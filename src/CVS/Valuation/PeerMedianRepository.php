<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Persistence for empirical peer-group medians.
 *
 * Stores one row per (level, bucket_key, model_version, metric_type).
 * Upserts are idempotent — re-running the batch crawl on the same day
 * overwrites values with fresher data.
 *
 * Accepts optional PDO injection for test isolation (SQLite in-memory).
 *
 * Table DDL: database/migrations/012_create_peer_medians.sql
 */
class PeerMedianRepository
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
     * Insert or update a peer median.
     *
     * @param string     $level         'industry' | 'sector'
     * @param string     $bucketKey     Industry or sector name
     * @param string|null $parentSector Owning sector (null for sector-level rows)
     * @param string     $modelVersion  e.g. '3.0'
     * @param string     $metricType    'ev_fcf' | 'ev_sales' | 'gm'
     * @param float|null $medianValue   Computed median; null when sample_count < N
     * @param int        $sampleCount   Number of tickers that contributed
     */
    public function upsertMedian(
        string  $level,
        string  $bucketKey,
        ?string $parentSector,
        string  $modelVersion,
        string  $metricType,
        ?float  $medianValue,
        int     $sampleCount
    ): void {
        $computedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare('
                INSERT INTO peer_medians
                    (level, bucket_key, parent_sector, model_version, metric_type,
                     median_value, sample_count, computed_at)
                VALUES
                    (:level, :bucket_key, :parent_sector, :model_version, :metric_type,
                     :median_value, :sample_count, :computed_at)
            ');
            $stmt->execute([
                ':level'         => $level,
                ':bucket_key'    => $bucketKey,
                ':parent_sector' => $parentSector,
                ':model_version' => $modelVersion,
                ':metric_type'   => $metricType,
                ':median_value'  => $medianValue,
                ':sample_count'  => $sampleCount,
                ':computed_at'   => $computedAt,
            ]);
        } catch (PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');

            if (!$isDup) {
                error_log(sprintf(
                    'PeerMedianRepository::upsertMedian failed (%s/%s/%s/%s): %s',
                    $level, $bucketKey, $modelVersion, $metricType, $msg
                ));
                return;
            }

            // Duplicate → update in place.
            try {
                $upd = $this->db->prepare('
                    UPDATE peer_medians
                    SET parent_sector = :parent_sector,
                        median_value  = :median_value,
                        sample_count  = :sample_count,
                        computed_at   = :computed_at
                    WHERE level         = :level
                      AND bucket_key    = :bucket_key
                      AND model_version = :model_version
                      AND metric_type   = :metric_type
                ');
                $upd->execute([
                    ':level'         => $level,
                    ':bucket_key'    => $bucketKey,
                    ':parent_sector' => $parentSector,
                    ':model_version' => $modelVersion,
                    ':metric_type'   => $metricType,
                    ':median_value'  => $medianValue,
                    ':sample_count'  => $sampleCount,
                    ':computed_at'   => $computedAt,
                ]);
            } catch (PDOException $ue) {
                error_log(sprintf(
                    'PeerMedianRepository::update failed (%s/%s/%s/%s): %s',
                    $level, $bucketKey, $modelVersion, $metricType, $ue->getMessage()
                ));
            }
        }
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Fetch a single median row for a given bucket, version, and metric.
     *
     * Returns ['median' => float|null, 'sample_count' => int] or null when
     * no row exists yet (cold-start — caller must fall back to static benchmark).
     *
     * @return array{median: float|null, sample_count: int}|null
     */
    public function findByBucket(
        string $level,
        string $bucketKey,
        string $modelVersion,
        string $metricType
    ): ?array {
        $stmt = $this->db->prepare('
            SELECT median_value, sample_count
            FROM   peer_medians
            WHERE  level         = :level
              AND  bucket_key    = :bucket_key
              AND  model_version = :model_version
              AND  metric_type   = :metric_type
            LIMIT 1
        ');
        $stmt->execute([
            ':level'         => $level,
            ':bucket_key'    => $bucketKey,
            ':model_version' => $modelVersion,
            ':metric_type'   => $metricType,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'median'       => $row['median_value'] !== null ? (float) $row['median_value'] : null,
            'sample_count' => (int) $row['sample_count'],
        ];
    }

    /**
     * Fetch all rows for a given model version (useful for diagnostics / reports).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllByVersion(string $modelVersion): array
    {
        $stmt = $this->db->prepare('
            SELECT *
            FROM   peer_medians
            WHERE  model_version = :model_version
            ORDER  BY level, bucket_key, metric_type
        ');
        $stmt->execute([':model_version' => $modelVersion]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
