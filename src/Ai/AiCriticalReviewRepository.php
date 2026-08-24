<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Async job cache for the stage-2 critical review — change: cvs-ai-critical-review,
 * extended with a `provider` dimension by change: critical-review-models.
 *
 * Table DDL: database/migrations/030_create_ai_critical_reviews.sql,
 * database/migrations/041_add_provider_and_probability_to_ai_critical_reviews.sql
 *
 * Unlike AiAnalysisRepository (etap 1, always-complete rows), a row here can
 * sit in status='pending' while a background CLI job does the real work —
 * see bin/generate_critical_review.php / bin/generate_critical_review_gemini.php.
 * markPending() never clears an existing completed row's content, so a failed
 * refresh doesn't lose the last good result the user already saw.
 *
 * Every method is scoped by (ticker, provider) — the unique key is
 * (ticker, provider), so Claude and Gemini reviews for the same ticker are
 * independent rows and must never be conflated by a query missing the
 * provider filter.
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
    public function findByTickerAndProvider(string $ticker, string $provider): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_critical_reviews WHERE ticker = ? AND provider = ? LIMIT 1');
        $stmt->execute([strtoupper($ticker), $provider]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Both providers' rows for a ticker in one query — used to render both
     * tabs' initial state on page load without two round-trips.
     *
     * @return array<string, array<string, mixed>> keyed by provider; only present providers included
     */
    public function findAllProvidersForTicker(string $ticker): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_critical_reviews WHERE ticker = ?');
        $stmt->execute([strtoupper($ticker)]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['provider']] = $row;
        }
        return $out;
    }

    /**
     * True when a COMPLETED review exists for this (ticker, provider) and is
     * at most $hours old. Pending or failed rows never count as fresh — only
     * a real, finished result does.
     */
    public function isFresh(string $ticker, string $provider, int $hours = 48): bool
    {
        $cutoff = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
        $stmt   = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_critical_reviews WHERE ticker = ? AND provider = ? AND status = 'completed' AND generated_at >= ?"
        );
        $stmt->execute([strtoupper($ticker), $provider, $cutoff]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * True when a background job is currently in flight for this
     * (ticker, provider) — blocks a second POST for the SAME provider from
     * firing a duplicate background process. Does NOT block the other
     * provider — each provider is an independent row with no shared
     * resource requiring a lock (FR-002).
     */
    public function isPending(string $ticker, string $provider): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_critical_reviews WHERE ticker = ? AND provider = ? AND status = 'pending'"
        );
        $stmt->execute([strtoupper($ticker), $provider]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Mark a (ticker, provider) job as started. INSERT + catch-duplicate →
     * UPDATE, the same portable pattern as AiAnalysisRepository::save().
     * Deliberately does NOT touch content/sources/probability fields — only
     * markCompleted() overwrites them, so a stale-but-valid review stays
     * visible while a refresh is running.
     */
    public function markPending(string $ticker, string $provider, int $userId): void
    {
        $ticker    = strtoupper($ticker);
        $startedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO ai_critical_reviews (ticker, provider, status, generated_by, started_at) VALUES (?, ?, 'pending', ?, ?)"
            );
            $stmt->execute([$ticker, $provider, $userId, $startedAt]);
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
                     WHERE ticker = ? AND provider = ?"
                );
                $upd->execute([$userId, $startedAt, $ticker, $provider]);
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
        string $provider,
        string $content,
        array  $sources,
        string $model,
        int    $tokensIn,
        int    $tokensOut,
        ?int   $bullProbability,
        ?int   $bearProbability,
        ?string $probabilityRationale
    ): void {
        $ticker      = strtoupper($ticker);
        $generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $sourcesJson = json_encode($sources);

        $stmt = $this->db->prepare(
            "UPDATE ai_critical_reviews
             SET status = 'completed', content = ?, sources = ?, model = ?,
                 tokens_input = ?, tokens_output = ?, generated_at = ?, error_message = NULL,
                 bull_probability = ?, bear_probability = ?, probability_rationale = ?
             WHERE ticker = ? AND provider = ?"
        );
        $stmt->execute([
            $content,
            $sourcesJson !== false ? $sourcesJson : '[]',
            $model,
            $tokensIn,
            $tokensOut,
            $generatedAt,
            $bullProbability,
            $bearProbability,
            $probabilityRationale,
            $ticker,
            $provider,
        ]);
    }

    public function markFailed(string $ticker, string $provider, string $errorMessage): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ai_critical_reviews SET status = 'failed', error_message = ? WHERE ticker = ? AND provider = ?"
        );
        $stmt->execute([$errorMessage, strtoupper($ticker), $provider]);
    }
}
