<?php

declare(strict_types=1);

namespace CVS\Links;

use CVS\Core\Database;
use PDO;

/**
 * Persistence for admin-curated favourite links per ticker (screener
 * right-click menu). Writes are admin-gated in TickerLinkController; this
 * repository itself does no authorization — it is a plain CRUD layer.
 */
class TickerLinkRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * One link row, ordered oldest-first (insertion order — no separate
     * sort_order column; reordering was not requested).
     *
     * @return list<array{id: int, label: string, url: string}>
     */
    public function findByTicker(string $ticker): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, url FROM ticker_links WHERE ticker = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$ticker]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'url' => (string) $row['url']];
        }
        return $out;
    }

    public function countByTicker(string $ticker): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ticker_links WHERE ticker = ?');
        $stmt->execute([$ticker]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{id: int, label: string, url: string} */
    public function create(string $ticker, string $label, string $url, ?int $createdBy): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticker_links (ticker, label, url, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ticker, $label, $url, $createdBy]);

        return ['id' => (int) $this->db->lastInsertId(), 'label' => $label, 'url' => $url];
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ticker_links WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
