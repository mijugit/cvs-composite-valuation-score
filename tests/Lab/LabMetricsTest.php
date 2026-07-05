<?php

declare(strict_types=1);

namespace CVS\Tests\Lab;

use CVS\Lab\LabMetrics;
use PHPUnit\Framework\TestCase;

class LabMetricsTest extends TestCase
{
    // ------------------------------------------------------------------
    // totalReturnPct
    // ------------------------------------------------------------------

    public function testTotalReturnPctComputesPercentGainFromFirstToLastPoint(): void
    {
        $series = [
            ['date' => '2026-07-02', 'nav' => 100000.0],
            ['date' => '2026-07-03', 'nav' => 101000.0],
            ['date' => '2026-07-06', 'nav' => 105000.0],
        ];

        $this->assertSame(5.0, LabMetrics::totalReturnPct($series));
    }

    public function testTotalReturnPctHandlesLoss(): void
    {
        $series = [
            ['date' => '2026-07-02', 'nav' => 100000.0],
            ['date' => '2026-07-06', 'nav' => 95000.0],
        ];

        $this->assertSame(-5.0, LabMetrics::totalReturnPct($series));
    }

    public function testTotalReturnPctNullWhenFewerThanTwoPoints(): void
    {
        $this->assertNull(LabMetrics::totalReturnPct([]));
        $this->assertNull(LabMetrics::totalReturnPct([['date' => '2026-07-02', 'nav' => 100000.0]]));
    }

    // ------------------------------------------------------------------
    // maxDrawdownPct
    // ------------------------------------------------------------------

    public function testMaxDrawdownPctFindsWorstPeakToTroughDecline(): void
    {
        $series = [
            ['date' => '2026-07-02', 'nav' => 100000.0],
            ['date' => '2026-07-03', 'nav' => 110000.0], // new peak
            ['date' => '2026-07-06', 'nav' => 88000.0],  // -20% from peak
            ['date' => '2026-07-07', 'nav' => 95000.0],  // recovers, still below peak
        ];

        $this->assertSame(20.0, LabMetrics::maxDrawdownPct($series));
    }

    public function testMaxDrawdownPctZeroForMonotonicallyRisingSeries(): void
    {
        $series = [
            ['date' => '2026-07-02', 'nav' => 100000.0],
            ['date' => '2026-07-03', 'nav' => 101000.0],
            ['date' => '2026-07-06', 'nav' => 105000.0],
        ];

        $this->assertSame(0.0, LabMetrics::maxDrawdownPct($series));
    }

    public function testMaxDrawdownPctNullWhenFewerThanTwoPoints(): void
    {
        $this->assertNull(LabMetrics::maxDrawdownPct([]));
    }

    // ------------------------------------------------------------------
    // normaliseToBase100
    // ------------------------------------------------------------------

    public function testNormaliseToBase100RebasesFirstPointTo100UsingNavKey(): void
    {
        $series = [
            ['date' => '2026-07-02', 'nav' => 50000.0],
            ['date' => '2026-07-03', 'nav' => 55000.0],
        ];

        $this->assertSame(
            [
                ['date' => '2026-07-02', 'value' => 100.0],
                ['date' => '2026-07-03', 'value' => 110.0],
            ],
            LabMetrics::normaliseToBase100($series)
        );
    }

    public function testNormaliseToBase100SupportsCustomValueKey(): void
    {
        $series = [
            ['date' => '2026-07-02', 'value' => 200.0],
            ['date' => '2026-07-03', 'value' => 180.0],
        ];

        $this->assertSame(
            [
                ['date' => '2026-07-02', 'value' => 100.0],
                ['date' => '2026-07-03', 'value' => 90.0],
            ],
            LabMetrics::normaliseToBase100($series, 'value')
        );
    }

    public function testNormaliseToBase100EmptyForEmptyInput(): void
    {
        $this->assertSame([], LabMetrics::normaliseToBase100([]));
    }
}
