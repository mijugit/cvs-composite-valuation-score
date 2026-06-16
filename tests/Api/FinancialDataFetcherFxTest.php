<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FinancialDataFetcher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Phase 1 (multi-currency-fx): FX rate fetch + determinism seam.
 *
 * Tests run fully offline — no Yahoo Finance calls.
 * Uses reflection to exercise normalise() and fetchFxRateToUsd() directly.
 */
class FinancialDataFetcherFxTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $full         = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->config = $full['data_source'] ?? [];
    }

    protected function tearDown(): void
    {
        // Clean FX cache entries written during tests.
        foreach (array_keys($_SESSION) as $key) {
            if (str_starts_with((string) $key, 'cvs_fx_')) {
                unset($_SESSION[$key]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function fetcher(): FinancialDataFetcher
    {
        return new FinancialDataFetcher($this->config);
    }

    /**
     * Call the private normalise() method via reflection.
     *
     * @return array<string, mixed>|null
     */
    private function callNormalise(FinancialDataFetcher $fetcher, array $raw, ?float $fxRateToUsd): ?array
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'normalise');
        $m->setAccessible(true);
        return $m->invoke($fetcher, $raw, [], [], new DateTimeImmutable(), $fxRateToUsd);
    }

    /**
     * Call the private fetchFxRateToUsd() method via reflection.
     */
    private function callFetchFxRateToUsd(FinancialDataFetcher $fetcher, string $currency): ?float
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'fetchFxRateToUsd');
        $m->setAccessible(true);
        return $m->invoke($fetcher, $currency);
    }

    /**
     * Minimal raw Yahoo Finance response — just enough for normalise() to succeed.
     *
     * @return array<string, mixed>
     */
    private function baseRaw(string $quoteCurrency = 'USD', string $financialCurrency = 'USD'): array
    {
        return [
            'financialData' => [
                'currentPrice'         => ['raw' => 100.0],
                'financialCurrency'    => $financialCurrency,
                'totalDebt'            => ['raw' => 1_000_000.0],
                'totalCash'            => ['raw' => 500_000.0],
                'freeCashflow'         => ['raw' => 200_000.0],
                'operatingCashflow'    => ['raw' => 250_000.0],
                'ebitda'               => ['raw' => 300_000.0],
                'grossMargins'         => ['raw' => 0.40],
                'returnOnEquity'       => ['raw' => 0.15],
                'revenueGrowth'        => ['raw' => 0.08],
                'twoHundredDayAverage' => ['raw' => 98.0],
            ],
            'summaryDetail' => [
                'currency'        => $quoteCurrency,
                'fiftyTwoWeekLow'  => ['raw' => 80.0],
                'fiftyTwoWeekHigh' => ['raw' => 120.0],
                'trailingPE'       => ['raw' => 20.0],
            ],
            'defaultKeyStatistics' => [
                'sharesOutstanding'              => ['raw' => 1_000_000.0],
                'priceToSalesTrailing12Months'   => ['raw' => 2.0],
                'enterpriseToEbitda'             => ['raw' => 8.0],
            ],
            'assetProfile' => [
                'sector'   => 'Technology',
                'industry' => 'Software',
                'country'  => 'KR',
            ],
            'incomeStatementHistory' => ['incomeStatementHistory' => []],
            'balanceSheetHistory'    => ['balanceSheetStatements' => []],
        ];
    }

    // ------------------------------------------------------------------
    // 1.2 — USD ticker: fx_rate_to_usd = 1.0, no FX network call
    // ------------------------------------------------------------------

    public function testFetchFxRateToUsdReturnOnePointZeroForUsd(): void
    {
        // fetchFxRateToUsd('USD') must short-circuit to 1.0 without any HTTP call.
        // We verify by inspecting the return value; if it tried to connect, the test
        // would either time out or throw (no real network in unit-test context).
        $rate = $this->callFetchFxRateToUsd($this->fetcher(), 'USD');
        $this->assertSame(1.0, $rate, 'USD must return 1.0 without a chart request');
    }

    public function testNormaliseUsdTickerHasFxRateOneAndNativeCurrencyUsd(): void
    {
        $result = $this->callNormalise($this->fetcher(), $this->baseRaw('USD', 'USD'), 1.0);

        $this->assertNotNull($result);
        $this->assertSame(1.0, $result['fx_rate_to_usd'], 'USD fx_rate_to_usd must be 1.0');
        $this->assertSame('USD', $result['native_currency'], 'native_currency must be USD');
    }

    // ------------------------------------------------------------------
    // 1.3 — non-USD with rate: fx_rate_to_usd and native_currency are set
    // ------------------------------------------------------------------

    public function testNormaliseKrwTickerWithRateSetsFields(): void
    {
        $fxRate = 1.0 / 1350.0; // simulate KRW=X close of 1350
        $raw    = $this->baseRaw('KRW', 'KRW');
        $result = $this->callNormalise($this->fetcher(), $raw, $fxRate);

        $this->assertNotNull($result, 'non-USD with valid rate must not return null');
        $this->assertEqualsWithDelta($fxRate, (float) $result['fx_rate_to_usd'], 1e-12);
        $this->assertSame('KRW', $result['native_currency']);
    }

    public function testNormaliseEurTickerWithRateSetsFields(): void
    {
        $fxRate = 1.0 / 0.915; // simulate EUR=X close of 0.915 (EUR per USD)
        $raw    = $this->baseRaw('EUR', 'EUR');
        $result = $this->callNormalise($this->fetcher(), $raw, $fxRate);

        $this->assertNotNull($result, 'EUR with valid rate must not return null');
        $this->assertSame('EUR', $result['native_currency']);
        $this->assertGreaterThan(1.0, (float) $result['fx_rate_to_usd'], 'EUR fx_rate_to_usd should be > 1.0');
    }

    // ------------------------------------------------------------------
    // 1.4 — non-USD without rate: fetch/normalise returns null
    // ------------------------------------------------------------------

    public function testNormaliseNonUsdWithNullRateReturnsNull(): void
    {
        $raw    = $this->baseRaw('KRW', 'KRW');
        $result = $this->callNormalise($this->fetcher(), $raw, null);

        $this->assertNull($result, 'non-USD with null rate must return null (skip)');
    }

    public function testNormaliseJpyWithNullRateReturnsNull(): void
    {
        $raw    = $this->baseRaw('JPY', 'JPY');
        $result = $this->callNormalise($this->fetcher(), $raw, null);

        $this->assertNull($result, 'JPY with null rate must return null (skip)');
    }

    // ------------------------------------------------------------------
    // Regression: existing USD-only downstream code still works (fx=1.0)
    // ------------------------------------------------------------------

    public function testNormaliseUsdStillReturnsExpectedCoreFields(): void
    {
        $raw    = $this->baseRaw('USD', 'USD');
        $result = $this->callNormalise($this->fetcher(), $raw, 1.0);

        $this->assertNotNull($result);
        $this->assertSame(100.0, $result['current_price']);
        $this->assertSame('Technology', $result['sector']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('USD', $result['financial_currency']);
    }
}
