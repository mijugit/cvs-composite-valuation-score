<?php

declare(strict_types=1);

namespace CVS\Links;

use CVS\Core\Database;
use PDO;

/**
 * Persistence for favourite links per ticker (screener right-click menu).
 * Any authenticated user may add a link; deleting one requires ownership
 * or admin — both are enforced in TickerLinkController, never here. This
 * repository itself does no authorization, it is a plain CRUD layer.
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
     * @return list<array{id: int, label: string, url: string, created_by: int|null}>
     */
    public function findByTicker(string $ticker): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, url, created_by FROM ticker_links WHERE ticker = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$ticker]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = self::mapRow($row);
        }
        return $out;
    }

    /**
     * Single row by id, for the delete endpoint's ownership check.
     *
     * @return array{id: int, label: string, url: string, created_by: int|null}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, label, url, created_by FROM ticker_links WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? self::mapRow($row) : null;
    }

    public function countByTicker(string $ticker): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ticker_links WHERE ticker = ?');
        $stmt->execute([$ticker]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{id: int, label: string, url: string, created_by: int|null} */
    public function create(string $ticker, string $label, string $url, ?int $createdBy): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticker_links (ticker, label, url, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ticker, $label, $url, $createdBy]);

        return ['id' => (int) $this->db->lastInsertId(), 'label' => $label, 'url' => $url, 'created_by' => $createdBy];
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ticker_links WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, label: string, url: string, created_by: int|null}
     */
    private static function mapRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'label'      => (string) $row['label'],
            'url'        => (string) $row['url'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        ];
    }
}
