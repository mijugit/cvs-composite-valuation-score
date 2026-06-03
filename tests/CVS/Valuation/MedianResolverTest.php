<?php

declare(strict_types=1);

namespace CVS\Tests\CVS\Valuation;

use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerMedianRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class MedianResolverTest extends TestCase
{
    private PDO $db;
    private PeerMedianRepository $repo;

    private array $benchmarks = [
        'Communication Services' => [
            'median_ev_fcf' => 22.0, 'median_ev_sales' => 4.0,
            'median_gm' => 50, 'max_growth' => 25,
        ],
        'DEFAULT' => [
            'median_ev_fcf' => 20.0, 'median_ev_sales' => 3.0,
            'median_gm' => 40, 'max_growth' => 20,
        ],
    ];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE peer_medians (
            id INTEGER PRIMARY KEY,
            level TEXT NOT NULL,
            bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL,
            model_version TEXT NOT NULL,
            metric_type TEXT NOT NULL,
            median_value REAL NULL,
            sample_count INTEGER NOT NULL DEFAULT 0,
            computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(level, bucket_key, model_version, metric_type)
        )');
        $this->repo = new PeerMedianRepository($this->db);
    }

    private function resolver(int $minN = 5): MedianResolver
    {
        return new MedianResolver($this->repo, $this->benchmarks, $minN, '3.0');
    }

    // ------------------------------------------------------------------
    // Tier 1: subsector (sample_count >= N)
    // ------------------------------------------------------------------

    public function test_resolve_uses_subsector_when_sample_sufficient(): void
    {
        $this->repo->upsertMedian('industry', 'Electronic Gaming & Multimedia', 'Communication Services', '3.0', 'ev_fcf', 18.5, 7);
        $this->repo->upsertMedian('sector',   'Communication Services', null, '3.0', 'ev_fcf', 22.0, 35);

        $r = $this->resolver(5)->resolve('Electronic Gaming & Multimedia', 'Communication Services', 'ev_fcf');

        $this->assertSame('subsector', $r->source);
        $this->assertEqualsWithDelta(18.5, $r->value, 0.001);
        $this->assertSame(7, $r->sampleCount);
        $this->assertTrue($r->isSubsector());
    }

    // ------------------------------------------------------------------
    // Tier 2: sector fallback (subsector too thin)
    // ------------------------------------------------------------------

    public function test_resolve_falls_back_to_sector_when_sample_below_threshold(): void
    {
        $this->repo->upsertMedian('industry', 'Electronic Gaming & Multimedia', 'Communication Services', '3.0', 'ev_fcf', 18.5, 3); // 3 < N=5
        $this->repo->upsertMedian('sector',   'Communication Services', null, '3.0', 'ev_fcf', 22.0, 35);

        $r = $this->resolver(5)->resolve('Electronic Gaming & Multimedia', 'Communication Services', 'ev_fcf');

        $this->assertSame('sector_fallback', $r->source);
        $this->assertEqualsWithDelta(22.0, $r->value, 0.001);
    }

    public function test_resolve_falls_back_when_subsector_missing(): void
    {
        // No industry row — jump straight to sector
        $this->repo->upsertMedian('sector', 'Communication Services', null, '3.0', 'ev_fcf', 22.0, 35);

        $r = $this->resolver(5)->resolve('Entertainment', 'Communication Services', 'ev_fcf');

        $this->assertSame('sector_fallback', $r->source);
        $this->assertEqualsWithDelta(22.0, $r->value, 0.001);
    }

    // ------------------------------------------------------------------
    // Tier 3: cold-start (no DB rows yet)
    // ------------------------------------------------------------------

    public function test_resolve_cold_start_uses_static_benchmark(): void
    {
        // Empty DB → cold-start
        $r = $this->resolver(5)->resolve('Electronic Gaming & Multimedia', 'Communication Services', 'ev_fcf');

        $this->assertSame('cold_start', $r->source);
        $this->assertEqualsWithDelta(22.0, $r->value, 0.001); // from static benchmarks
        $this->assertSame(0, $r->sampleCount);
    }

    public function test_resolve_cold_start_default_sector_when_unknown(): void
    {
        $r = $this->resolver()->resolve('Unknown Sub', 'Unknown Sector', 'ev_fcf');

        $this->assertSame('cold_start', $r->source);
        $this->assertEqualsWithDelta(20.0, $r->value, 0.001); // DEFAULT benchmark
    }

    // ------------------------------------------------------------------
    // resolveSector (anchor)
    // ------------------------------------------------------------------

    public function test_resolve_sector_returns_sector_level(): void
    {
        $this->repo->upsertMedian('industry', 'Entertainment', 'Communication Services', '3.0', 'ev_fcf', 18.0, 10);
        $this->repo->upsertMedian('sector',   'Communication Services', null, '3.0', 'ev_fcf', 22.0, 35);

        $r = $this->resolver()->resolveSector('Communication Services', 'ev_fcf');

        $this->assertSame('sector_fallback', $r->source);
        $this->assertEqualsWithDelta(22.0, $r->value, 0.001);
        $this->assertFalse($r->isSubsector());
    }

    public function test_resolve_sector_cold_start_when_no_sector_row(): void
    {
        $r = $this->resolver()->resolveSector('Communication Services', 'ev_fcf');

        $this->assertSame('cold_start', $r->source);
        $this->assertEqualsWithDelta(22.0, $r->value, 0.001);
    }

    // ------------------------------------------------------------------
    // Missing
    // ------------------------------------------------------------------

    public function test_resolve_missing_when_no_benchmark_and_no_db(): void
    {
        $resolver = new MedianResolver($this->repo, [], 5, '3.0'); // no benchmarks
        $r = $resolver->resolve('Some Sub', 'Unknown', 'ev_fcf');

        $this->assertSame('missing', $r->source);
        $this->assertNull($r->value);
        $this->assertFalse($r->isValid());
    }

    // ------------------------------------------------------------------
    // Version isolation
    // ------------------------------------------------------------------

    public function test_does_not_use_different_model_version(): void
    {
        $this->repo->upsertMedian('industry', 'Entertainment', 'Communication Services', '2.0', 'ev_fcf', 15.0, 10);
        // No row for v3.0 → cold-start
        $r = $this->resolver()->resolve('Entertainment', 'Communication Services', 'ev_fcf');

        $this->assertSame('cold_start', $r->source);
    }
}
