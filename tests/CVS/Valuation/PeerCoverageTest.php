<?php

declare(strict_types=1);

namespace CVS\Tests\CVS\Valuation;

use CVS\CVS\Valuation\PeerCoverage;
use CVS\CVS\Valuation\PeerMedianRepository;
use PHPUnit\Framework\TestCase;

class PeerCoverageTest extends TestCase
{
    private function coverage(): PeerCoverage
    {
        return new PeerCoverage(
            [
                'Electronics & Computer Distribution' => 7,  // after peers were added
                'Semiconductors'                      => 42,
                'Specialty Retail'                    => 1,  // the ASB.WA situation
                'Drug Manufacturers'                  => 4,  // just under the threshold
            ],
            5
        );
    }

    public function testDeepBucketIsNotThin(): void
    {
        $this->assertFalse($this->coverage()->isThin('Semiconductors'));
        $this->assertFalse($this->coverage()->isThin('Electronics & Computer Distribution'));
    }

    public function testBucketBelowThresholdIsThin(): void
    {
        $this->assertTrue($this->coverage()->isThin('Specialty Retail'));
        $this->assertTrue($this->coverage()->isThin('Drug Manufacturers'));
    }

    public function testExactlyAtThresholdIsNotThin(): void
    {
        $coverage = new PeerCoverage(['X' => 5], 5);

        $this->assertFalse($coverage->isThin('X'));
    }

    public function testUnknownIndustryIsThin(): void
    {
        $this->assertTrue($this->coverage()->isThin('Cheese Futures'));
    }

    /** No industry at all ends at the sector too — same situation, same treatment. */
    public function testNullOrEmptyIndustryIsThin(): void
    {
        $this->assertTrue($this->coverage()->isThin(null));
        $this->assertTrue($this->coverage()->isThin(''));
    }

    public function testSampleCountIsReportedForDisplay(): void
    {
        $this->assertSame(7, $this->coverage()->sampleCount('Electronics & Computer Distribution'));
        $this->assertSame(1, $this->coverage()->sampleCount('Specialty Retail'));
        $this->assertSame(0, $this->coverage()->sampleCount('Cheese Futures'));
        $this->assertSame(0, $this->coverage()->sampleCount(null));
    }

    // -----------------------------------------------------------------------
    // Persisted valuation_source (migration 036) — authoritative when present
    // -----------------------------------------------------------------------

    public function testPersistedSubsectorSourceMeansNotThin(): void
    {
        // Sample counts say thin, but the pillar recorded that it DID resolve a
        // subsector median. The recorded verdict wins.
        $this->assertFalse($this->coverage()->isThin('Specialty Retail', 'subsector'));
    }

    public function testPersistedFallbackSourceMeansThin(): void
    {
        // Sample counts say fine, but the pillar recorded a fallback — e.g. the
        // company was scored on EV/Sales, whose bucket is the thin one.
        $this->assertTrue($this->coverage()->isThin('Semiconductors', 'sector_fallback'));
        $this->assertTrue($this->coverage()->isThin('Semiconductors', 'cold_start'));
    }

    public function testEmptySourceFallsBackToSampleCounts(): void
    {
        // Pre-migration rows carry no source; the estimate is all we have.
        $this->assertFalse($this->coverage()->isThin('Semiconductors', null));
        $this->assertFalse($this->coverage()->isThin('Semiconductors', ''));
        $this->assertTrue($this->coverage()->isThin('Specialty Retail', null));
    }

    public function testThresholdIsExposed(): void
    {
        $this->assertSame(5, $this->coverage()->minSampleCount());
    }

    /**
     * The regression that started this: ASB.WA was the only electronics
     * distributor in the universe, so its bucket held exactly itself and the
     * resolver silently benchmarked it against the Technology sector.
     */
    public function testSoleMemberOfItsIndustryIsThin(): void
    {
        $coverage = new PeerCoverage(['Electronics & Computer Distribution' => 1], 5);

        $this->assertTrue($coverage->isThin('Electronics & Computer Distribution'));
        $this->assertSame(1, $coverage->sampleCount('Electronics & Computer Distribution'));
    }

    /**
     * The factory exists so no caller picks a metric list by hand. Two of the
     * four did, and both were still asking about ev_fcf alone after variants C
     * and D shipped — which reads a bank's or a REIT's bucket as empty.
     */
    public function testFromConfigCountsEveryValuationMetric(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE peer_medians (
            id INTEGER PRIMARY KEY, level TEXT NOT NULL, bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL, model_version TEXT NOT NULL, metric_type TEXT NOT NULL,
            median_value REAL NULL, sample_count INTEGER NOT NULL DEFAULT 0,
            computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(level, bucket_key, model_version, metric_type)
        )');
        $pdo->exec('CREATE TABLE peer_medians_history (
            id INTEGER PRIMARY KEY, level TEXT NOT NULL, bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL, model_version TEXT NOT NULL, metric_type TEXT NOT NULL,
            median_value REAL NULL, sample_count INTEGER NOT NULL DEFAULT 0,
            snapshotted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $repo = new PeerMedianRepository($pdo);
        // A bank bucket: deep on price/book, absent from ev_fcf entirely.
        $repo->upsertMedian('industry', 'Banks - Regional', 'Financial Services', '4.0', 'pb', 1.74, 22);

        $coverage = PeerCoverage::fromConfig(
            ['model_version' => '4.0', 'peer_group' => ['min_sample_count' => 5]],
            $repo
        );

        $this->assertFalse(
            $coverage->isThin('Banks - Regional'),
            'a bucket with 22 peers on its own metric must not read as peerless'
        );
        $this->assertSame(22, $coverage->sampleCount('Banks - Regional'));
    }
}
