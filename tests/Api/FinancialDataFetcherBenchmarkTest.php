<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FinancialDataFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for resolveBenchmarkTicker() — picks MomentumPillar's benchmark
 * per the ticker's home market (e.g. WIG20TR for Warsaw-listed .WA tickers)
 * instead of always comparing against SPY, so a non-US company is scored on
 * momentum vs. its own market. Exercises the private static method directly
 * via reflection, the same pattern used for parseOhlcChartResult() and
 * resolveCurrentPrice().
 */
class FinancialDataFetcherBenchmarkTest extends TestCase
{
    private const CONFIG = [
        'default'   => 'SPY',
        'by_suffix' => [
            '.WA' => 'ETFBW20TR.WA',
            '.KS' => '069500.KS',
        ],
        'labels' => [
            'SPY'          => 'S&P 500',
            'ETFBW20TR.WA' => 'WIG20TR',
            '069500.KS'    => 'KOSPI 200',
        ],
    ];

    private function resolve(string $ticker, array $config = self::CONFIG): string
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'resolveBenchmarkTicker');
        $m->setAccessible(true);
        return $m->invoke(null, $ticker, $config);
    }

    private function label(string $benchmarkTicker, array $config = self::CONFIG): string
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'resolveBenchmarkLabel');
        $m->setAccessible(true);
        return $m->invoke(null, $benchmarkTicker, $config);
    }

    public function testUsTickerWithNoSuffixUsesDefault(): void
    {
        $this->assertSame('SPY', $this->resolve('AAPL'));
    }

    public function testWarsawSuffixResolvesToWig20trEtf(): void
    {
        $this->assertSame('ETFBW20TR.WA', $this->resolve('KGH.WA'));
    }

    public function testKoreaSuffixResolvesToKospi200Etf(): void
    {
        $this->assertSame('069500.KS', $this->resolve('005930.KS'));
    }

    public function testUnmappedSuffixFallsBackToDefault(): void
    {
        $this->assertSame('SPY', $this->resolve('SOMETHING.XX'));
    }

    public function testEmptyConfigFallsBackToHardcodedSpyDefault(): void
    {
        $this->assertSame('SPY', $this->resolve('KGH.WA', []));
    }

    public function testCustomDefaultIsRespectedForUnmappedSuffix(): void
    {
        $config = ['default' => 'VGK', 'by_suffix' => ['.WA' => 'ETFBW20TR.WA']];
        $this->assertSame('VGK', $this->resolve('SOMETHING.DE', $config));
    }

    // ------------------------------------------------------------------
    // resolveBenchmarkLabel
    // ------------------------------------------------------------------

    public function testLabelReturnsConfiguredHumanReadableName(): void
    {
        $this->assertSame('WIG20TR', $this->label('ETFBW20TR.WA'));
        $this->assertSame('KOSPI 200', $this->label('069500.KS'));
        $this->assertSame('S&P 500', $this->label('SPY'));
    }

    public function testLabelFallsBackToTickerWhenUnconfigured(): void
    {
        $this->assertSame('EXS1.DE', $this->label('EXS1.DE'));
    }
}
