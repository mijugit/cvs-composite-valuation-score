<?php

declare(strict_types=1);

namespace CVS\LlmFree;

use PDO;

/**
 * Data-access layer for the llm_free_cycle table.
 *
 * Mirrors CVS\Portfolio\CycleRepository's claim/status/summary contract exactly,
 * plus one additive method: updateLlmRecord() also folds in the model's legend
 * entry and token usage counters, since this wallet needs them from day one
 * rather than growing them on later migrations.
 */
class LlmFreeCycleRepository
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Returns the cycle row for the given date, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    public function findTodayCycle(string $cycleDate): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM llm_free_cycle WHERE cycle_date = ? LIMIT 1'
        );
        $stmt->execute([$cycleDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Full mark-to-market history, oldest first — feeds the wallet NAV
     * comparison chart on /llm-free (change: wallet-nav-chart). Mirrors
     * CVS\Portfolio\CycleRepository::getValueSeries() exactly, one table over.
     *
     * @return list<array{date: string, value: float}>
     */
    public function getValueSeries(): array
    {
        $stmt = $this->db->query(
            "SELECT cycle_date, portfolio_value_usd FROM llm_free_cycle
             WHERE status = 'completed' AND portfolio_value_usd IS NOT NULL
             ORDER BY cycle_date ASC"
        );
        $rows = $stmt !== false ? ($stmt->fetchAll() ?: []) : [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['date' => (string) $r['cycle_date'], 'value' => (float) $r['portfolio_value_usd']];
        }
        return $out;
    }

    /**
     * Claims the cycle for the given date for execution, returning its id, or
     * null when the cycle must NOT run now.
     *
     * Rules:
     *   - No row yet           → insert a fresh cycle (attempt 1), return its id.
     *   - status 'completed'   → return null (already done for the day).
     *   - status 'started'     → return null (a run is in progress / crashed; avoid
     *                            concurrent execution — the day resets tomorrow).
     *   - status failed/llm_failed:
     *       - attempt_count >= $maxAttempts → return null (retries exhausted).
     *       - otherwise → reset to 'started', increment attempt_count, clear
     *         finished_at, and return the id for a retry on the same row.
     */
    public function claimForRun(string $cycleDate, int $maxAttempts): ?int
    {
        // Fast path: brand-new day. Portable "insert if absent" (works on MySQL and
        // SQLite); the UNIQUE(cycle_date) constraint is the concurrency backstop.
        $stmt = $this->db->prepare(
            'INSERT INTO llm_free_cycle (cycle_date, status, attempt_count, started_at)
             SELECT ?, \'started\', 1, CURRENT_TIMESTAMP
             WHERE NOT EXISTS (SELECT 1 FROM llm_free_cycle WHERE cycle_date = ?)'
        );
        $stmt->execute([$cycleDate, $cycleDate]);

        if ($stmt->rowCount() > 0) {
            return (int) $this->db->lastInsertId();
        }

        // Row exists — decide whether this is a permitted retry.
        $existing = $this->findTodayCycle($cycleDate);
        if ($existing === null) {
            return null; // race: vanished between INSERT and SELECT
        }

        $id       = (int) $existing['id'];
        $status   = (string) ($existing['status'] ?? '');
        $attempts = (int) ($existing['attempt_count'] ?? 1);

        if (!in_array($status, ['failed', 'llm_failed'], true)) {
            return null; // completed, started, or any non-retryable state
        }

        if ($attempts >= $maxAttempts) {
            return null; // retries exhausted for the day
        }

        $upd = $this->db->prepare(
            'UPDATE llm_free_cycle
             SET status = \'started\', attempt_count = attempt_count + 1,
                 started_at = CURRENT_TIMESTAMP, finished_at = NULL
             WHERE id = ? AND status IN (\'failed\', \'llm_failed\')'
        );
        $upd->execute([$id]);

        // If another tick claimed it first, the UPDATE affected 0 rows.
        return $upd->rowCount() > 0 ? $id : null;
    }

    /**
     * Updates the status and sets finished_at = CURRENT_TIMESTAMP.
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE llm_free_cycle SET status = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }

    /**
     * Writes the LLM audit columns after every generate() call, including the
     * model's legend entry and token usage — this wallet folds them into the
     * same write the sibling module splits across two migrations (027 + this).
     * Called OUTSIDE the wallet's execution DB transaction (audit must persist
     * even on rollback).
     */
    public function updateLlmRecord(
        int     $id,
        int     $retryCount,
        string  $rawResponse,
        ?string $failureKind,
        ?string $decisionJson,
        ?string $legend,
        int     $tokensInput,
        int     $tokensOutput,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE llm_free_cycle
             SET retry_count = ?, llm_raw_response = ?, llm_failure_kind = ?, llm_decision_json = ?,
                 legend = ?, tokens_input = ?, tokens_output = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $retryCount,
            $rawResponse,
            $failureKind,
            $decisionJson,
            $legend,
            $tokensInput,
            $tokensOutput,
            $id,
        ]);
    }

    /**
     * Writes the financial summary columns at cycle end.
     * Called inside the open DB transaction in LlmFreeService::executeCycle().
     */
    public function updateCycleSummary(
        int     $id,
        float   $cashBefore,
        float   $cashAfter,
        float   $portfolioValueUsd,
        int     $executedCount,
        int     $skippedCount,
        ?string $notes,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE llm_free_cycle
             SET cash_before = ?, cash_after = ?, portfolio_value_usd = ?,
                 executed_count = ?, skipped_count = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            round($cashBefore, 2),
            round($cashAfter, 2),
            round($portfolioValueUsd, 2),
            $executedCount,
            $skippedCount,
            $notes,
            $id,
        ]);
    }
}
