<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FinancialDataFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the open/date extension to daily OHLC parsing (change:
 * cvs-experimental-portfolios, Phase 1 — needed for P2 open-execution and
 * stop-fill gap detection). Tests run fully offline against a fixture shaped
 * like the real Yahoo Finance chart v8 response — no network involved.
 *
 * Exercises the private static parseOhlcChartResult() directly via reflection,
 * the same pattern FinancialDataFetcherFxTest uses for normalise()/fetchFxRateToUsd().
 */
class FinancialDataFetcherOhlcTest extends TestCase
{
    /**
     * @param array<string, mixed> $chartResult decoded chart.result[0]
     * @return array{open: float[], high: float[], low: float[], close: float[], date: string[]}
     */
    private function parse(array $chartResult): array
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'parseOhlcChartResult');
        $m->setAccessible(true);
        return $m->invoke(null, $chartResult);
    }

    /** Three clean daily bars — 2026-06-29, 06-30, 07-01 (Unix seconds, UTC midnight). */
    private function fixture(): array
    {
        return [
            'timestamp' => [1782691200, 1782777600, 1782864000],
            'indicators' => [
                'quote' => [[
                    'open'  => [100.0, 102.0, 101.0],
                    'high'  => [103.0, 104.0, 105.0],
                    'low'   => [99.0, 101.0, 100.0],
                    'close' => [102.0, 101.0, 104.0],
                ]],
            ],
        ];
    }

    public function testParseReturnsAlignedParallelArraysOldestFirst(): void
    {
        $result = $this->parse($this->fixture());

        $this->assertSame([100.0, 102.0, 101.0], $result['open']);
        $this->assertSame([103.0, 104.0, 105.0], $result['high']);
        $this->assertSame([99.0, 101.0, 100.0], $result['low']);
        $this->assertSame([102.0, 101.0, 104.0], $result['close']);
        $this->assertSame(['2026-06-29', '2026-06-30', '2026-07-01'], $result['date']);
    }

    public function testParseDropsRowWithNullOpen(): void
    {
        $raw = $this->fixture();
        $raw['indicators']['quote'][0]['open'][1] = null;

        $result = $this->parse($raw);

        $this->assertCount(2, $result['open']);
        $this->assertSame([100.0, 101.0], $result['open']);
        $this->assertSame(['2026-06-29', '2026-07-01'], $result['date']);
    }

    public function testParseDropsRowWithNullTimestamp(): void
    {
        $raw = $this->fixture();
        $raw['timestamp'][0] = null;

        $result = $this->parse($raw);

        $this->assertCount(2, $result['date']);
        $this->assertSame(['2026-06-30', '2026-07-01'], $result['date']);
    }

    public function testParseDropsRowWithNullHighLowOrClose(): void
    {
        $raw = $this->fixture();
        $raw['indicators']['quote'][0]['high'][0]  = null;
        $raw['indicators']['quote'][0]['low'][1]   = null;
        $raw['indicators']['quote'][0]['close'][2] = null;

        $result = $this->parse($raw);

        // Every row had exactly one null field -> all three rows dropped.
        $this->assertSame([], $result['open']);
        $this->assertSame([], $result['date']);
    }

    public function testParseReturnsEmptyStructureWhenQuoteMissing(): void
    {
        $result = $this->parse(['timestamp' => [1782777600]]);

        $this->assertSame(['open' => [], 'high' => [], 'low' => [], 'close' => [], 'date' => []], $result);
    }

    public function testParseReturnsEmptyStructureWhenTimestampMissing(): void
    {
        $raw = $this->fixture();
        unset($raw['timestamp']);

        $result = $this->parse($raw);

        $this->assertSame(['open' => [], 'high' => [], 'low' => [], 'close' => [], 'date' => []], $result);
    }

    public function testParseHandlesMismatchedArrayLengthsByTruncatingToShortest(): void
    {
        $raw = $this->fixture();
        $raw['indicators']['quote'][0]['open'] = [100.0]; // only 1 entry vs 3 elsewhere

        $result = $this->parse($raw);

        $this->assertCount(1, $result['open']);
        $this->assertCount(1, $result['close']);
    }
}
