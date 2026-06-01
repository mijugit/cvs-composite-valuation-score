<?php

declare(strict_types=1);

namespace CVS\Pro;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * PRO code persistence.
 *
 * user_id NULL  = global code (any user who knows it can generate AI).
 * user_id = N   = code assigned to a specific user.
 *
 * Table DDL: database/migrations/006_create_pro_codes.sql
 */
class ProRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Check whether a code is active and usable by the given user.
     *
     * Accepts global codes (user_id IS NULL) or codes assigned to $userId.
     */
    public function findActiveCode(string $code, int $userId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM pro_codes
            WHERE code = ? AND is_active = 1
              AND (user_id IS NULL OR user_id = ?)
        ');
        $stmt->execute([$code, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * All codes for the admin panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('
            SELECT p.*, u.email AS user_email
            FROM pro_codes p
            LEFT JOIN users u ON u.id = p.user_id
            ORDER BY p.created_at DESC
        ');
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll() ?: [];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function create(string $code, ?int $userId, string $description): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO pro_codes (code, user_id, description)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$code, $userId, $description ?: null]);
        } catch (PDOException $e) {
            error_log('ProRepository::create failed: ' . $e->getMessage());
        }
    }

    public function revoke(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pro_codes SET is_active = 0 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function activate(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pro_codes SET is_active = 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }
}
