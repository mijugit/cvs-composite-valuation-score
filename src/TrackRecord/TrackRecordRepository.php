<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * Track record queries — evaluates historical CVS recommendations.
 *
 * Self-join on cvs_snapshots pairs:
 *   - old snapshot (≥ horizonDays ago, has price) = "then"
 *   - latest snapshot (≤ 7 days old, has price)   = "now"
 *
 * Dates are computed in PHP (not SQL) for MySQL/SQLite compatibility.
 * No live Yahoo Finance API calls needed.
 */
class TrackRecordRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Core queries
    // ------------------------------------------------------------------

    /**
     * All evaluated snapshot pairs across all tickers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEvaluations(int $horizonDays = 30): array
    {
        [$cutoffOld, $cutoffRecent] = $this->dateCutoffs($horizonDays);

        $stmt = $this->db->prepare('
            SELECT
                old.ticker,
                old.score_date,
                old.cvs_swing,
                old.cvs_fund,
                old.reco_swing,
                old.reco_fund,
                old.golden_signal,
                old.price_at_snapshot  AS price_then,
                latest.price_now       AS price_now,
                ROUND(
                    (latest.price_now - old.price_at_snapshot)
                    / old.price_at_snapshot * 100,
                    2
                ) AS price_change_pct
            FROM cvs_snapshots old
            INNER JOIN (
                SELECT ticker, MAX(price_at_snapshot) AS price_now
                FROM cvs_snapshots
                WHERE score_date >= ?
                  AND price_at_snapshot IS NOT NULL
                GROUP BY ticker
            ) latest ON latest.ticker = old.ticker
            WHERE old.score_date <= ?
              AND old.price_at_snapshot IS NOT NULL
              AND old.quality_gate = 1
            ORDER BY old.ticker ASC, old.score_date ASC
        ');
        $stmt->execute([$cutoffRecent, $cutoffOld]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Evaluated pairs for a single ticker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForTicker(string $ticker, int $horizonDays = 30): array
    {
        $ticker = strtoupper($ticker);
        [$cutoffOld, $cutoffRecent] = $this->dateCutoffs($horizonDays);

        $stmt = $this->db->prepare('
            SELECT
                old.ticker,
                old.score_date,
                old.cvs_swing,
                old.cvs_fund,
                old.reco_swing,
                old.reco_fund,
                old.golden_signal,
                old.price_at_snapshot  AS price_then,
                latest.price_now       AS price_now,
                ROUND(
                    (latest.price_now - old.price_at_snapshot)
                    / old.price_at_snapshot * 100,
                    2
                ) AS price_change_pct
            FROM cvs_snapshots old
            INNER JOIN (
                SELECT ticker, MAX(price_at_snapshot) AS price_now
                FROM cvs_snapshots
                WHERE score_date >= ?
                  AND price_at_snapshot IS NOT NULL
                  AND ticker = ?
                GROUP BY ticker
            ) latest ON latest.ticker = old.ticker
            WHERE old.ticker = ?
              AND old.score_date <= ?
              AND old.price_at_snapshot IS NOT NULL
              AND old.quality_gate = 1
            ORDER BY old.score_date ASC
        ');
        $stmt->execute([$cutoffRecent, $ticker, $ticker, $cutoffOld]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * All snapshots for a ticker (for history chart — includes pending/recent).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllForTicker(string $ticker): array
    {
        $stmt = $this->db->prepare('
            SELECT ticker, score_date, cvs_swing, cvs_fund,
                   reco_swing, reco_fund, golden_signal,
                   price_at_snapshot, quality_gate
            FROM cvs_snapshots
            WHERE ticker = ?
            ORDER BY score_date ASC
        ');
        $stmt->execute([strtoupper($ticker)]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Summary stats using enriched evaluations.
     *
     * @return array{total: int, hits: int, misses: int, neutral: int,
     *               pending: int, hit_rate_pct: float|null, avg_change_pct: float|null}
     */
    public function getSummaryStats(int $horizonDays = 30): array
    {
        $evaluations = $this->getEvaluations($horizonDays);
        return TrackRecordCalculator::summarise(
            TrackRecordCalculator::enrichWithResult($evaluations)
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{string, string} [cutoff_old (for old snapshots), cutoff_recent (for latest)]
     */
    private function dateCutoffs(int $horizonDays): array
    {
        $cutoffOld    = (new DateTimeImmutable("-{$horizonDays} days"))->format('Y-m-d');
        $cutoffRecent = (new DateTimeImmutable('-7 days'))->format('Y-m-d');
        return [$cutoffOld, $cutoffRecent];
    }
}
