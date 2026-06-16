<?php

declare(strict_types=1);

namespace CVS\Auth;

use CVS\Core\Database;
use PDO;

/**
 * User persistence — thin repository over the `users` table.
 *
 * Table DDL (add to migrations):
 *   CREATE TABLE users (
 *       id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *       email         VARCHAR(255) NOT NULL UNIQUE,
 *       password_hash VARCHAR(255) NOT NULL,
 *       created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
class UserRepository
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
     * All registered users — used by the daily-rescore CLI to iterate watchlists.
     *
     * @return array<int, array{id: int, email: string}>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, email FROM users ORDER BY id ASC');
        if ($stmt === false) {
            return [];
        }
        /** @var array<int, array{id: int, email: string}> */
        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, password_hash, is_admin FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, is_admin FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function emailExists(string $email): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Insert a new user and return the new ID.
     */
    public function create(string $email, string $passwordHash): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password_hash) VALUES (?, ?)'
        );
        $stmt->execute([$email, $passwordHash]);
        return (int) $this->db->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Verification token
    // ------------------------------------------------------------------

    public function setVerifyToken(int $id, string $token, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET email_verify_token = ?, email_verify_expires_at = ? WHERE id = ?'
        );
        $stmt->execute([$token, $expiresAt, $id]);
    }

    /** @return array{id:int, email:string}|null — null gdy token nieznany lub wygasł */
    public function findByVerifyToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email FROM users
             WHERE email_verify_token = ? AND email_verify_expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setEmailVerified(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET email_verified_at = NOW(),
                              email_verify_token = NULL,
                              email_verify_expires_at = NULL
             WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function isEmailVerified(int $id): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM users WHERE id = ? AND email_verified_at IS NOT NULL'
        );
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
