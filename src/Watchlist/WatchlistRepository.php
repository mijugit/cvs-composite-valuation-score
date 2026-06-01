<?php

declare(strict_types=1);

namespace CVS\Watchlist;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * Watchlist persistence — thin repository over the `watchlist` table.
 *
 * Table DDL: database/migrations/002_create_watchlist.sql
 *
 * Accepts an optional PDO injection for test isolation (SQLite in-memory).
 */
class WatchlistRepository
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
     * Return all tickers for the given user in insertion order.
     *
     * @return string[]
     */
    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ticker FROM watchlist WHERE user_id = ? ORDER BY added_at ASC, id ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Union of all users' watchlists — the rescore batch input.
     *
     * @return string[]
     */
    public function findAllDistinctTickers(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT ticker FROM watchlist ORDER BY ticker ASC');
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function isWatched(int $userId, string $ticker): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM watchlist WHERE user_id = ? AND ticker = ?'
        );
        $stmt->execute([$userId, $ticker]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM watchlist WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Add a ticker; silently ignore if already present (idempotent).
     */
    public function add(int $userId, string $ticker): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO watchlist (user_id, ticker) VALUES (?, ?)'
        );
        try {
            $stmt->execute([$userId, $ticker]);
        } catch (PDOException $e) {
            // Duplicate key on MySQL ("Duplicate entry") or SQLite ("UNIQUE constraint").
            if (!str_contains($e->getMessage(), 'Duplicate') &&
                !str_contains($e->getMessage(), 'UNIQUE constraint')) {
                throw $e;
            }
        }
    }

    public function remove(int $userId, string $ticker): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM watchlist WHERE user_id = ? AND ticker = ?'
        );
        $stmt->execute([$userId, $ticker]);
    }

    /**
     * Toggle: add if absent, remove if present.
     *
     * @return 'added'|'removed'
     */
    public function toggle(int $userId, string $ticker): string
    {
        if ($this->isWatched($userId, $ticker)) {
            $this->remove($userId, $ticker);
            return 'removed';
        }

        $this->add($userId, $ticker);
        return 'added';
    }
}
