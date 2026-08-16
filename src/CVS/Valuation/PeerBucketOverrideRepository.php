<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * Admin-defined peer groups (migration 037).
 *
 * Substitutes the bucket the Valuation pillar benchmarks against, without
 * touching Yahoo's own `industry` — see the migration for why the two cases
 * (segment dominance vs region/regulator) behave differently.
 */
class PeerBucketOverrideRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Every override as ticker => bucket_key, uppercased.
     *
     * One bulk read for the scoring run: bin/rescore.php walks a hundred-plus
     * tickers, and a per-ticker lookup there would be a hundred round trips for
     * a table that holds a handful of rows.
     *
     * @return array<string, string>
     */
    public function findBucketMap(): array
    {
        $stmt = $this->db->query('SELECT ticker, bucket_key FROM peer_bucket_override');
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $out = [];
        foreach ($rows as $r) {
            $out[strtoupper((string) $r['ticker'])] = (string) $r['bucket_key'];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM peer_bucket_override ORDER BY bucket_key ASC, ticker ASC'
        );
        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string, mixed>|null */
    public function findByTicker(string $ticker): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM peer_bucket_override WHERE ticker = ?');
        $stmt->execute([strtoupper($ticker)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Sets or replaces a ticker's override.
     *
     * @param string|null $reviewDate Y-m-d, or null for a structural grouping
     *        that does not expire (region/regulator). A cycle-dependent
     *        grouping should always carry one.
     */
    public function upsert(
        string  $ticker,
        string  $bucketKey,
        ?string $reason = null,
        ?string $reviewDate = null,
        ?int    $createdBy = null
    ): void {
        $ticker = strtoupper(trim($ticker));
        $now    = date('Y-m-d H:i:s');

        try {
            $this->db->prepare(
                'INSERT INTO peer_bucket_override (ticker, bucket_key, reason, review_date, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$ticker, trim($bucketKey), $reason, $reviewDate, $createdBy, $now]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log(sprintf('PeerBucketOverrideRepository::upsert failed for %s: %s', $ticker, $msg));
                return;
            }

            $this->db->prepare(
                'UPDATE peer_bucket_override
                 SET bucket_key = ?, reason = ?, review_date = ?, updated_at = ?
                 WHERE ticker = ?'
            )->execute([trim($bucketKey), $reason, $reviewDate, $now, $ticker]);
        }
    }

    public function delete(string $ticker): void
    {
        $this->db->prepare('DELETE FROM peer_bucket_override WHERE ticker = ?')
            ->execute([strtoupper(trim($ticker))]);
    }

    /**
     * Overrides whose review date has passed — a grouping that was true for a
     * cycle and nobody has revisited. Surfaced in the admin view so a stale
     * grouping is a visible decision rather than a forgotten one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDueForReview(string $today): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM peer_bucket_override
             WHERE review_date IS NOT NULL AND review_date <= ?
             ORDER BY review_date ASC'
        );
        $stmt->execute([$today]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
