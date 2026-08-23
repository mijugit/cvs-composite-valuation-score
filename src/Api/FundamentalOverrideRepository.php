<?php

declare(strict_types=1);

namespace CVS\Api;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * Admin-confirmed fundamental-data overrides (migration 039, change:
 * fundamentals-validation).
 *
 * One row per (ticker, field_name). Read by SuspectFieldDetector's caller to
 * drive raw-data-table coloring and by FundamentalOverrideMerger to build the
 * scoring input; written only by the validation controller's confirm step —
 * never by the background worker directly (the worker writes to
 * fundamental_validation_runs, a separate PROPOSED-state table).
 */
class FundamentalOverrideRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<string, array{value: ?string, status: string, source: string, validated_at: string}>
     *         keyed by field_name
     */
    public function findByTicker(string $ticker): array
    {
        $stmt = $this->db->prepare(
            'SELECT field_name, value, status, source, validated_at
             FROM fundamental_overrides
             WHERE ticker = ?'
        );
        $stmt->execute([strtoupper(trim($ticker))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['field_name']] = [
                'value'        => $row['value'] !== null ? (string) $row['value'] : null,
                'status'       => (string) $row['status'],
                'source'       => (string) $row['source'],
                'validated_at' => (string) $row['validated_at'],
            ];
        }
        return $out;
    }

    /**
     * Every override, grouped by ticker — one bulk read for the whole batch run
     * (bin/rescore.php walks the full watchlist union; a per-ticker lookup there
     * would be a round trip per ticker for a table that's small in comparison).
     * Same "findBucketMap()-style bulk read before the loop" shape as
     * PeerBucketOverrideRepository — see bin/rescore.php's merge point.
     *
     * @return array<string, array<string, array{value: ?string, status: string, source: string, validated_at: string}>>
     *         ticker (uppercase) => field_name => row
     */
    public function findAllGroupedByTicker(): array
    {
        $stmt = $this->db->query('SELECT ticker, field_name, value, status, source, validated_at FROM fundamental_overrides');
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $out = [];
        foreach ($rows as $row) {
            $ticker = strtoupper((string) $row['ticker']);
            $out[$ticker][(string) $row['field_name']] = [
                'value'        => $row['value'] !== null ? (string) $row['value'] : null,
                'status'       => (string) $row['status'],
                'source'       => (string) $row['source'],
                'validated_at' => (string) $row['validated_at'],
            ];
        }
        return $out;
    }

    /**
     * Sets or replaces one field's override.
     *
     * @param string|null $value NULL means "checked, no value found" — the
     *                            caller must pass $status='checked_no_data' in
     *                            that case, never a coalesced empty string.
     */
    public function upsert(
        string  $ticker,
        string  $field,
        ?string $value,
        string  $status,
        string  $source = 'gemini_validation',
        ?int    $validatedBy = null
    ): void {
        $ticker = strtoupper(trim($ticker));
        $now    = date('Y-m-d H:i:s');

        try {
            $this->db->prepare(
                'INSERT INTO fundamental_overrides
                     (ticker, field_name, value, status, source, validated_by, validated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$ticker, $field, $value, $status, $source, $validatedBy, $now]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log(sprintf('FundamentalOverrideRepository::upsert failed for %s.%s: %s', $ticker, $field, $msg));
                return;
            }

            $this->db->prepare(
                'UPDATE fundamental_overrides
                 SET value = ?, status = ?, source = ?, validated_by = ?, validated_at = ?
                 WHERE ticker = ? AND field_name = ?'
            )->execute([$value, $status, $source, $validatedBy, $now, $ticker, $field]);
        }
    }
}
