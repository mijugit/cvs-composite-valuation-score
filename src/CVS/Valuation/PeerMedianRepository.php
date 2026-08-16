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

        // Append snapshot to history regardless of insert/update path.
        $this->insertHistory($level, $bucketKey, $parentSector, $modelVersion, $metricType, $medianValue, $sampleCount);
    }

    /**
     * Append one row to peer_medians_history (best-effort — errors are logged, never rethrown).
     */
    private function insertHistory(
        string  $level,
        string  $bucketKey,
        ?string $parentSector,
        string  $modelVersion,
        string  $metricType,
        ?float  $medianValue,
        int     $sampleCount
    ): void {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO peer_medians_history
                    (level, bucket_key, parent_sector, model_version, metric_type,
                     median_value, sample_count, snapshotted_at)
                VALUES
                    (:level, :bucket_key, :parent_sector, :model_version, :metric_type,
                     :median_value, :sample_count, :snapshotted_at)
            ');
            $stmt->execute([
                ':level'          => $level,
                ':bucket_key'     => $bucketKey,
                ':parent_sector'  => $parentSector,
                ':model_version'  => $modelVersion,
                ':metric_type'    => $metricType,
                ':median_value'   => $medianValue,
                ':sample_count'   => $sampleCount,
                ':snapshotted_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            error_log(sprintf(
                'PeerMedianRepository::insertHistory failed (%s/%s/%s/%s): %s',
                $level, $bucketKey, $modelVersion, $metricType, $e->getMessage()
            ));
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
    /**
     * Sample size behind every industry bucket, keyed by industry name.
     *
     * One bulk read for callers that need to know, across many tickers at once,
     * whether a company actually has peers — MedianResolver answers that per
     * ticker and hits the DB each time. A bucket below min_sample_count silently
     * falls back to the sector median, which is how ASB.WA (the only electronics
     * distributor in the universe, n=1) came to be judged against software
     * multiples and ranked second overall on a comparison that meant nothing.
     *
     * Pass every metric the bucket's members might be scored on and the deepest
     * one wins. Asking about a single metric answers the wrong question whenever
     * the bucket's companies do not all use it: `Banks - Regional` has 22
     * companies on `pb` and none on `ev_fcf`, because a bank has no meaningful
     * free cash flow (variant C) — reading only `ev_fcf` reports a working
     * bucket as empty. Same trap as variant B and `ev_sales`.
     *
     * @param string|list<string> $metricType One metric, or several to merge.
     * @return array<string, int> industry => sample_count
     */
    public function findIndustrySampleCounts(string $modelVersion, string|array $metricType): array
    {
        $metrics = is_array($metricType) ? array_values($metricType) : [$metricType];
        if ($metrics === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($metrics), '?'));
        $stmt = $this->db->prepare("
            SELECT bucket_key, sample_count
            FROM   peer_medians
            WHERE  level         = 'industry'
              AND  model_version = ?
              AND  metric_type   IN ($placeholders)
        ");
        $stmt->execute(array_merge([$modelVersion], $metrics));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key   = (string) $row['bucket_key'];
            $count = (int) $row['sample_count'];
            // Deepest metric wins: the resolver needs only one bucket to clear
            // min_sample_count for the comparison to be a real one.
            if ($count > ($out[$key] ?? 0)) {
                $out[$key] = $count;
            }
        }
        return $out;
    }

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
     * Aggregate peer-median rows per (level, bucket_key) for the admin sectors panel.
     *
     * Returns:
     * [
     *   'sector'   => [ sectorName   => ['computed_at'=>..., 'sample_count'=>..., 'ev_fcf'=>..., 'ev_sales'=>..., 'gm'=>...] ],
     *   'industry' => [ industryName => ['parent_sector'=>..., ...same metrics...] ],
     * ]
     *
     * @return array{sector: array<string, array<string, mixed>>, industry: array<string, array<string, mixed>>}
     */
    public function findSectorStats(string $modelVersion): array
    {
        $result = ['sector' => [], 'industry' => []];

        foreach (['sector', 'industry'] as $level) {
            $stmt = $this->db->prepare('
                SELECT bucket_key, parent_sector, metric_type, median_value, sample_count, computed_at
                FROM   peer_medians
                WHERE  level         = :level
                  AND  model_version = :model_version
                ORDER  BY bucket_key, metric_type
            ');
            $stmt->execute([':level' => $level, ':model_version' => $modelVersion]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $key = (string) $row['bucket_key'];
                if (!isset($result[$level][$key])) {
                    $result[$level][$key] = [
                        'computed_at'   => $row['computed_at'],
                        'sample_count'  => (int) $row['sample_count'],
                        'ev_fcf'        => null,
                        'ev_sales'      => null,
                        'gm'            => null,
                    ];
                    if ($level === 'industry') {
                        $result[$level][$key]['parent_sector'] = (string) ($row['parent_sector'] ?? '');
                    }
                }
                $metric = (string) $row['metric_type'];
                if (in_array($metric, ['ev_fcf', 'ev_sales', 'gm'], true)) {
                    $result[$level][$key][$metric] = $row['median_value'] !== null
                        ? (float) $row['median_value']
                        : null;
                }
                // Use the latest computed_at across metrics for this bucket.
                if ($row['computed_at'] > $result[$level][$key]['computed_at']) {
                    $result[$level][$key]['computed_at'] = $row['computed_at'];
                }
            }
        }

        return $result;
    }

    /**
     * Fetch history snapshots for a given bucket and model version.
     *
     * Rows are grouped by calendar date (DATE(snapshotted_at)). When multiple
     * refreshes occur on the same day, the last-seen value per metric wins
     * (rows arrive ordered ASC so later overwrites earlier in the pivot).
     *
     * @return array{labels: list<string>, ev_fcf: list<float|null>, ev_sales: list<float|null>, gm: list<float|null>}
     */
    public function findHistory(string $level, string $bucketKey, string $modelVersion): array
    {
        $stmt = $this->db->prepare('
            SELECT DATE(snapshotted_at) AS snap_date, metric_type, median_value
            FROM   peer_medians_history
            WHERE  level         = :level
              AND  bucket_key    = :bucket_key
              AND  model_version = :model_version
            ORDER  BY snapshotted_at ASC
        ');
        $stmt->execute([
            ':level'         => $level,
            ':bucket_key'    => $bucketKey,
            ':model_version' => $modelVersion,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pivot: collect all dates, map each metric — last value per day wins.
        $byDate = [];
        foreach ($rows as $row) {
            $date   = (string) $row['snap_date'];
            $metric = (string) $row['metric_type'];
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['ev_fcf' => null, 'ev_sales' => null, 'gm' => null];
            }
            if (in_array($metric, ['ev_fcf', 'ev_sales', 'gm'], true)) {
                $byDate[$date][$metric] = $row['median_value'] !== null ? (float) $row['median_value'] : null;
            }
        }

        $result = ['labels' => [], 'ev_fcf' => [], 'ev_sales' => [], 'gm' => []];
        foreach ($byDate as $date => $metrics) {
            $result['labels'][]   = $date;
            $result['ev_fcf'][]   = $metrics['ev_fcf'];
            $result['ev_sales'][] = $metrics['ev_sales'];
            $result['gm'][]       = $metrics['gm'];
        }

        return $result;
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
