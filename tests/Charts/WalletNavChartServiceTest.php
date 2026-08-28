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

    // ------------------------------------------------------------------
    // Optional third series: LLM Gemini (change: llm-gemini-wallet)
    // ------------------------------------------------------------------

    public function testGeminiSeriesOmittedByDefaultWhenNullPreservesOldTwoWalletBehaviour(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmFree   = [['date' => '2026-06-01', 'value' => 10000.0]];

        // Positional call exactly as /portfolio and /llm-free's controllers make it —
        // 5th param omitted entirely (backward compatibility).
        $result = WalletNavChartService::build($portfolio, $llmFree, null, null);

        $this->assertArrayNotHasKey('LLM Gemini', $result['chartSeries']);
        $this->assertArrayHasKey('LLM Bazowy', $result['chartSeries']);
        $this->assertArrayHasKey('LLM Free', $result['chartSeries']);
    }

    public function testGeminiSeriesIncludedAndNormalisedWhenProvided(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmFree   = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGemini = [
            ['date' => '2026-08-19', 'value' => 10000.0],
            ['date' => '2026-08-20', 'value' => 9500.0],
        ];

        $result = WalletNavChartService::build($portfolio, $llmFree, null, null, $llmGemini);

        $this->assertArrayHasKey('LLM Gemini', $result['chartSeries']);
        $this->assertEqualsWithDelta(100.0, $result['chartSeries']['LLM Gemini'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(95.0, $result['chartSeries']['LLM Gemini'][1]['value'], 0.001);
    }

    public function testGeminiSeriesEmptyArrayStillAddsKeyUnlikeNull(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];

        // Explicit empty array (wallet exists but has no completed cycles yet) is
        // distinct from omitting the param entirely — mirrors how the two
        // primary wallets already behave (see testOneEmptyWalletStillProducesChartFromTheOther).
        $result = WalletNavChartService::build($portfolio, [], null, null, []);

        $this->assertArrayHasKey('LLM Gemini', $result['chartSeries']);
        $this->assertSame([], $result['chartSeries']['LLM Gemini']);
    }

    public function testGeminiSeriesCanEstablishEarliestD0(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGemini = [['date' => '2026-01-01', 'value' => 10000.0]];

        $result = WalletNavChartService::build($portfolio, [], null, null, $llmGemini);

        $this->assertSame('2026-01-01', $result['d0']);
    }

    // ------------------------------------------------------------------
    // Optional fourth series: LLM GPT Luna (change: llm-gpt-luna-wallet)
    // ------------------------------------------------------------------

    public function testGptLunaSeriesOmittedByDefaultPreservesOldBehaviour(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmFree   = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGemini = [['date' => '2026-06-01', 'value' => 10000.0]];

        // Positional call with the 6th param omitted entirely (backward compatibility).
        $result = WalletNavChartService::build($portfolio, $llmFree, null, null, $llmGemini);

        $this->assertArrayNotHasKey('LLM GPT Luna', $result['chartSeries']);
        $this->assertArrayHasKey('LLM Gemini', $result['chartSeries']);
    }

    public function testGptLunaSeriesIncludedAndNormalisedWhenProvided(): void
    {
        $portfolio  = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmFree    = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGptLuna = [
            ['date' => '2026-08-19', 'value' => 10000.0],
            ['date' => '2026-08-20', 'value' => 10800.0],
        ];

        $result = WalletNavChartService::build($portfolio, $llmFree, null, null, null, $llmGptLuna);

        $this->assertArrayHasKey('LLM GPT Luna', $result['chartSeries']);
        $this->assertEqualsWithDelta(100.0, $result['chartSeries']['LLM GPT Luna'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(108.0, $result['chartSeries']['LLM GPT Luna'][1]['value'], 0.001);
    }

    public function testGptLunaSeriesEmptyArrayStillAddsKeyUnlikeNull(): void
    {
        $portfolio = [['date' => '2026-06-01', 'value' => 10000.0]];

        $result = WalletNavChartService::build($portfolio, [], null, null, null, []);

        $this->assertArrayHasKey('LLM GPT Luna', $result['chartSeries']);
        $this->assertSame([], $result['chartSeries']['LLM GPT Luna']);
    }

    public function testGptLunaSeriesCanEstablishEarliestD0(): void
    {
        $portfolio  = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGptLuna = [['date' => '2026-01-01', 'value' => 10000.0]];

        $result = WalletNavChartService::build($portfolio, [], null, null, null, $llmGptLuna);

        $this->assertSame('2026-01-01', $result['d0']);
    }

    public function testAllFourWalletSeriesCoexistWithBothBenchmarks(): void
    {
        $portfolio  = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmFree    = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGemini  = [['date' => '2026-06-01', 'value' => 10000.0]];
        $llmGptLuna = [['date' => '2026-06-01', 'value' => 10000.0]];
        $spy        = ['date' => ['2026-06-01'], 'close' => [500.0]];
        $qqq        = ['date' => ['2026-06-01'], 'close' => [400.0]];

        $result = WalletNavChartService::build($portfolio, $llmFree, $spy, $qqq, $llmGemini, $llmGptLuna);

        $this->assertSame(
            ['LLM Bazowy', 'LLM Free', 'LLM Gemini', 'LLM GPT Luna', 'S&P 500', 'Nasdaq 100'],
            array_keys($result['chartSeries'])
        );
    }
}
