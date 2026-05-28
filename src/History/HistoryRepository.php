<?php

declare(strict_types=1);

namespace CVS\History;

use CVS\Core\Database;
use PDO;

/**
 * Analysis-history persistence — thin repository over the `analysis_history` table.
 *
 * Table DDL: database/migrations/003_create_analysis_history.sql
 *
 * Accepts an optional PDO injection for test isolation (SQLite in-memory).
 */
class HistoryRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Persist one analysis result for a user.
     *
     * @param array<string, mixed> $result  CVSResult::toArray() output
     */
    public function save(int $userId, string $ticker, array $result): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO analysis_history
                (user_id, ticker, cvs_swing, cvs_fund, reco_swing, reco_fund, golden_signal, quality_gate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $ticker,
            $result['swing']['cvs']                ?? null,
            $result['fundamental']['cvs']          ?? null,
            $result['swing']['recommendation']       ?? null,
            $result['fundamental']['recommendation'] ?? null,
            $result['golden_signal']               ?? null,
            (int) ($result['quality_gate'] ?? 0),
        ]);
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Return the most recent analyses for a user, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $userId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT ticker, cvs_swing, cvs_fund, reco_swing, reco_fund,
                    golden_signal, quality_gate, analysed_at
             FROM analysis_history
             WHERE user_id = :user_id
             ORDER BY analysed_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
