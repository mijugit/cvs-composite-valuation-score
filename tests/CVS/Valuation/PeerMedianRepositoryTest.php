<?php

declare(strict_types=1);

namespace CVS\Tests\CVS\Valuation;

use CVS\CVS\Valuation\PeerMedianRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PeerMedianRepository — SQLite in-memory.
 */
class PeerMedianRepositoryTest extends TestCase
{
    private PDO $db;
    private PeerMedianRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Bootstrap tables (mirror 012 and 020 migrations for SQLite).
        $this->db->exec('CREATE TABLE peer_medians (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            level        TEXT NOT NULL,
            bucket_key   TEXT NOT NULL,
            parent_sector TEXT NULL,
            model_version TEXT NOT NULL,
            metric_type  TEXT NOT NULL,
            median_value REAL NULL,
            sample_count INTEGER NOT NULL DEFAULT 0,
            computed_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (level, bucket_key, model_version, metric_type)
        )');

        $this->db->exec('CREATE TABLE peer_medians_history (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            level          TEXT NOT NULL,
            bucket_key     TEXT NOT NULL,
            parent_sector  TEXT NULL,
            model_version  TEXT NOT NULL,
            metric_type    TEXT NOT NULL,
            median_value   REAL NULL,
            sample_count   INTEGER NOT NULL DEFAULT 0,
            snapshotted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->repo = new PeerMedianRepository($this->db);
    }

    // ------------------------------------------------------------------
    // findByBucket — cold start
    // ------------------------------------------------------------------

    public function test_find_returns_null_when_no_row(): void
    {
        $result = $this->repo->findByBucket('industry', 'Electronic Gaming', '3.0', 'ev_fcf');
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // upsertMedian — insert
    // ------------------------------------------------------------------

    public function test_insert_and_find_industry_row(): void
    {
        $this->repo->upsertMedian('industry', 'Electronic Gaming & Multimedia', 'Communication Services', '3.0', 'ev_fcf', 18.5, 7);

        $row = $this->repo->findByBucket('industry', 'Electronic Gaming & Multimedia', '3.0', 'ev_fcf');

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(18.5, $row['median'], 0.001);
        $this->assertSame(7, $row['sample_count']);
    }

    public function test_insert_sector_row_with_null_parent(): void
    {
        $this->repo->upsertMedian('sector', 'Communication Services', null, '3.0', 'ev_fcf', 22.0, 35);

        $row = $this->repo->findByBucket('sector', 'Communication Services', '3.0', 'ev_fcf');

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(22.0, $row['median'], 0.001);
        $this->assertSame(35, $row['sample_count']);
    }

    public function test_insert_null_median_when_sample_below_threshold(): void
    {
        $this->repo->upsertMedian('industry', 'Tiny Niche', null, '3.0', 'ev_fcf', null, 2);

        $row = $this->repo->findByBucket('industry', 'Tiny Niche', '3.0', 'ev_fcf');

        $this->assertNotNull($row);
        $this->assertNull($row['median']);
        $this->assertSame(2, $row['sample_count']);
    }

    // ------------------------------------------------------------------
    // upsertMedian — update (idempotent re-run)
    // ------------------------------------------------------------------

    public function test_upsert_overwrites_existing_row(): void
    {
        $this->repo->upsertMedian('industry', 'Entertainment', 'Communication Services', '3.0', 'ev_fcf', 25.0, 10);
        $this->repo->upsertMedian('industry', 'Entertainment', 'Communication Services', '3.0', 'ev_fcf', 26.5, 12);

        $row = $this->repo->findByBucket('industry', 'Entertainment', '3.0', 'ev_fcf');

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(26.5, $row['median'], 0.001);
        $this->assertSame(12, $row['sample_count']);
    }

    public function test_upsert_different_metrics_stored_separately(): void
    {
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf',   32.0, 50);
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_sales',  8.0, 50);
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'gm',       55.0, 50);

        $evFcf   = $this->repo->findByBucket('sector', 'Technology', '3.0', 'ev_fcf');
        $evSales = $this->repo->findByBucket('sector', 'Technology', '3.0', 'ev_sales');
        $gm      = $this->repo->findByBucket('sector', 'Technology', '3.0', 'gm');

        $this->assertEqualsWithDelta(32.0, $evFcf['median'],   0.001);
        $this->assertEqualsWithDelta(8.0,  $evSales['median'], 0.001);
        $this->assertEqualsWithDelta(55.0, $gm['median'],      0.001);
    }

    // ------------------------------------------------------------------
    // findAllByVersion
    // ------------------------------------------------------------------

    public function test_find_all_by_version_returns_only_matching(): void
    {
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf', 32.0, 50);
        $this->repo->upsertMedian('sector', 'Technology', null, '2.0', 'ev_fcf', 30.0, 40); // different version

        $rows = $this->repo->findAllByVersion('3.0');

        $this->assertCount(1, $rows);
        $this->assertSame('3.0', $rows[0]['model_version']);
    }

    // ------------------------------------------------------------------
    // findSectorStats
    // ------------------------------------------------------------------

    public function test_find_sector_stats_returns_empty_on_no_data(): void
    {
        $stats = $this->repo->findSectorStats('3.0');

        $this->assertSame([], $stats['sector']);
        $this->assertSame([], $stats['industry']);
    }

    public function test_find_sector_stats_pivots_metrics_per_bucket(): void
    {
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf',  32.0, 50);
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_sales', 8.0, 50);
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'gm',      55.0, 50);

        $stats = $this->repo->findSectorStats('3.0');

        $this->assertArrayHasKey('Technology', $stats['sector']);
        $row = $stats['sector']['Technology'];
        $this->assertEqualsWithDelta(32.0, $row['ev_fcf'],  0.001);
        $this->assertEqualsWithDelta(8.0,  $row['ev_sales'], 0.001);
        $this->assertEqualsWithDelta(55.0, $row['gm'],      0.001);
        $this->assertSame(50, $row['sample_count']);
    }

    public function test_find_sector_stats_industry_includes_parent_sector(): void
    {
        $this->repo->upsertMedian('industry', 'Software—Application', 'Technology', '3.0', 'ev_fcf', 35.0, 20);

        $stats = $this->repo->findSectorStats('3.0');

        $this->assertArrayHasKey('Software—Application', $stats['industry']);
        $this->assertSame('Technology', $stats['industry']['Software—Application']['parent_sector']);
    }

    public function test_find_sector_stats_isolates_by_model_version(): void
    {
        $this->repo->upsertMedian('sector', 'Healthcare', null, '3.0', 'ev_fcf', 20.0, 15);
        $this->repo->upsertMedian('sector', 'Healthcare', null, '2.0', 'ev_fcf', 18.0, 10);

        $stats = $this->repo->findSectorStats('3.0');

        $this->assertArrayHasKey('Healthcare', $stats['sector']);
        $this->assertEqualsWithDelta(20.0, $stats['sector']['Healthcare']['ev_fcf'], 0.001);
    }

    public function test_find_sector_stats_handles_null_median(): void
    {
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf', null, 2);

        $stats = $this->repo->findSectorStats('3.0');

        $this->assertNull($stats['sector']['Technology']['ev_fcf']);
    }

    // ------------------------------------------------------------------
    // Version isolation
    // ------------------------------------------------------------------

    public function test_versions_stored_independently(): void
    {
        $this->repo->upsertMedian('sector', 'Healthcare', null, '3.0', 'ev_fcf', 28.0, 20);
        $this->repo->upsertMedian('sector', 'Healthcare', null, '4.0', 'ev_fcf', 30.0, 25);

        $v3 = $this->repo->findByBucket('sector', 'Healthcare', '3.0', 'ev_fcf');
        $v4 = $this->repo->findByBucket('sector', 'Healthcare', '4.0', 'ev_fcf');

        $this->assertEqualsWithDelta(28.0, $v3['median'], 0.001);
        $this->assertEqualsWithDelta(30.0, $v4['median'], 0.001);
    }

    // ------------------------------------------------------------------
    // peer_medians_history — append-only write-path
    // ------------------------------------------------------------------

    public function test_upsert_appends_row_to_history(): void
    {
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf', 32.0, 50);

        $rows = $this->db->query(
            "SELECT * FROM peer_medians_history WHERE bucket_key = 'Technology' AND metric_type = 'ev_fcf'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('sector', $rows[0]['level']);
        $this->assertEqualsWithDelta(32.0, (float) $rows[0]['median_value'], 0.001);
        $this->assertSame(50, (int) $rows[0]['sample_count']);
    }

    public function test_repeated_upsert_appends_multiple_history_rows(): void
    {
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf', 32.0, 50);
        $this->repo->upsertMedian('sector', 'Technology', null, '3.0', 'ev_fcf', 33.5, 52);

        $count = $this->db->query(
            "SELECT COUNT(*) FROM peer_medians_history WHERE bucket_key = 'Technology' AND metric_type = 'ev_fcf'"
        )->fetchColumn();

        // peer_medians still has 1 row (upsert), history has 2 (append).
        $this->assertSame(2, (int) $count);

        $pm = $this->db->query(
            "SELECT COUNT(*) FROM peer_medians WHERE bucket_key = 'Technology' AND metric_type = 'ev_fcf'"
        )->fetchColumn();
        $this->assertSame(1, (int) $pm);
    }
}
