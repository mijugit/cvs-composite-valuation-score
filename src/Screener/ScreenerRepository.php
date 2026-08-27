<?php

declare(strict_types=1);

namespace CVS\Screener;

use CVS\Core\Database;
use CVS\Logo\TickerLogoRepository;
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
    /**
     * Natural default direction per sort column, used when no explicit `dir`
     * is given. Score-like columns default to "best first" (desc); ticker
     * defaults to A→Z; atr defaults to most-actionable-first (its own rank,
     * see $atrRank in getFiltered()) — both are "asc" in the sense of their
     * own natural ordering, not literal numeric ascent.
     */
    private const SORT_DEFAULT_DIR = [
        'ticker' => 'asc',
        'atr'    => 'asc',
    ];

    public static function defaultDirFor(string $sort): string
    {
        return self::SORT_DEFAULT_DIR[$sort] ?? 'desc';
    }

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

    /** @var array{default_label?: string, labels?: array<string, string>} */
    private array $marketsConfig;

    /**
     * @param array{window_days?: int, min_points?: int, boundary_margin?: float} $trajectoryConfig
     * @param array<string, float> $thresholds config/cvs-weights.php → thresholds
     * @param array{default_label?: string, labels?: array<string, string>} $marketsConfig config/cvs-weights.php → markets
     */
    public function __construct(
        ?PDO $db = null,
        ?string $liveModelVersion = null,
        array $trajectoryConfig = [],
        array $thresholds = [],
        array $marketsConfig = []
    ) {
        $this->db               = $db ?? Database::connection();
        $this->liveModelVersion = $liveModelVersion;
        $this->trajectoryConfig = [
            'window_days'     => (int) ($trajectoryConfig['window_days'] ?? 90),
            'min_points'      => (int) ($trajectoryConfig['min_points'] ?? 2),
            'boundary_margin' => (float) ($trajectoryConfig['boundary_margin'] ?? 5),
        ];
        $this->thresholds    = $thresholds;
        $this->marketsConfig = $marketsConfig;
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
     * @param string      $sort     'swing'|'fund'|'date'|'ticker'|'price'|'atr'|'fv'
     * @param bool        $nearBoundary Only rows within trajectory.boundary_margin of a recommendation threshold
     * @param bool        $fvOnly   Only rows where fair_value_price > price_at_snapshot (both non-null)
     * @param string|null $market   Ticker suffix (e.g. '.WA'), or the 'US' sentinel for no-suffix tickers (null = all)
     * @param string|null $dir      'asc'|'desc', or null to use $sort's natural default (see defaultDirFor())
     * @return array<int, array<string, mixed>>
     */
    public function getFiltered(
        ?string $reco        = null,
        ?string $signal      = null,
        int     $minSwing    = 0,
        ?string $sector      = null,
        string  $sort        = 'swing',
        ?string $atr         = null,
        bool    $nearBoundary = false,
        bool    $fvOnly      = false,
        ?string $market      = null,
        ?string $dir         = null
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

        // change: cvs-screener-ticker-links — same bulk-query-then-enrich pattern
        // as zones/trajectories above; the right-click menu needs every listed
        // ticker's links up front, not fetched lazily per row.
        $tickers      = array_map(fn($r) => strtoupper((string) ($r['ticker'] ?? '')), $rows);
        $tickerLinks  = $this->findTickerLinksMap($tickers);

        // change: ticker-logo-cache — same bulk-query-then-enrich pattern as
        // $tickerLinks above; read-only here, writes only ever come from
        // bin/fetch_logos.php.
        $tickerLogos = (new TickerLogoRepository($this->db))->findByTickers($tickers);

        foreach ($rows as &$row) {
            $ticker = strtoupper((string) ($row['ticker'] ?? ''));
            $price  = $row['price_at_snapshot'] ?? null;
            $row['market_suffix'] = MarketResolver::suffixForTicker($ticker);
            $row['ticker_links']  = $tickerLinks[$ticker] ?? [];
            $row['ticker_logo']   = $tickerLogos[$ticker] ?? null;
            $row['atr_state'] = ($price !== null && isset($zones[$ticker]))
                ? $this->classifyAtrState((float) $price, $zones[$ticker]['low'], $zones[$ticker]['high'])
                : null;

            $summary = TrajectoryCalculator::summarise($trajectories[$ticker] ?? [], $minPoints);
            $row['trend_delta_weekly'] = $summary['delta_weekly'];
            $row['trend_delta_daily']  = $summary['delta_daily'];

            $row['trend_near_boundary'] = $row['cvs_swing'] !== null
                ? $this->isNearBoundary((float) $row['cvs_swing'], $this->thresholds, $boundaryMargin)
                : false;

            // FV column (migration 031): margin of CVS Fair Value over the snapshot
            // price, as a percentage. Null when either side is missing (pre-migration
            // row, or FairPriceCalculator returned null for this ticker/day).
            $fv = $row['fair_value_price'] ?? null;
            $row['fv_margin_pct'] = ($fv !== null && $price !== null && (float) $price > 0)
                ? (((float) $fv / (float) $price) - 1) * 100
                : null;
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

        // Filter: market (ticker suffix). 'US' is the sentinel for "no suffix" —
        // an actual suffix always starts with '.', so it can never collide.
        if ($market !== null && $market !== '') {
            $wantedSuffix = $market === 'US' ? null : $market;
            $rows = array_filter($rows, fn($r) => ($r['market_suffix'] ?? null) === $wantedSuffix);
        }

        // Filter: ATR zone state (in_zone / above / below).
        if ($atr !== null && $atr !== '') {
            $rows = array_filter($rows, fn($r) => ($r['atr_state'] ?? null) === $atr);
        }

        // Filter: near a recommendation threshold (change: cvs-screener-trend,
        // Phase 2). trend_near_boundary is already computed above in the
        // enrichment loop — this is a plain array_filter, no new SQL.
        if ($nearBoundary) {
            $rows = array_filter($rows, fn($r) => ($r['trend_near_boundary'] ?? false) === true);
        }

        // Filter: Fair Value above price — the "trap check" from the CVS manual
        // (identical headline signal, but only one side clears this bar).
        if ($fvOnly) {
            $rows = array_filter($rows, fn($r) => ($r['fv_margin_pct'] ?? null) !== null && $r['fv_margin_pct'] > 0);
        }

        // Sort. ATR ranks by actionability for entries: in zone, then below, then above.
        // Every branch below compares $a <=> $b in the column's own natural ascending
        // order; $sign flips the whole result for 'desc' (or a column's default — see
        // defaultDirFor()) so there is exactly one place that knows about direction,
        // instead of baking "desc" into half the branches via a swapped $b <=> $a.
        $atrRank     = static fn (?string $s): int => ['in_zone' => 0, 'below' => 1, 'above' => 2][$s] ?? 3;
        $resolvedDir = in_array($dir, ['asc', 'desc'], true) ? $dir : self::defaultDirFor($sort);
        $sign        = $resolvedDir === 'asc' ? 1 : -1;
        $rows = array_values($rows);
        usort($rows, function (array $a, array $b) use ($sort, $atrRank, $sign): int {
            return $sign * match ($sort) {
                'fund'   => (float) ($a['cvs_fund']  ?? 0) <=> (float) ($b['cvs_fund']  ?? 0),
                'date'   => ($a['score_date'] ?? '') <=> ($b['score_date'] ?? ''),
                'ticker' => strcmp((string) ($a['ticker'] ?? ''), (string) ($b['ticker'] ?? '')),
                'price'  => (float) ($a['price_at_snapshot'] ?? 0) <=> (float) ($b['price_at_snapshot'] ?? 0),
                'atr'    => $atrRank($a['atr_state'] ?? null) <=> $atrRank($b['atr_state'] ?? null),
                'fv'     => ($a['fv_margin_pct'] ?? -PHP_FLOAT_MAX) <=> ($b['fv_margin_pct'] ?? -PHP_FLOAT_MAX),
                default  => (float) ($a['cvs_swing'] ?? 0) <=> (float) ($b['cvs_swing'] ?? 0),
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

    /**
     * Bulk-loads favourite links (change: cvs-screener-ticker-links) for
     * every ticker in $tickers, keyed by ticker — same "one query, no N+1"
     * shape as findZoneMap()/findTrajectoryMap() above. Own direct SQL against
     * ticker_links rather than delegating to TickerLinkRepository (that
     * repository is CRUD for TickerLinkController's writes; this is a
     * read-only enrichment map, same split as the screener's own
     * findAllLatest() self-join not delegating to CvsSnapshotRepository).
     * Returns an empty map (no error) if the table doesn't exist yet
     * (pre-migration schema, mirrors findZoneMap()'s degrade-gracefully guard).
     *
     * created_by travels all the way to the template/JS so the right-click
     * menu can show the "✕ remove" control only on links the viewer owns
     * (or, for an admin, on every link) — see TickerLinkController::canDelete().
     *
     * @param list<string> $tickers
     * @return array<string, list<array{id: int, label: string, url: string, created_by: int|null}>>
     */
    private function findTickerLinksMap(array $tickers): array
    {
        $tickers = array_values(array_unique(array_filter($tickers, fn($t) => $t !== '')));
        if ($tickers === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tickers), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT ticker, id, label, url, created_by FROM ticker_links
                 WHERE ticker IN ({$placeholders})
                 ORDER BY ticker ASC, created_at ASC, id ASC"
            );
            $stmt->execute($tickers);
        } catch (\PDOException) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $ticker = strtoupper((string) $r['ticker']);
            $map[$ticker][] = [
                'id'         => (int) $r['id'],
                'label'      => (string) $r['label'],
                'url'        => (string) $r['url'],
                'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
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

    /**
     * Distinct markets (by ticker suffix) present in the live population, for
     * the "Rynek" filter dropdown. Same "compute live from data, don't persist
     * a redundant registry" pattern as getDistinctSectors() — a newly-added
     * ticker's market shows up here as soon as it's scored, no separate
     * registration step. 'US' is the value/sentinel for the no-suffix group.
     *
     * @return list<array{value: string, label: string}>
     */
    public function getDistinctMarkets(): array
    {
        $o    = CvsSnapshotRepository::ORIGIN_RESCORE;
        $stmt = $this->db->query("SELECT DISTINCT ticker FROM cvs_snapshots WHERE origin = '{$o}'");
        if ($stmt === false) {
            return [];
        }

        $suffixes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ticker) {
            $suffixes[MarketResolver::suffixForTicker((string) $ticker) ?? ''] = true;
        }

        $out = [];
        foreach (array_keys($suffixes) as $suffix) {
            $suffix = $suffix === '' ? null : $suffix;
            $out[] = [
                'value' => $suffix ?? 'US',
                'label' => MarketResolver::labelForSuffix($suffix, $this->marketsConfig),
            ];
        }

        // US first (if present), then alphabetically by label.
        usort($out, static fn(array $a, array $b): int =>
            ($a['value'] === 'US' ? 0 : 1) <=> ($b['value'] === 'US' ? 0 : 1)
                ?: strcmp($a['label'], $b['label']));

        return $out;
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
