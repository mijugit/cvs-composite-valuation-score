<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Shared AI analysis cache — one row per ticker, overwritten on refresh.
 *
 * Table DDL: database/migrations/008_create_ai_analyses.sql
 */
class AiAnalysisRepository
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
     * Latest analysis for a ticker, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    public function findByTicker(string $ticker): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ai_analyses WHERE ticker = ? LIMIT 1'
        );
        $stmt->execute([strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * True when analysis exists and was generated within the last $days days.
     */
    public function isFresh(string $ticker, int $days = 7): bool
    {
        $cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $stmt   = $this->db->prepare(
            'SELECT COUNT(*) FROM ai_analyses WHERE ticker = ? AND generated_at >= ?'
        );
        $stmt->execute([strtoupper($ticker), $cutoff]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * True when PRO can request a refresh — analysis exists AND is at least
     * $minHours old (avoids spamming the API multiple times per day).
     */
    public function needsRefresh(string $ticker, int $minHours = 24): bool
    {
        $cutoff = (new DateTimeImmutable("-{$minHours} hours"))->format('Y-m-d H:i:s');
        $stmt   = $this->db->prepare(
            'SELECT COUNT(*) FROM ai_analyses WHERE ticker = ? AND generated_at <= ?'
        );
        $stmt->execute([strtoupper($ticker), $cutoff]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Insert or update the analysis for this ticker.
     * Uses INSERT + catch UNIQUE duplicate → UPDATE pattern (works on MySQL and SQLite).
     */
    public function save(
        string $ticker,
        string $content,
        string $model,
        int    $tokensIn,
        int    $tokensOut,
        ?int   $generatedBy
    ): void {
        $ticker    = strtoupper($ticker);
        $generatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare('
                INSERT INTO ai_analyses
                    (ticker, content, model, tokens_input, tokens_output,
                     generated_by, generated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$ticker, $content, $model, $tokensIn, $tokensOut,
                $generatedBy, $generatedAt]);
        } catch (PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');

            if (!$isDup) {
                error_log('AiAnalysisRepository::save failed: ' . $msg);
                return;
            }

            // Overwrite existing row for this ticker.
            try {
                $upd = $this->db->prepare('
                    UPDATE ai_analyses
                    SET content = ?, model = ?, tokens_input = ?, tokens_output = ?,
                        generated_by = ?, generated_at = ?
                    WHERE ticker = ?
                ');
                $upd->execute([$content, $model, $tokensIn, $tokensOut,
                    $generatedBy, $generatedAt, $ticker]);
            } catch (PDOException $ue) {
                error_log('AiAnalysisRepository::update failed: ' . $ue->getMessage());
            }
        }
    }
}
