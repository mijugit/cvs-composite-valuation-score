<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use PDO;

/**
 * Data-access layer for the rebalance_cycle table.
 *
 * F-01 scope: gate operations (find, insert, updateStatus).
 * F-02 scope: updateCycleSummary() — financial summary at cycle end.
 * F-03 will extend it with updateLlmRecord().
 */
class CycleRepository
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
            'SELECT * FROM rebalance_cycle WHERE cycle_date = ? LIMIT 1'
        );
        $stmt->execute([$cycleDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Inserts a new cycle row using INSERT IGNORE to be idempotent.
     *
     * Returns the new row's id on success, or null when a row for
     * cycle_date already exists (UNIQUE constraint — INSERT IGNORE skips it).
     */
    public function insertCycle(string $cycleDate): ?int
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO rebalance_cycle (cycle_date, status, started_at)
             VALUES (?, \'started\', CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$cycleDate]);

        if ($stmt->rowCount() === 0) {
            return null; // row already existed — "already_started"
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates the status and sets finished_at = CURRENT_TIMESTAMP.
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE rebalance_cycle SET status = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }

    /**
     * Writes the F-03 LLM audit columns after every generate() call.
     * Called OUTSIDE the portfolio DB transaction (audit must persist even on rollback).
     */
    public function updateLlmRecord(
        int     $id,
        int     $retryCount,
        string  $rawResponse,
        ?string $failureKind,
        ?string $decisionJson,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE rebalance_cycle
             SET retry_count = ?, llm_raw_response = ?, llm_failure_kind = ?, llm_decision_json = ?
             WHERE id = ?'
        );
        $stmt->execute([$retryCount, $rawResponse, $failureKind, $decisionJson, $id]);
    }

    /**
     * Writes the F-02 financial summary columns at cycle end.
     * Called inside the open DB transaction in PortfolioService::executeCycle().
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
            'UPDATE rebalance_cycle
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
