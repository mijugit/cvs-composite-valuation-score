<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Async job cache for fundamentals-validation — change: fundamentals-validation.
 *
 * Table DDL: database/migrations/040_create_fundamental_validation_runs.sql
 *
 * Mirrors AiCriticalReviewRepository's pending/completed/failed shape exactly
 * (see that class's docblock), but holds a structured, not-yet-applied `diff`
 * rather than free text — this table is PROPOSED state. Confirmed values only
 * ever land in fundamental_overrides (FundamentalOverrideRepository), written
 * by the controller's confirm step, never by this repository.
 */
class FundamentalsValidationRunRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function findByTicker(string $ticker): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM fundamental_validation_runs WHERE ticker = ? LIMIT 1');
        $stmt->execute([strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * True when a background job is currently in flight for this ticker —
     * blocks a second trigger from firing a duplicate background process.
     */
    public function isPending(string $ticker): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM fundamental_validation_runs WHERE ticker = ? AND status = 'pending'"
        );
        $stmt->execute([strtoupper($ticker)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Mark a ticker's job as started. INSERT + catch-duplicate -> UPDATE, the
     * same portable pattern as AiCriticalReviewRepository::markPending().
     * Deliberately does NOT touch diff/notes — only markCompleted() overwrites
     * them, so a stale-but-valid diff from a prior run stays visible while a
     * refresh is running.
     *
     * @param list<string> $requestedFields
     */
    public function markPending(string $ticker, string $mode, array $requestedFields, int $requestedBy): void
    {
        $ticker      = strtoupper($ticker);
        $requestedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $fieldsJson  = json_encode($requestedFields);

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO fundamental_validation_runs (ticker, status, mode, requested_fields, requested_by, requested_at)
                 VALUES (?, 'pending', ?, ?, ?, ?)"
            );
            $stmt->execute([$ticker, $mode, $fieldsJson !== false ? $fieldsJson : '[]', $requestedBy, $requestedAt]);
        } catch (PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');

            if (!$isDup) {
                error_log('FundamentalsValidationRunRepository::markPending failed: ' . $msg);
                return;
            }

            try {
                $upd = $this->db->prepare(
                    "UPDATE fundamental_validation_runs
                     SET status = 'pending', mode = ?, requested_fields = ?, requested_by = ?,
                         requested_at = ?, error_message = NULL
                     WHERE ticker = ?"
                );
                $upd->execute([$mode, $fieldsJson !== false ? $fieldsJson : '[]', $requestedBy, $requestedAt, $ticker]);
            } catch (PDOException $ue) {
                error_log('FundamentalsValidationRunRepository::markPending update failed: ' . $ue->getMessage());
            }
        }
    }

    /**
     * @param array<string, array{old: mixed, new: mixed, status: string}> $diff
     */
    public function markCompleted(string $ticker, array $diff, string $notes, string $model): void
    {
        $ticker      = strtoupper($ticker);
        $completedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $diffJson    = json_encode($diff);

        $stmt = $this->db->prepare(
            "UPDATE fundamental_validation_runs
             SET status = 'completed', diff = ?, notes = ?, model = ?, completed_at = ?, error_message = NULL
             WHERE ticker = ?"
        );
        $stmt->execute([
            $diffJson !== false ? $diffJson : '{}',
            $notes,
            $model,
            $completedAt,
            $ticker,
        ]);
    }

    public function markFailed(string $ticker, string $errorMessage): void
    {
        $stmt = $this->db->prepare(
            "UPDATE fundamental_validation_runs SET status = 'failed', error_message = ? WHERE ticker = ?"
        );
        $stmt->execute([$errorMessage, strtoupper($ticker)]);
    }
}
