<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use PDO;

/**
 * Read-only access to all virtual portfolio tables.
 *
 * Pure SELECT methods — no side effects, no transactions.
 * Called by S-01 views, S-03 history, S-05 stats.
 */
class PortfolioRepository
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Returns the single portfolio_state row.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when portfolio has not been initialized (no row in portfolio_state)
     */
    public function getCurrentState(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_state LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Portfolio not initialized: portfolio_state is empty.');
        }

        return $row;
    }

    /**
     * Returns holdings enriched with the latest snapshot price from cvs_snapshots.
     *
     * Uses LEFT JOIN so holdings without a matching snapshot still appear,
     * falling back to avg_entry_price (price_is_snapshot = false).
     *
     * IMPORTANT: filters by model_version and origin='RESCORE' to avoid shadow rows
     * (lesson: commit 442689d — unfiltered JOIN returns duplicate rows when shadow
     * scoring writes multiple rows per ticker/date).
     *
     * @return array<int, array{ticker: string, quantity: int, avg_entry_price: float, live_price: float, price_is_snapshot: bool, value_usd: float, updated_at: string}>
     */
    public function getCurrentHoldingsWithPrice(string $liveModelVersion): array
    {
        $sql = "
            SELECT
                h.ticker,
                h.quantity,
                h.avg_entry_price,
                h.updated_at,
                COALESCE(s.price_at_snapshot, h.avg_entry_price) AS live_price,
                (s.price_at_snapshot IS NOT NULL)                 AS price_is_snapshot
            FROM portfolio_holdings h
            LEFT JOIN cvs_snapshots s
                ON  s.ticker        = h.ticker
                AND s.model_version = ?
                AND s.origin        = 'RESCORE'
                AND s.scored_at     = (
                    SELECT MAX(s2.scored_at)
                    FROM cvs_snapshots s2
                    WHERE s2.ticker        = h.ticker
                      AND s2.model_version = ?
                      AND s2.origin        = 'RESCORE'
                )
            WHERE h.quantity > 0
            ORDER BY h.ticker ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$liveModelVersion, $liveModelVersion]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $livePrice = (float) $row['live_price'];
            $quantity  = (int)   $row['quantity'];
            return [
                'ticker'           => (string) $row['ticker'],
                'quantity'         => $quantity,
                'avg_entry_price'  => (float) $row['avg_entry_price'],
                'live_price'       => $livePrice,
                'price_is_snapshot'=> (bool) $row['price_is_snapshot'],
                'value_usd'        => round($quantity * $livePrice, 2),
                'updated_at'       => (string) $row['updated_at'],
            ];
        }, $rows);
    }

    /**
     * Returns all current holdings with quantity > 0, ordered by ticker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCurrentHoldings(): array
    {
        $stmt = $this->db->prepare(
            'SELECT ticker, quantity, avg_entry_price, updated_at
             FROM portfolio_holdings
             WHERE quantity > 0
             ORDER BY ticker ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the most recent transaction reason per ticker — the justification
     * from the last rebalance in which each ticker was touched (BUY/HOLD/SELL).
     * Used by the portfolio view's per-position info popover.
     *
     * @return array<string, string> ticker → reason
     */
    public function getLatestReasonsByTicker(): array
    {
        $sql = '
            SELECT pt.ticker, pt.reason
            FROM portfolio_transactions pt
            INNER JOIN (
                SELECT ticker, MAX(id) AS max_id
                FROM portfolio_transactions
                WHERE reason IS NOT NULL AND reason <> ""
                GROUP BY ticker
            ) latest ON latest.ticker = pt.ticker AND latest.max_id = pt.id
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['ticker']] = (string) $row['reason'];
        }

        return $out;
    }

    /**
     * Returns all transactions for a given cycle, ordered by insertion order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionsByCycle(int $cycleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM portfolio_transactions
             WHERE cycle_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$cycleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the full rebalance cycle history, newest first.
     * No LIMIT — PRD FR-011 requires full history retention.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCycleHistory(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rebalance_cycle ORDER BY cycle_date DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the most recent rebalance cycle row, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestCycle(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rebalance_cycle ORDER BY cycle_date DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Returns a rebalance cycle row by primary key, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getCycleById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rebalance_cycle WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
