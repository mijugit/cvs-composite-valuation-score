<?php

declare(strict_types=1);

namespace CVS\Screener;

use CVS\Core\Database;
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

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
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
        string  $sort     = 'swing'
    ): array {
        $rows = $this->findAllLatest();

        // Filter: only quality_gate passed.
        $rows = array_filter($rows, fn($r) => (int) $r['quality_gate'] === 1);

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

        // Sort.
        $rows = array_values($rows);
        usort($rows, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'fund'  => (float) ($b['cvs_fund']  ?? 0) <=> (float) ($a['cvs_fund']  ?? 0),
                'date'  => ($b['score_date'] ?? '') <=> ($a['score_date'] ?? ''),
                default => (float) ($b['cvs_swing'] ?? 0) <=> (float) ($a['cvs_swing'] ?? 0),
            };
        });

        return $rows;
    }

    /**
     * Timestamp of the most recent snapshot (for freshness notice).
     */
    public function getLastScoredAt(): ?string
    {
        $stmt = $this->db->query('SELECT MAX(scored_at) FROM cvs_snapshots');
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
        $stmt = $this->db->query(
            'SELECT DISTINCT sector FROM cvs_snapshots WHERE sector IS NOT NULL ORDER BY sector ASC'
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
        $stmt = $this->db->prepare('
            SELECT s.*
            FROM cvs_snapshots s
            INNER JOIN (
                SELECT ticker, MAX(score_date) AS max_date
                FROM cvs_snapshots
                GROUP BY ticker
            ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
            ORDER BY s.ticker ASC
        ');
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
