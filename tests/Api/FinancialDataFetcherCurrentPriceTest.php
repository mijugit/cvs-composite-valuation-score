<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FinancialDataFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for resolveCurrentPrice() — the marketState-aware price pick
 * that lets a rescore run firing before/after NYSE hours (or for a
 * non-US-exchange ticker in its own pre/post window) surface the freshest
 * available Yahoo quote instead of a stale regular-session close.
 *
 * Exercises the private static method directly via reflection, the same
 * pattern FinancialDataFetcherOhlcTest uses for parseOhlcChartResult().
 */
class FinancialDataFetcherCurrentPriceTest extends TestCase
{
    private function resolve(array $priceModule, ?float $financialCurrentPrice): ?float
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'resolveCurrentPrice');
        $m->setAccessible(true);
        return $m->invoke(null, $priceModule, $financialCurrentPrice);
    }

    public function testPreMarketStateUsesPreMarketPriceWhenPresent(): void
    {
        $priceModule = [
            'marketState'        => 'PRE',
            'preMarketPrice'      => ['raw' => 101.5],
            'regularMarketPrice'  => ['raw' => 100.0],
        ];

        $this->assertSame(101.5, $this->resolve($priceModule, 100.0));
    }

    public function testPostMarketStateUsesPostMarketPriceWhenPresent(): void
    {
        $priceModule = [
            'marketState'        => 'POST',
            'postMarketPrice'     => ['raw' => 98.2],
            'regularMarketPrice'  => ['raw' => 100.0],
        ];

        $this->assertSame(98.2, $this->resolve($priceModule, 100.0));
    }

    public function testPreMarketFallsBackToRegularMarketPriceWhenPreMarketFieldAbsent(): void
    {
        $priceModule = [
            'marketState'       => 'PRE',
            'regularMarketPrice' => ['raw' => 100.0],
        ];

        $this->assertSame(100.0, $this->resolve($priceModule, 99.0));
    }

    public function testPreMarketFallsBackToFinancialCurrentPriceWhenNothingElseAvailable(): void
    {
        $priceModule = ['marketState' => 'PRE'];

        $this->assertSame(99.0, $this->resolve($priceModule, 99.0));
    }

    public function testRegularMarketStateKeepsFinancialCurrentPriceUnchanged(): void
    {
        $priceModule = [
            'marketState'        => 'REGULAR',
            'preMarketPrice'      => ['raw' => 101.5],
            'regularMarketPrice'  => ['raw' => 100.0],
        ];

        $this->assertSame(100.0, $this->resolve($priceModule, 100.0));
    }

    public function testClosedMarketStateKeepsFinancialCurrentPriceUnchanged(): void
    {
        $priceModule = [
            'marketState'        => 'CLOSED',
            'postMarketPrice'     => ['raw' => 98.2],
            'regularMarketPrice'  => ['raw' => 100.0],
        ];

        $this->assertSame(100.0, $this->resolve($priceModule, 100.0));
    }

    public function testMissingMarketStateKeepsFinancialCurrentPriceUnchanged(): void
    {
        $this->assertSame(100.0, $this->resolve([], 100.0));
    }

    public function testMissingMarketStateFallsBackToRegularMarketPriceWhenFinancialCurrentPriceIsNull(): void
    {
        $priceModule = ['regularMarketPrice' => ['raw' => 100.0]];

        $this->assertSame(100.0, $this->resolve($priceModule, null));
    }

    public function testReturnsNullWhenNothingIsAvailableAtAll(): void
    {
        $this->assertNull($this->resolve([], null));
    }
}
