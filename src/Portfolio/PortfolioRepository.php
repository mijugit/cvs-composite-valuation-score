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
