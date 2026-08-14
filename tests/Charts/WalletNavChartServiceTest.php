<?php

declare(strict_types=1);

namespace CVS\Tests\Charts;

use CVS\Charts\WalletNavChartService;
use PHPUnit\Framework\TestCase;

class WalletNavChartServiceTest extends TestCase
{
    public function testD0IsEarliestDateAcrossBothWallets(): void
    {
        $portfolio = [
            ['date' => '2026-06-01', 'value' => 10000.0],
            ['date' => '2026-06-02', 'value' => 10100.0],
        ];
        $llmFree = [
            ['date' => '2026-08-10', 'value' => 10000.0],
        ];

        $result = WalletNavChartService::build($portfolio, $llmFree, null, null);

        $this->assertSame('2026-06-01', $result['d0']);
    }

    public function testNormalisesEachWalletToItsOwnBase100(): void
    {
        $portfolio = [
            ['date' => '2026-06-01', 'value' => 10000.0],
            ['date' => '2026-06-02', 'value' => 10500.0],
        ];
        $llmFree = [
            ['date' => '2026-08-10', 'value' => 8000.0],
            ['date' => '2026-08-11', 'value' => 8400.0],
        ];

        $result = WalletNavChartService::build($portfolio, $llmFree, null, null);

        $this->assertEqualsWithDelta(100.0, $result['chartSeries']['LLM Bazowy'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(105.0, $result['chartSeries']['LLM Bazowy'][1]['value'], 0.001);
        $this->assertEqualsWithDelta(100.0, $result['chartSeries']['LLM Free'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(105.0, $result['chartSeries']['LLM Free'][1]['value'], 0.001);
    }

    public function testBothWalletsEmptyReturnsNullD0AndEmptyChart(): void
    {
        $result = WalletNavChartService::build([], [], null, null);

        $this->assertNull($result['d0']);
        $this->assertSame([], $result['chartSeries']);
    }

    public function testOneEmptyWalletStillProducesChartFromTheOther(): void
    {
        $llmFree = [
            ['date' => '2026-08-10', 'value' => 10000.0],
        ];

        $result = WalletNavChartService::build([], $llmFree, null, null);

        $this->assertSame('2026-08-10', $result['d0']);
        $this->assertSame([], $result['chartSeries']['LLM Bazowy']);
        $this->assertCount(1, $result['chartSeries']['LLM Free']);
    }

    public function testNullBenchmarkFetchIsOmittedFromChartSeries(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];

        $result = WalletNavChartService::build($portfolio, [], null, null);

        $this->assertArrayNotHasKey('S&P 500', $result['chartSeries']);
        $this->assertArrayNotHasKey('Nasdaq 100', $result['chartSeries']);
    }

    public function testBenchmarkIsRebasedFromItsValueAtD0NotItsOwnFetchWindow(): void
    {
        $portfolio = [
            ['date' => '2026-06-01', 'value' => 10000.0],
            ['date' => '2026-06-02', 'value' => 10100.0],
        ];
        // A full year of SPY history, but the wallet only starts 2026-06-01 —
        // the pre-d0 points must not become the 100 baseline.
        $spy = [
            'date'  => ['2025-06-01', '2026-05-30', '2026-06-01', '2026-06-02'],
            'close' => [400.0, 550.0, 500.0, 505.0],
        ];

        $result = WalletNavChartService::build($portfolio, [], $spy, null);

        $this->assertCount(2, $result['chartSeries']['S&P 500']);
        $this->assertSame('2026-06-01', $result['chartSeries']['S&P 500'][0]['date']);
        $this->assertEqualsWithDelta(100.0, $result['chartSeries']['S&P 500'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(101.0, $result['chartSeries']['S&P 500'][1]['value'], 0.001);
    }

    public function testBenchmarkWithNoPointsOnOrAfterD0IsOmitted(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];
        $spy = [
            'date'  => ['2025-06-01'],
            'close' => [400.0],
        ];

        $result = WalletNavChartService::build($portfolio, [], $spy, null);

        $this->assertArrayNotHasKey('S&P 500', $result['chartSeries']);
    }

    public function testBothBenchmarksPresentTogether(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];
        $spy = ['date' => ['2026-06-01'], 'close' => [500.0]];
        $qqq = ['date' => ['2026-06-01'], 'close' => [400.0]];

        $result = WalletNavChartService::build($portfolio, [], $spy, $qqq);

        $this->assertArrayHasKey('S&P 500', $result['chartSeries']);
        $this->assertArrayHasKey('Nasdaq 100', $result['chartSeries']);
    }
}
