<?php

declare(strict_types=1);

namespace CVS\Screener;

use CVS\Core\Database;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;
use DateTimeImmutable;
use PDO;

/**
 * Screener queries — fetches latest snapshots and applies PHP-side filters.
 *
 * PHP filtering is acceptable at ~50 tickers; SQL-side filtering would be
 * premature optimisation for this scale.
 */
class ScreenerRepository
{
    private PDO $db;

    /**
     * Live model_version (config/cvs-weights.php → model_version).
     *
     * Hotfix (2026-06-08): cvs-overlay-penalties shadow persistence (p4, SHA
     * 9530a10) writes a second row per (ticker, score_date) under model_version
     * 3.1 alongside the live 3.0 row. Without filtering, findAllLatest() returns
     * BOTH rows for the same day (both satisfy MAX(score_date)), doubling the
     * screener listing. Restrict to the live version; shadow rows are an internal
     * preview, never user-facing. Nullable for backward-compat with callers/tests
     * that don't pass it (falls back to the pre-fix unfiltered behaviour).
     */
    private ?string $liveModelVersion;

    /** @var array{window_days: int, min_points: int, boundary_margin: float} */
    private array $trajectoryConfig;

    /** @var array<string, float> */
    private array $thresholds;

    /**
     * @param array{window_days?: int, min_points?: int, boundary_margin?: float} $trajectoryConfig
     * @param array<string, float> $thresholds config/cvs-weights.php → thresholds
     */
    public function __construct(
        ?PDO $db = null,
        ?string $liveModelVersion = null,
        array $trajectoryConfig = [],
        array $thresholds = []
    ) {
        $this->db               = $db ?? Database::connection();
        $this->liveModelVersion = $liveModelVersion;
        $this->trajectoryConfig = [
            'window_days'     => (int) ($trajectoryConfig['window_days'] ?? 90),
            'min_points'      => (int) ($trajectoryConfig['min_points'] ?? 2),
            'boundary_margin' => (float) ($trajectoryConfig['boundary_margin'] ?? 5),
        ];
        $this->thresholds = $thresholds;
    }

    // ------------------------------------------------------------------
    // Core
    // ------------------------------------------------------------------

    /**
     * Latest snapshots filtered and sorted.
     *
     * @param string|null $reco     Exact reco_swing match (null = all)
     * @param string|null $signal   golden_signal value; 'none' = only null signals
     * @param int         $minSwing Minimum cvs_swing (0 = no filter)
     * @param string|null $sector   Exact sector match (null = all)
     * @param string      $sort     'swing'|'fund'|'date'
     * @return array<int, array<string, mixed>>
     */
    public function getFiltered(
        ?string $reco     = null,
        ?string $signal   = null,
        int     $minSwing = 0,
        ?string $sector   = null,
        string  $sort     = 'swing',
        ?string $atr      = null
    ): array {
        $rows = $this->findAllLatest();

        // Filter: only quality_gate passed.
        $rows = array_filter($rows, fn($r) => (int) $r['quality_gate'] === 1);

        // Enrich each row with ATR zone state (needed both for the ATR filter below
        // and the ATR column in the view). price_at_snapshot and the zone are both USD
        // and written by the same rescore run, so the comparison is consistent.
        $zones = $this->findZoneMap();

        // change: cvs-screener-trend — same "one bulk query, enrich before filtering"
        // pattern as the ATR zone map above, instead of N+1 per-ticker trajectory calls.
        $sinceDate      = (new DateTimeImmutable())->modify('-' . $this->trajectoryConfig['window_days'] . ' days')->format('Y-m-d');
        $trajectories   = $this->findTrajectoryMap($sinceDate);
        $minPoints      = $this->trajectoryConfig['min_points'];
        $boundaryMargin = $this->trajectoryConfig['boundary_margin'];

        foreach ($rows as &$row) {
            $ticker = strtoupper((string) ($row['ticker'] ?? ''));
            $price  = $row['price_at_snapshot'] ?? null;
            $row['atr_state'] = ($price !== null && isset($zones[$ticker]))
                ? $this->classifyAtrState((float) $price, $zones[$ticker]['low'], $zones[$ticker]['high'])
                : null;

            $summary = TrajectoryCalculator::summarise($trajectories[$ticker] ?? [], $minPoints);
            $row['trend_delta_weekly'] = $summary['delta_weekly'];

            $row['trend_near_boundary'] = $row['cvs_swing'] !== null
                ? $this->isNearBoundary((float) $row['cvs_swing'], $this->thresholds, $boundaryMargin)
                : false;
        }
        unset($row);

        // Filter: rekomendacja.
        if ($reco !== null && $reco !== '') {
            $rows = array_filter($rows, fn($r) => ($r['reco_swing'] ?? '') === $reco);
        }

        // Filter: golden signal.
        if ($signal !== null && $signal !== '') {
            if ($signal === 'none') {
                $rows = array_filter($rows, fn($r) => ($r['golden_signal'] ?? null) === null);
            } else {
                $rows = array_filter($rows, fn($r) => ($r['golden_signal'] ?? null) === $signal);
            }
        }

        // Filter: min CVS Swing.
        if ($minSwing > 0) {
            $rows = array_filter(
                $rows,
                fn($r) => $r['cvs_swing'] !== null && (float) $r['cvs_swing'] >= $minSwing
            );
        }

        // Filter: sector.
        if ($sector !== null && $sector !== '') {
            $rows = array_filter($rows, fn($r) => ($r['sector'] ?? null) === $sector);
        }

        // Filter: ATR zone state (in_zone / above / below).
        if ($atr !== null && $atr !== '') {
            $rows = array_filter($rows, fn($r) => ($r['atr_state'] ?? null) === $atr);
        }

        // Sort. ATR ranks by actionability for entries: in zone, then below, then above.
        $atrRank = static fn (?string $s): int => ['in_zone' => 0, 'below' => 1, 'above' => 2][$s] ?? 3;
        $rows = array_values($rows);
        usort($rows, function (array $a, array $b) use ($sort, $atrRank): int {
            return match ($sort) {
                'fund'   => (float) ($b['cvs_fund']  ?? 0) <=> (float) ($a['cvs_fund']  ?? 0),
                'date'   => ($b['score_date'] ?? '') <=> ($a['score_date'] ?? ''),
                'ticker' => strcmp((string) ($a['ticker'] ?? ''), (string) ($b['ticker'] ?? '')),
                'price'  => (float) ($b['price_at_snapshot'] ?? 0) <=> (float) ($a['price_at_snapshot'] ?? 0),
                'atr'    => $atrRank($a['atr_state'] ?? null) <=> $atrRank($b['atr_state'] ?? null),
                default  => (float) ($b['cvs_swing'] ?? 0) <=> (float) ($a['cvs_swing'] ?? 0),
            };
        });

        return $rows;
    }

    /**
     * Map ticker → {low, high} from the ATR zone cache. Returns an empty map if the
     * table is absent (pre-migration / test schema) so the screener degrades gracefully.
     *
     * @return array<string, array{low: float, high: float}>
     */
    private function findZoneMap(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT ticker, zone_low, zone_high FROM ticker_zone WHERE zone_low IS NOT NULL AND zone_high IS NOT NULL'
            );
        } catch (\PDOException) {
            return [];
        }
        if ($stmt === false) {
            return [];
        }
        $map = [];
        foreach ($stmt->fetchAll() as $z) {
            $map[strtoupper((string) $z['ticker'])] = [
                'low'  => (float) $z['zone_low'],
                'high' => (float) $z['zone_high'],
            ];
        }
        return $map;
    }

    /** Classify a price against the accumulation zone: in_zone / above / below. */
    private function classifyAtrState(float $price, float $low, float $high): string
    {
        if ($price >= $low && $price <= $high) {
            return 'in_zone';
        }
        return $price > $high ? 'above' : 'below';
    }

    /**
     * change: cvs-screener-trend — CVS Swing history for every ticker in one
     * query (mirrors findZoneMap() above), instead of N+1 per-ticker calls to
     * CvsSnapshotRepository::findTrajectory(). Same shadow-row guard as
     * findAllLatest(): filtered to the live model_version when known, since an
     * unfiltered MAX/GROUP over cvs_snapshots would mix in 3.1/3.2 shadow rows
     * (lessons.md: "Filtruj shadow model_version przy każdym odczycie latest
     * snapshot").
     *
     * @return array<string, list<array{score_date: string, cvs_swing: float}>>
     */
    private function findTrajectoryMap(string $sinceDate): array
    {
        $o = CvsSnapshotRepository::ORIGIN_RESCORE;

        if ($this->liveModelVersion !== null) {
            $stmt = $this->db->prepare(
                "SELECT ticker, score_date, cvs_swing FROM cvs_snapshots
                 WHERE origin = '{$o}' AND model_version = ? AND score_date >= ?
                   AND cvs_swing IS NOT NULL
                 ORDER BY ticker ASC, score_date ASC"
            );
            $stmt->execute([$this->liveModelVersion, $sinceDate]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT ticker, score_date, cvs_swing FROM cvs_snapshots
                 WHERE origin = '{$o}' AND score_date >= ? AND cvs_swing IS NOT NULL
                 ORDER BY ticker ASC, score_date ASC"
            );
            $stmt->execute([$sinceDate]);
        }

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $ticker = strtoupper((string) $r['ticker']);
            $map[$ticker][] = ['score_date' => (string) $r['score_date'], 'cvs_swing' => (float) $r['cvs_swing']];
        }
        return $map;
    }

    /**
     * True when $score sits within $margin points of ANY recommendation
     * threshold (strong_buy/accumulate/neutral/reduce) — the screener's
     * "near boundary" flag (change: cvs-screener-trend).
     *
     * @param array<string, float> $thresholds
     */
    private function isNearBoundary(float $score, array $thresholds, float $margin): bool
    {
        foreach ($thresholds as $threshold) {
            if (abs($score - (float) $threshold) <= $margin) {
                return true;
            }
        }
        return false;
    }

    /**
     * Timestamp of the most recent snapshot (for freshness notice).
     */
    public function getLastScoredAt(): ?string
    {
        // Phase 7 (slice 1, FR-003): freshness reflects user-facing rescore runs
        // only — corpus crawl timestamps must not masquerade as screener freshness.
        $o    = CvsSnapshotRepository::ORIGIN_RESCORE;
        $stmt = $this->db->query("SELECT MAX(scored_at) FROM cvs_snapshots WHERE origin = '{$o}'");
        if ($stmt === false) {
            return null;
        }
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    /**
     * Distinct non-null sectors (for sector filter dropdown).
     *
     * @return string[]
     */
    public function getDistinctSectors(): array
    {
        // Phase 7 (slice 1, FR-003): the dropdown lists only sectors present in
        // the user-facing population — corpus covers ~11 sectors of the full
        // universe and would flood the filter with sectors no watchlist row has.
        $o    = CvsSnapshotRepository::ORIGIN_RESCORE;
        $stmt = $this->db->query(
            "SELECT DISTINCT sector FROM cvs_snapshots WHERE sector IS NOT NULL AND origin = '{$o}' ORDER BY sector ASC"
        );
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Latest snapshot per ticker (reuses CvsSnapshotRepository self-join pattern).
     *
     * @return array<int, array<string, mixed>>
     */
    private function findAllLatest(): array
    {
        // Phase 7 (slice 1, FR-003): NOTE — this is the screener's OWN self-join,
        // not a delegation to CvsSnapshotRepository::findAllLatest(); the origin
        // filter must live here too. Corpus rows share live model_version values,
        // so the version filter alone would NOT exclude them (same leak class as
        // the 2026-06-08 shadow-row duplication hotfix).
        $o = CvsSnapshotRepository::ORIGIN_RESCORE;

        if ($this->liveModelVersion !== null) {
            $stmt = $this->db->prepare("
                SELECT s.*
                FROM cvs_snapshots s
                INNER JOIN (
                    SELECT ticker, MAX(score_date) AS max_date
                    FROM cvs_snapshots
                    WHERE model_version = :live_version
                      AND origin = '{$o}'
                    GROUP BY ticker
                ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
                WHERE s.model_version = :live_version_join
                  AND s.origin = '{$o}'
                ORDER BY s.ticker ASC
            ");
            $stmt->execute([
                ':live_version'      => $this->liveModelVersion,
                ':live_version_join' => $this->liveModelVersion,
            ]);
            return $stmt->fetchAll() ?: [];
        }

        $stmt = $this->db->prepare("
            SELECT s.*
            FROM cvs_snapshots s
            INNER JOIN (
                SELECT ticker, MAX(score_date) AS max_date
                FROM cvs_snapshots
                WHERE origin = '{$o}'
                GROUP BY ticker
            ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
            WHERE s.origin = '{$o}'
            ORDER BY s.ticker ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
