<?php

declare(strict_types=1);

namespace CVS\Logo;

use CVS\Core\Database;
use PDO;

/**
 * Persistence for the `ticker_logos` cache table — one row per ticker.
 * Bulk-read mirrors ScreenerRepository::findTickerLinksMap() (single IN(...)
 * query, degrade to [] if the table doesn't exist yet). Writes only ever
 * come from bin/fetch_logos.php — there is no user-facing mutation path.
 */
class TickerLogoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @param list<string> $tickers
     * @return array<string, array{logo_path: string|null, status: string}>
     */
    public function findByTickers(array $tickers): array
    {
        $tickers = array_values(array_unique(array_filter($tickers, fn($t) => $t !== '')));
        if ($tickers === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tickers), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT ticker, logo_path, status FROM ticker_logos WHERE ticker IN ({$placeholders})"
            );
            $stmt->execute($tickers);
        } catch (\PDOException) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(string) $r['ticker']] = [
                'logo_path' => $r['logo_path'] !== null ? (string) $r['logo_path'] : null,
                'status'    => (string) $r['status'],
            ];
        }
        return $map;
    }

    /** @return array{domain: string|null, logo_path: string|null, status: string}|null */
    public function findByTicker(string $ticker): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT domain, logo_path, status FROM ticker_logos WHERE ticker = ?'
            );
            $stmt->execute([$ticker]);
        } catch (\PDOException) {
            return null;
        }

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'domain'    => $row['domain'] !== null ? (string) $row['domain'] : null,
            'logo_path' => $row['logo_path'] !== null ? (string) $row['logo_path'] : null,
            'status'    => (string) $row['status'],
        ];
    }

    /**
     * Tickers already processed (found or not_found) — the skip-list
     * bin/fetch_logos.php uses to avoid re-querying logo.dev.
     *
     * @return list<string>
     */
    public function existingTickers(): array
    {
        $stmt = $this->db->query('SELECT ticker FROM ticker_logos');
        return array_map(static fn($r) => (string) $r['ticker'], $stmt->fetchAll());
    }

    /**
     * Insert-or-update by primary key. Uses a try-INSERT/catch-duplicate
     * pattern (mirror CvsSnapshotRepository::save()) rather than MySQL's
     * `ON DUPLICATE KEY UPDATE`, so the same code path runs against the
     * in-memory SQLite used by TickerLogoRepositoryTest and against the
     * real MySQL database.
     */
    public function upsert(string $ticker, ?string $domain, ?string $logoPath, string $status): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ticker_logos (ticker, domain, logo_path, status, fetched_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$ticker, $domain, $logoPath, $status, $now, $now]);
        } catch (\PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');
            if (!$isDup) {
                throw $e;
            }

            $upd = $this->db->prepare(
                'UPDATE ticker_logos
                 SET domain = ?, logo_path = ?, status = ?, fetched_at = ?, updated_at = ?
                 WHERE ticker = ?'
            );
            $upd->execute([$domain, $logoPath, $status, $now, $now, $ticker]);
        }
    }
}
