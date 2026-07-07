<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Async job cache for the stage-2 critical review — change: cvs-ai-critical-review.
 *
 * Table DDL: database/migrations/030_create_ai_critical_reviews.sql
 *
 * Unlike AiAnalysisRepository (etap 1, always-complete rows), a row here can
 * sit in status='pending' while a background CLI job does the real work —
 * see bin/generate_critical_review.php. markPending() never clears an
 * existing completed row's content, so a failed refresh doesn't lose the
 * last good result the user already saw.
 */
class AiCriticalReviewRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function findByTicker(string $ticker): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_critical_reviews WHERE ticker = ? LIMIT 1');
        $stmt->execute([strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * True when a COMPLETED review exists and is at most $hours old. Pending
     * or failed rows never count as fresh — only a real, finished result does.
     */
    public function isFresh(string $ticker, int $hours = 48): bool
    {
        $cutoff = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
        $stmt   = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_critical_reviews WHERE ticker = ? AND status = 'completed' AND generated_at >= ?"
        );
        $stmt->execute([strtoupper($ticker), $cutoff]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * True when a background job is currently in flight for this ticker —
     * blocks a second POST from firing a duplicate background process.
     */
    public function isPending(string $ticker): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_critical_reviews WHERE ticker = ? AND status = 'pending'"
        );
        $stmt->execute([strtoupper($ticker)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Mark a ticker's job as started. INSERT + catch-duplicate → UPDATE, the
     * same portable pattern as AiAnalysisRepository::save(). Deliberately
     * does NOT touch content/sources — only markCompleted() overwrites them,
     * so a stale-but-valid review stays visible while a refresh is running.
     */
    public function markPending(string $ticker, int $userId): void
    {
        $ticker    = strtoupper($ticker);
        $startedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO ai_critical_reviews (ticker, status, generated_by, started_at) VALUES (?, 'pending', ?, ?)"
            );
            $stmt->execute([$ticker, $userId, $startedAt]);
        } catch (PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');

            if (!$isDup) {
                error_log('AiCriticalReviewRepository::markPending failed: ' . $msg);
                return;
            }

            try {
                $upd = $this->db->prepare(
                    "UPDATE ai_critical_reviews
                     SET status = 'pending', generated_by = ?, started_at = ?, error_message = NULL
                     WHERE ticker = ?"
                );
                $upd->execute([$userId, $startedAt, $ticker]);
            } catch (PDOException $ue) {
                error_log('AiCriticalReviewRepository::markPending update failed: ' . $ue->getMessage());
            }
        }
    }

    /**
     * @param list<array{url: string, title: string}> $sources
     */
    public function markCompleted(
        string $ticker,
        string $content,
        array  $sources,
        string $model,
        int    $tokensIn,
        int    $tokensOut
    ): void {
        $ticker      = strtoupper($ticker);
        $generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $sourcesJson = json_encode($sources);

        $stmt = $this->db->prepare(
            "UPDATE ai_critical_reviews
             SET status = 'completed', content = ?, sources = ?, model = ?,
                 tokens_input = ?, tokens_output = ?, generated_at = ?, error_message = NULL
             WHERE ticker = ?"
        );
        $stmt->execute([
            $content,
            $sourcesJson !== false ? $sourcesJson : '[]',
            $model,
            $tokensIn,
            $tokensOut,
            $generatedAt,
            $ticker,
        ]);
    }

    public function markFailed(string $ticker, string $errorMessage): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ai_critical_reviews SET status = 'failed', error_message = ? WHERE ticker = ?"
        );
        $stmt->execute([$errorMessage, strtoupper($ticker)]);
    }
}
