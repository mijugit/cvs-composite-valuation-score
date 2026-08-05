<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use PDO;

/**
 * Atomic write model for the virtual portfolio.
 *
 * All portfolio mutations (BUY, SELL, HOLD, SKIP, NO_ACTION) run inside a single
 * PDO transaction in executeCycle(). A Throwable at any point triggers a full
 * ROLLBACK — no partial writes are possible.
 *
 * The decision array consumed by executeCycle() is produced by F-03 (DecisionService).
 * Each element must have: ticker (string), action (string), quantity (?int), price_usd (?float), reason (?string).
 */
class PortfolioService
{
    public function __construct(
        private readonly PDO             $db,
        private readonly CycleRepository $cycleRepo,
    ) {}

    /**
     * Guards against an uninitialized portfolio (missing seed row).
     *
     * @throws \RuntimeException when portfolio_state does not contain exactly one row
     */
    public function ensureInitialized(): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM portfolio_state');
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();

        if ($count !== 1) {
            throw new \RuntimeException(
                'Portfolio not initialized: expected 1 row in portfolio_state, found ' . $count . '.'
            );
        }
    }

    /**
     * Executes a full rebalance cycle atomically.
     *
     * All writes (portfolio_state, portfolio_holdings, portfolio_transactions,
     * rebalance_cycle summary) happen in one PDO transaction. Any Throwable
     * triggers a full rollback; the exception is re-thrown to the caller.
     *
     * @param array<int, array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}> $decisions
     * @param array<string, float> $priceMap ticker (uppercase) => today's USD snapshot price
     *        (bin/portfolio-rebalance.php builds this from the same cvs_snapshots rows the LLM
     *        reasoned over, before this call). Marks the cycle-end valuation to market instead
     *        of cost basis — see computeHoldingsValue()'s docblock for why this matters.
     */
    public function executeCycle(int $cycleId, array $decisions, array $priceMap): void
    {
        $this->db->beginTransaction();

        try {
            // Read cash inside the transaction (correct isolation pattern).
            $stmt = $this->db->prepare('SELECT cash FROM portfolio_state LIMIT 1');
            $stmt->execute();
            $cashBefore   = (float) $stmt->fetchColumn();
            $cashRunning  = $cashBefore;
            $executedCount = 0;
            $skippedCount  = 0;

            foreach ($decisions as $decision) {
                $ticker   = (string) $decision['ticker'];
                $action   = strtoupper((string) $decision['action']);
                $quantity = isset($decision['quantity']) ? (int) $decision['quantity'] : null;
                $priceUsd = isset($decision['price_usd']) ? (float) $decision['price_usd'] : null;
                $reason   = $decision['reason'] ?? null;

                match ($action) {
                    'BUY' => $this->handleBuy(
                        $cycleId, $ticker, $quantity ?? 0, $priceUsd ?? 0.0,
                        $reason, $cashRunning, $executedCount, $skippedCount
                    ),
                    'SELL'      => $this->executeSellInternal($cycleId, $ticker, $quantity ?? 0, $priceUsd ?? 0.0, $reason, $cashRunning, $executedCount),
                    'HOLD'      => $this->recordHoldInternal($cycleId, $ticker, $reason),
                    'NO_ACTION' => $this->recordNoActionInternal($cycleId, $reason),
                    default     => null, // unknown action — silently skip
                };
            }

            // Compute portfolio value snapshot (cash + holdings marked to today's price).
            $portfolioValueUsd = $cashRunning + $this->computeHoldingsValue($priceMap);

            $this->cycleRepo->updateCycleSummary(
                $cycleId,
                $cashBefore,
                $cashRunning,
                $portfolioValueUsd,
                $executedCount,
                $skippedCount,
                null,
            );

            $this->cycleRepo->updateStatus($cycleId, 'completed');

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers — all called only from within an open transaction
    // -----------------------------------------------------------------------

    private function handleBuy(
        int     $cycleId,
        string  $ticker,
        int     $quantity,
        float   $priceUsd,
        ?string $reason,
        float   &$cashRunning,
        int     &$executedCount,
        int     &$skippedCount,
    ): void {
        $cost = round($quantity * $priceUsd, 2);

        if ($cashRunning < $cost) {
            $this->recordSkipInternal($cycleId, $ticker, $reason, $skippedCount, $cashRunning);
            return;
        }

        $this->executeBuyInternal($cycleId, $ticker, $quantity, $priceUsd, $reason, $cashRunning, $executedCount);
    }

    private function executeBuyInternal(
        int     $cycleId,
        string  $ticker,
        int     $quantity,
        float   $priceUsd,
        ?string $reason,
        float   &$cashRunning,
        int     &$executedCount,
    ): void {
        $cost       = round($quantity * $priceUsd, 2);
        $cashBefore = $cashRunning;
        $cashAfter  = round($cashRunning - $cost, 2);

        // Reduce cash.
        $this->db->prepare('UPDATE portfolio_state SET cash = ?, updated_at = CURRENT_TIMESTAMP')
            ->execute([round($cashAfter, 2)]);

        // Upsert holding with weighted avg_entry_price.
        $existing = $this->fetchHolding($ticker);

        if ($existing !== null) {
            $oldQty   = (int) $existing['quantity'];
            $oldAvg   = (float) $existing['avg_entry_price'];
            $newAvg   = round(($oldQty * $oldAvg + $quantity * $priceUsd) / ($oldQty + $quantity), 4);
            $newQty   = $oldQty + $quantity;

            $this->db->prepare(
                'UPDATE portfolio_holdings SET quantity = ?, avg_entry_price = ?, updated_at = CURRENT_TIMESTAMP WHERE ticker = ?'
            )->execute([$newQty, $newAvg, $ticker]);
        } else {
            $this->db->prepare(
                'INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
            )->execute([$ticker, $quantity, round($priceUsd, 4)]);
        }

        $this->insertTransaction($cycleId, $ticker, 'BUY', $quantity, $priceUsd, 'executed', $reason, $cashBefore, $cashAfter);

        $cashRunning = $cashAfter;
        $executedCount++;
    }

    private function executeSellInternal(
        int     $cycleId,
        string  $ticker,
        int     $quantity,
        float   $priceUsd,
        ?string $reason,
        float   &$cashRunning,
        int     &$executedCount,
    ): void {
        $proceeds   = round($quantity * $priceUsd, 2);
        $cashBefore = $cashRunning;
        $cashAfter  = round($cashRunning + $proceeds, 2);

        // Increase cash.
        $this->db->prepare('UPDATE portfolio_state SET cash = ?, updated_at = CURRENT_TIMESTAMP')
            ->execute([round($cashAfter, 2)]);

        // Reduce or remove holding.
        $existing = $this->fetchHolding($ticker);
        if ($existing !== null) {
            $remaining = (int) $existing['quantity'] - $quantity;
            if ($remaining <= 0) {
                $this->db->prepare('DELETE FROM portfolio_holdings WHERE ticker = ?')
                    ->execute([$ticker]);
            } else {
                $this->db->prepare(
                    'UPDATE portfolio_holdings SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE ticker = ?'
                )->execute([$remaining, $ticker]);
            }
        }

        $this->insertTransaction($cycleId, $ticker, 'SELL', $quantity, $priceUsd, 'executed', $reason, $cashBefore, $cashAfter);

        $cashRunning = $cashAfter;
        $executedCount++;
    }

    private function recordSkipInternal(
        int     $cycleId,
        string  $ticker,
        ?string $reason,
        int     &$skippedCount,
        float   $cashRunning,
    ): void {
        $this->insertTransaction($cycleId, $ticker, 'SKIP_INSUFFICIENT_CASH', null, null, 'skipped_insufficient_cash', $reason, $cashRunning, $cashRunning);
        $skippedCount++;
    }

    private function recordHoldInternal(int $cycleId, string $ticker, ?string $reason): void
    {
        $this->insertTransaction($cycleId, $ticker, 'HOLD', null, null, 'hold', $reason, null, null);
    }

    private function recordNoActionInternal(int $cycleId, ?string $reason): void
    {
        $this->insertTransaction($cycleId, '*', 'NO_ACTION', null, null, 'no_action', $reason, null, null);
    }

    /** @return array<string, mixed>|null */
    private function fetchHolding(string $ticker): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_holdings WHERE ticker = ? LIMIT 1');
        $stmt->execute([$ticker]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function insertTransaction(
        int     $cycleId,
        string  $ticker,
        string  $action,
        ?int    $quantity,
        ?float  $priceUsd,
        string  $status,
        ?string $reason,
        ?float  $cashBefore,
        ?float  $cashAfter,
    ): void {
        $this->db->prepare(
            'INSERT INTO portfolio_transactions
             (cycle_id, ticker, action, quantity, price_usd, cash_before, cash_after, status, reason, executed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        )->execute([
            $cycleId,
            $ticker,
            $action,
            $quantity,
            $priceUsd !== null ? round($priceUsd, 4) : null,
            $cashBefore !== null ? round($cashBefore, 2) : null,
            $cashAfter !== null ? round($cashAfter, 2) : null,
            $status,
            $reason,
        ]);
    }

    /**
     * Values current holdings at today's snapshot price (mark-to-market), not
     * at cost basis (avg_entry_price). This is the fix for a bug present since
     * the module's original implementation (2026-06-26): the plan explicitly
     * scoped live pricing OUT of this cycle-summary snapshot ("cycle-snapshot
     * value uses transaction prices only ... live value computed by S-01 read
     * logic" — context/changes/virtual-portfolio-ledger/plan.md), on the
     * assumption nothing downstream would treat it as a real value series. A
     * week later the Lab feature's /lab NAV chart started reading this exact
     * column read-only as the LLM portfolio's comparison line — at which
     * point "cost basis, not live" stopped being a harmless simplification:
     * cost basis barely moves except when a trade executes (no market-price
     * sensitivity at all), so the chart's LLM line tracked fee drag, not
     * performance. $priceMap (built by the caller from that day's
     * cvs_snapshots) closes that gap; a ticker missing from it (e.g. dropped
     * from the watchlist) falls back to avg_entry_price rather than being
     * dropped from the sum.
     *
     * @param array<string, float> $priceMap ticker (uppercase) => today's USD snapshot price
     */
    private function computeHoldingsValue(array $priceMap): float
    {
        $stmt = $this->db->prepare(
            'SELECT ticker, quantity, avg_entry_price FROM portfolio_holdings WHERE quantity > 0'
        );
        $stmt->execute();

        $total = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $ticker = strtoupper((string) $row['ticker']);
            $price  = $priceMap[$ticker] ?? (float) $row['avg_entry_price'];
            $total += (float) $row['quantity'] * $price;
        }

        return round($total, 2);
    }
}
