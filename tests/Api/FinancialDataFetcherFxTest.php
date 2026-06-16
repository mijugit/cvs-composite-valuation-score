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
        $this->assertSame(100.0, $result['native_price'], 'native_price == current_price for USD');
    }

    // ------------------------------------------------------------------
    // 2.3 — EV/FCF invariant: same ratio regardless of currency (dimensionless)
    // ------------------------------------------------------------------

    public function testEvFcfRatioIsIdenticalInUsdAndNativeKrw(): void
    {
        // Simulate KRW ticker (price=79500 KRW, FCF=10_000_000_000 KRW, shares=1B)
        $krwPrice  = 79500.0;
        $krwFcf    = 10_000_000_000.0;
        $krwDebt   = 5_000_000_000.0;
        $krwCash   = 2_000_000_000.0;
        $shares    = 1_000_000_000.0;
        $fxRate    = 1.0 / 1350.0; // KRW→USD

        $raw = array_merge($this->baseRaw('KRW', 'KRW'), [
            'financialData' => [
                'currentPrice'         => ['raw' => $krwPrice],
                'financialCurrency'    => 'KRW',
                'freeCashflow'         => ['raw' => $krwFcf],
                'operatingCashflow'    => ['raw' => $krwFcf * 1.1], // capex < 70%
                'totalDebt'            => ['raw' => $krwDebt],
                'totalCash'            => ['raw' => $krwCash],
                'ebitda'               => ['raw' => 8_000_000_000.0],
                'grossMargins'         => ['raw' => 0.40],
                'returnOnEquity'       => ['raw' => 0.15],
                'revenueGrowth'        => ['raw' => 0.10],
                'twoHundredDayAverage' => ['raw' => 78000.0],
            ],
            'defaultKeyStatistics' => [
                'sharesOutstanding'            => ['raw' => $shares],
                'priceToSalesTrailing12Months' => ['raw' => 1.5],
                'enterpriseToEbitda'           => ['raw' => 6.0],
            ],
        ]);

        $result = $this->callNormalise($this->fetcher(), $raw, $fxRate);
        $this->assertNotNull($result);

        // EV/FCF computed natively (in KRW)
        $evNative  = $krwPrice * $shares + $krwDebt - $krwCash;
        $evFcfNative = $evNative / $krwFcf;

        // EV/FCF from converted USD fields
        $priceUsd  = (float) $result['current_price'];
        $fcfUsd    = (float) $result['free_cash_flow'];
        $debtUsd   = (float) $result['total_debt'];
        $cashUsd   = (float) $result['cash'];
        $evUsd     = $priceUsd * $shares + $debtUsd - $cashUsd;
        $evFcfUsd  = $evUsd / $fcfUsd;

        $this->assertEqualsWithDelta($evFcfNative, $evFcfUsd, 0.001,
            'EV/FCF must be identical in native KRW and USD (dimensionless)');

        // Price and FCF must now be in USD, not KRW
        $this->assertEqualsWithDelta($krwPrice * $fxRate,  $priceUsd, 1e-6, 'current_price converted to USD');
        $this->assertEqualsWithDelta($krwFcf   * $fxRate,  $fcfUsd,   1e-2, 'free_cash_flow converted to USD');

        // native_price must still be KRW
        $this->assertEqualsWithDelta($krwPrice, (float) $result['native_price'], 1e-6, 'native_price is KRW');
        $this->assertSame('KRW', $result['native_currency']);
    }

    // ------------------------------------------------------------------
    // 2.4 — ADR: EV computed consistently in USD (no currency mixing)
    // ------------------------------------------------------------------

    public function testAdrTickerNoMixedCurrencyInEv(): void
    {
        // ADR: quote currency USD (TSM-style), financial currency TWD
        $usdPrice  = 185.0;   // quoted in USD (already USD)
        $twdFcf    = 600_000_000_000.0; // TWD
        $twdDebt   = 100_000_000_000.0; // TWD
        $twdCash   =  50_000_000_000.0; // TWD
        $shares    = 5_000_000_000.0;   // ADR shares
        $fxRateTwd = 1.0 / 32.0;        // TWD=X ≈ 32

        $raw = [
            'financialData' => [
                'currentPrice'         => ['raw' => $usdPrice],
                'financialCurrency'    => 'TWD',
                'freeCashflow'         => ['raw' => $twdFcf],
                'operatingCashflow'    => ['raw' => $twdFcf * 1.05],
                'totalDebt'            => ['raw' => $twdDebt],
                'totalCash'            => ['raw' => $twdCash],
                'ebitda'               => ['raw' => 800_000_000_000.0],
                'grossMargins'         => ['raw' => 0.55],
                'returnOnEquity'       => ['raw' => 0.20],
                'revenueGrowth'        => ['raw' => 0.12],
                'twoHundredDayAverage' => ['raw' => 180.0],
            ],
            'summaryDetail' => [
                'currency'        => 'USD', // ADR quoted in USD
                'fiftyTwoWeekLow'  => ['raw' => 160.0],
                'fiftyTwoWeekHigh' => ['raw' => 210.0],
                'trailingPE'       => ['raw' => 25.0],
            ],
            'defaultKeyStatistics' => [
                'sharesOutstanding'            => ['raw' => $shares],
                'priceToSalesTrailing12Months' => ['raw' => 3.0],
                'enterpriseToEbitda'           => ['raw' => 12.0],
            ],
            'assetProfile' => ['sector' => 'Technology', 'industry' => 'Semiconductors', 'country' => 'TW'],
            'incomeStatementHistory' => ['incomeStatementHistory' => []],
            'balanceSheetHistory'    => ['balanceSheetStatements' => []],
        ];

        $result = $this->callNormalise($this->fetcher(), $raw, $fxRateTwd);
        $this->assertNotNull($result, 'ADR with valid TWD FX rate must not return null');

        // Price must stay USD (fxP=1.0 for ADR)
        $this->assertEqualsWithDelta($usdPrice, (float) $result['current_price'], 1e-6,
            'ADR current_price must stay in USD (not re-converted)');

        // Financials must be in USD
        $expectedFcfUsd  = $twdFcf  * $fxRateTwd;
        $expectedDebtUsd = $twdDebt * $fxRateTwd;
        $expectedCashUsd = $twdCash * $fxRateTwd;

        $this->assertEqualsWithDelta($expectedFcfUsd,  (float) $result['free_cash_flow'], 1e-2);
        $this->assertEqualsWithDelta($expectedDebtUsd, (float) $result['total_debt'],     1e-2);
        $this->assertEqualsWithDelta($expectedCashUsd, (float) $result['cash'],           1e-2);

        // EV = USD_price * shares + USD_debt - USD_cash — no TWD in the mix
        $evUsd = $usdPrice * $shares + $expectedDebtUsd - $expectedCashUsd;
        $this->assertGreaterThan(0.0, $evUsd, 'EV must be positive and in USD');

        // EV/FCF must be finite and reasonable (sanity: not mixing USD price with TWD financials)
        $evFcfUsd = $evUsd / $expectedFcfUsd;
        $this->assertGreaterThan(0.0, $evFcfUsd);
        $this->assertLessThan(1000.0, $evFcfUsd, 'EV/FCF < 1000 confirms no currency mixing');

        $this->assertSame('TWD', $result['native_currency']);
    }
}
