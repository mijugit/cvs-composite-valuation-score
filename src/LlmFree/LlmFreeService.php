<?php

declare(strict_types=1);

namespace CVS\LlmFree;

use PDO;

/**
 * Atomic write model for the LLM_Free_Wallet.
 *
 * Mirrors CVS\Portfolio\PortfolioService::executeCycle()'s transaction-per-cycle
 * shape and its four decision-action handlers (BUY/SELL/HOLD/NO_ACTION), but with
 * no risk-cap enforcement step between "decisions received" and "decisions
 * applied" — the whole point of this module (PRD FR-004). Only the physical
 * sanity guards remain: a BUY that costs more than available cash is skipped
 * (you cannot spend money that doesn't exist), and a SELL is capped at the
 * quantity actually held (you cannot sell shares you don't own). Whatever
 * quantity survives those two checks is what executes — no sizing trim, no
 * stop-loss override.
 *
 * The decision array consumed by executeCycle() is produced by the decision
 * engine (Phase 3). Each element must have: ticker (string), action (string),
 * quantity (?int), price_usd (?float), reason (?string).
 */
class LlmFreeService
{
    public function __construct(
        private readonly PDO                    $db,
        private readonly LlmFreeCycleRepository $cycleRepo,
    ) {}

    /**
     * Guards against an uninitialized wallet (missing seed row).
     *
     * @throws \RuntimeException when llm_free_state does not contain exactly one row
     */
    public function ensureInitialized(): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM llm_free_state');
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();

        if ($count !== 1) {
            throw new \RuntimeException(
                'LLM_Free_Wallet not initialized: expected 1 row in llm_free_state, found ' . $count . '.'
            );
        }
    }

    /**
     * Executes a full rebalance cycle atomically.
     *
     * All writes (llm_free_state, llm_free_holdings, llm_free_transactions,
     * llm_free_cycle summary) happen in one PDO transaction. Any Throwable
     * triggers a full rollback; the exception is re-thrown to the caller.
     *
     * @param array<int, array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}> $decisions
     * @param array<string, float> $priceMap ticker (uppercase) => today's USD snapshot price.
     *        Marks the cycle-end valuation to market, never cost basis — see
     *        computeHoldingsValue()'s docblock.
     * @param string|null $notes Operational note stored on the cycle row and shown
     *        on /llm-free — used to surface decisions the caller had to discard
     *        (e.g. a BUY/SELL with no known price), so the wallet never diverges
     *        from the model's own legend without the user being told.
     */
    public function executeCycle(int $cycleId, array $decisions, array $priceMap, ?string $notes = null): void
    {
        $this->db->beginTransaction();

        try {
            // Read cash inside the transaction (correct isolation pattern).
            $stmt = $this->db->prepare('SELECT cash FROM llm_free_state LIMIT 1');
            $stmt->execute();
            $cashBefore    = (float) $stmt->fetchColumn();
            $cashRunning   = $cashBefore;
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
                    'SELL' => $this->handleSell(
                        $cycleId, $ticker, $quantity ?? 0, $priceUsd ?? 0.0,
                        $reason, $cashRunning, $executedCount, $skippedCount
                    ),
                    'HOLD'      => $this->recordHoldInternal($cycleId, $ticker, $reason),
                    'NO_ACTION' => $this->recordNoActionInternal($cycleId, $reason),
                    default     => null, // unknown action — silently skip
                };
            }

            // Compute wallet value snapshot (cash + holdings marked to today's price).
            $portfolioValueUsd = $cashRunning + $this->computeHoldingsValue($priceMap);

            $this->cycleRepo->updateCycleSummary(
                $cycleId,
                $cashBefore,
                $cashRunning,
                $portfolioValueUsd,
                $executedCount,
                $skippedCount,
                $notes,
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
            $this->insertTransaction(
                $cycleId, $ticker, 'SKIP_INSUFFICIENT_CASH', null, null,
                'skipped_insufficient_cash', $reason, $cashRunning, $cashRunning
            );
            $skippedCount++;
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
        $this->db->prepare('UPDATE llm_free_state SET cash = ?, updated_at = CURRENT_TIMESTAMP')
            ->execute([round($cashAfter, 2)]);

        // Upsert holding with weighted avg_entry_price.
        $existing = $this->fetchHolding($ticker);

        if ($existing !== null) {
            $oldQty = (int) $existing['quantity'];
            $oldAvg = (float) $existing['avg_entry_price'];
            $newAvg = round(($oldQty * $oldAvg + $quantity * $priceUsd) / ($oldQty + $quantity), 4);
            $newQty = $oldQty + $quantity;

            $this->db->prepare(
                'UPDATE llm_free_holdings SET quantity = ?, avg_entry_price = ?, updated_at = CURRENT_TIMESTAMP WHERE ticker = ?'
            )->execute([$newQty, $newAvg, $ticker]);
        } else {
            $this->db->prepare(
                'INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
            )->execute([$ticker, $quantity, round($priceUsd, 4)]);
        }

        $this->insertTransaction($cycleId, $ticker, 'BUY', $quantity, $priceUsd, 'executed', $reason, $cashBefore, $cashAfter);

        $cashRunning = $cashAfter;
        $executedCount++;
    }

    /**
     * Physical guard: a SELL cannot move more shares than are actually held.
     * Zero held → skip entirely (nothing to sell). Held < requested → cap the
     * executed quantity at what's held (partial fill), still counted as executed.
     */
    private function handleSell(
        int     $cycleId,
        string  $ticker,
        int     $quantity,
        float   $priceUsd,
        ?string $reason,
        float   &$cashRunning,
        int     &$executedCount,
        int     &$skippedCount,
    ): void {
        $existing = $this->fetchHolding($ticker);
        $held     = $existing !== null ? (int) $existing['quantity'] : 0;

        if ($held <= 0) {
            $this->insertTransaction(
                $cycleId, $ticker, 'SKIP_INSUFFICIENT_QUANTITY', null, null,
                'skipped_insufficient_quantity', $reason, $cashRunning, $cashRunning
            );
            $skippedCount++;
            return;
        }

        $actualQuantity = min($quantity, $held);

        $this->executeSellInternal($cycleId, $ticker, $actualQuantity, $priceUsd, $reason, $cashRunning, $executedCount);
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
        $this->db->prepare('UPDATE llm_free_state SET cash = ?, updated_at = CURRENT_TIMESTAMP')
            ->execute([round($cashAfter, 2)]);

        // Reduce or remove holding.
        $existing = $this->fetchHolding($ticker);
        if ($existing !== null) {
            $remaining = (int) $existing['quantity'] - $quantity;
            if ($remaining <= 0) {
                $this->db->prepare('DELETE FROM llm_free_holdings WHERE ticker = ?')
                    ->execute([$ticker]);
            } else {
                $this->db->prepare(
                    'UPDATE llm_free_holdings SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE ticker = ?'
                )->execute([$remaining, $ticker]);
            }
        }

        $this->insertTransaction($cycleId, $ticker, 'SELL', $quantity, $priceUsd, 'executed', $reason, $cashBefore, $cashAfter);

        $cashRunning = $cashAfter;
        $executedCount++;
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
        $stmt = $this->db->prepare('SELECT * FROM llm_free_holdings WHERE ticker = ? LIMIT 1');
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
            'INSERT INTO llm_free_transactions
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
     * at cost basis (avg_entry_price) — built this way from the first line of
     * code, unlike the sibling Portfolio module where this was a bug fixed only
     * after the /lab NAV chart started reading portfolio_value_usd as a real
     * comparison line (see src/Portfolio/PortfolioService.php::computeHoldingsValue()).
     *
     * @param array<string, float> $priceMap ticker (uppercase) => today's USD snapshot price
     */
    private function computeHoldingsValue(array $priceMap): float
    {
        $stmt = $this->db->prepare(
            'SELECT ticker, quantity, avg_entry_price FROM llm_free_holdings WHERE quantity > 0'
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
