<?php

declare(strict_types=1);

namespace CVS\Pro;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * AI usage log persistence.
 *
 * Records each AI generation call and provides counts for rate-limiting.
 * Table DDL: database/migrations/007_create_ai_usage_log.sql
 */
class AiUsageRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function log(
        int    $userId,
        string $proCode,
        int    $tokensIn,
        int    $tokensOut
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO ai_usage_log (user_id, pro_code, tokens_input, tokens_output)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $proCode, $tokensIn, $tokensOut]);
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function countToday(int $userId): int
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $stmt  = $this->db->prepare('
            SELECT COUNT(*) FROM ai_usage_log
            WHERE user_id = ? AND generated_at >= ?
        ');
        $stmt->execute([$userId, $today . ' 00:00:00']);
        return (int) $stmt->fetchColumn();
    }

    public function countThisMonth(int $userId): int
    {
        $firstDay = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $stmt     = $this->db->prepare('
            SELECT COUNT(*) FROM ai_usage_log
            WHERE user_id = ? AND generated_at >= ?
        ');
        $stmt->execute([$userId, $firstDay . ' 00:00:00']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Recent usage entries for a user (for future UI display).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM ai_usage_log
            WHERE user_id = ?
            ORDER BY generated_at DESC
            LIMIT ?
        ');
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll() ?: [];
    }
}
