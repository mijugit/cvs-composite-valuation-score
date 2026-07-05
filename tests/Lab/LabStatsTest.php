<?php

declare(strict_types=1);

namespace CVS\Tests\Lab;

use CVS\Lab\LabStats;
use PHPUnit\Framework\TestCase;

class LabStatsTest extends TestCase
{
    private const STATS_CFG = ['bootstrap_iterations' => 500, 'bootstrap_seed' => 42, 'min_sessions' => 5];

    // ------------------------------------------------------------------
    // dailyReturns
    // ------------------------------------------------------------------

    public function testDailyReturnsComputesLogReturnsAndIsOneShorterThanInput(): void
    {
        $series = [
            ['date' => '2026-07-01', 'nav' => 100.0],
            ['date' => '2026-07-02', 'nav' => 110.0],
            ['date' => '2026-07-03', 'nav' => 99.0],
        ];

        $returns = LabStats::dailyReturns($series);

        $this->assertCount(2, $returns);
        $this->assertSame('2026-07-02', $returns[0]['date']);
        $this->assertEqualsWithDelta(log(110.0 / 100.0), $returns[0]['ret'], 1e-12);
        $this->assertEqualsWithDelta(log(99.0 / 110.0), $returns[1]['ret'], 1e-12);
    }

    public function testDailyReturnsEmptyForSinglePointSeries(): void
    {
        $this->assertSame([], LabStats::dailyReturns([['date' => '2026-07-01', 'nav' => 100.0]]));
    }

    // ------------------------------------------------------------------
    // pairedDiffs
    // ------------------------------------------------------------------

    public function testPairedDiffsSubtractsReferenceOnMatchingDatesOnly(): void
    {
        $variant = [
            ['date' => '2026-07-02', 'ret' => 0.02],
            ['date' => '2026-07-03', 'ret' => 0.01],
            ['date' => '2026-07-06', 'ret' => 0.03], // no matching reference date
        ];
        $reference = [
            ['date' => '2026-07-02', 'ret' => 0.01],
            ['date' => '2026-07-03', 'ret' => 0.015],
        ];

        $diffs = LabStats::pairedDiffs($variant, $reference);

        $this->assertCount(2, $diffs);
        $this->assertEqualsWithDelta(0.01, $diffs[0], 1e-12);
        $this->assertEqualsWithDelta(-0.005, $diffs[1], 1e-12);
    }

    // ------------------------------------------------------------------
    // bootstrapCiOfMeanDiff
    // ------------------------------------------------------------------

    public function testBootstrapCiIsDeterministicAcrossRepeatedCalls(): void
    {
        $diffs = [0.01, -0.02, 0.015, 0.005, -0.01, 0.02, 0.0, 0.008];

        $ci1 = LabStats::bootstrapCiOfMeanDiff($diffs, 500, 42);
        $ci2 = LabStats::bootstrapCiOfMeanDiff($diffs, 500, 42);

        $this->assertSame($ci1, $ci2);
    }

    public function testBootstrapCiEntirelyPositiveWhenAllDiffsPositive(): void
    {
        $diffs = [0.01, 0.02, 0.015, 0.012, 0.018, 0.011, 0.014, 0.016];

        [$lo, $hi] = LabStats::bootstrapCiOfMeanDiff($diffs, 500, 42);

        $this->assertGreaterThan(0.0, $lo);
        $this->assertGreaterThan(0.0, $hi);
    }

    public function testBootstrapCiZeroWidthForEmptyDiffs(): void
    {
        $this->assertSame([0.0, 0.0], LabStats::bootstrapCiOfMeanDiff([], 500, 42));
    }

    // ------------------------------------------------------------------
    // hypothesisStatus
    // ------------------------------------------------------------------

    public function testHypothesisStatusTooEarlyWhenBelowMinSessions(): void
    {
        $status = LabStats::hypothesisStatus([0.01, 0.02], 3, ['claim' => '', 'source' => '', 'versus' => 'P1', 'direction' => 'above'], self::STATS_CFG);

        $this->assertSame('too_early', $status);
    }

    public function testHypothesisStatusInconclusiveWhenCiStraddlesZero(): void
    {
        $status = LabStats::hypothesisStatus([-0.01, 0.02], 10, ['claim' => '', 'source' => '', 'versus' => 'P1', 'direction' => 'above'], self::STATS_CFG);

        $this->assertSame('inconclusive', $status);
    }

    public function testHypothesisStatusSupportedWhenCiMatchesAboveDirection(): void
    {
        $status = LabStats::hypothesisStatus([0.005, 0.02], 10, ['claim' => '', 'source' => '', 'versus' => 'P1', 'direction' => 'above'], self::STATS_CFG);

        $this->assertSame('supported', $status);
    }

    public function testHypothesisStatusRefutedWhenCiOpposesAboveDirection(): void
    {
        $status = LabStats::hypothesisStatus([-0.02, -0.005], 10, ['claim' => '', 'source' => '', 'versus' => 'P1', 'direction' => 'above'], self::STATS_CFG);

        $this->assertSame('refuted', $status);
    }

    public function testHypothesisStatusSupportedWhenCiMatchesBelowDirection(): void
    {
        $status = LabStats::hypothesisStatus([-0.02, -0.005], 10, ['claim' => '', 'source' => '', 'versus' => 'P1', 'direction' => 'below'], self::STATS_CFG);

        $this->assertSame('supported', $status);
    }

    public function testHypothesisStatusRefutedWhenCiOpposesBelowDirection(): void
    {
        $status = LabStats::hypothesisStatus([0.005, 0.02], 10, ['claim' => '', 'source' => '', 'versus' => 'P1', 'direction' => 'below'], self::STATS_CFG);

        $this->assertSame('refuted', $status);
    }
}
