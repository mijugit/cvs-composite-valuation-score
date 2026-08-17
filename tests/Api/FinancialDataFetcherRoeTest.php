<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FinancialDataFetcher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the return_on_equity fallback wired into
 * normalise(): when Yahoo's own returnOnEquity is absent, fall back to
 * ProfitabilityMetrics::deriveRoe() (P/B ÷ P/E) and record which source won
 * in return_on_equity_source.
 *
 * Tests run fully offline — no Yahoo Finance calls — using the same
 * normalise()-via-reflection seam as FinancialDataFetcherFxTest.
 */
class FinancialDataFetcherRoeTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $full         = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->config = $full['data_source'] ?? [];
    }

    private function fetcher(): FinancialDataFetcher
    {
        return new FinancialDataFetcher($this->config);
    }

    /** @return array<string, mixed>|null */
    private function callNormalise(FinancialDataFetcher $fetcher, array $raw): ?array
    {
        $m = new \ReflectionMethod(FinancialDataFetcher::class, 'normalise');
        $m->setAccessible(true);
        return $m->invoke($fetcher, $raw, [], [], new DateTimeImmutable(), 1.0);
    }

    /**
     * Minimal raw Yahoo payload, financial-sector shaped. $roe null omits
     * returnOnEquity entirely (Yahoo not publishing it), matching the
     * XTB.WA payload observed 2026-08-17.
     *
     * @return array<string, mixed>
     */
    private function bankRaw(?float $roe, float $priceToBook, float $trailingPe): array
    {
        $financialData = [
            'currentPrice'         => ['raw' => 46.07],
            'financialCurrency'    => 'USD',
            'totalDebt'            => ['raw' => 0.0],
            'totalCash'            => ['raw' => 0.0],
            'grossMargins'         => ['raw' => 0.0],
            'revenueGrowth'        => ['raw' => 0.71],
            'twoHundredDayAverage' => ['raw' => 40.0],
        ];
        if ($roe !== null) {
            $financialData['returnOnEquity'] = ['raw' => $roe];
        }

        return [
            'financialData' => $financialData,
            'summaryDetail' => [
                'currency'         => 'USD',
                'fiftyTwoWeekLow'  => ['raw' => 16.65],
                'fiftyTwoWeekHigh' => ['raw' => 47.87],
                'trailingPE'       => ['raw' => $trailingPe],
            ],
            'defaultKeyStatistics' => [
                'sharesOutstanding' => ['raw' => 117_397_851.0],
                'priceToBook'       => ['raw' => $priceToBook],
            ],
            'assetProfile' => [
                'sector'   => 'Financial Services',
                'industry' => 'Capital Markets',
                'country'  => 'PL',
            ],
            'incomeStatementHistory' => ['incomeStatementHistory' => []],
            'balanceSheetHistory'    => ['balanceSheetStatements' => []],
        ];
    }

    public function testFallsBackToDerivedRoeWhenYahooOmitsIt(): void
    {
        // XTB.WA-shaped: Yahoo returns no returnOnEquity for this ticker.
        $result = $this->callNormalise($this->fetcher(), $this->bankRaw(null, 7.87, 20.31));

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(0.388, $result['return_on_equity'], 0.001);
        $this->assertSame('derived_pb_pe', $result['return_on_equity_source']);
    }

    public function testPrefersYahoosOwnRoeWhenPresent(): void
    {
        $result = $this->callNormalise($this->fetcher(), $this->bankRaw(0.20, 2.31, 12.77));

        $this->assertNotNull($result);
        $this->assertSame(0.20, $result['return_on_equity']);
        $this->assertSame('yahoo', $result['return_on_equity_source']);
    }

    public function testSourceIsNullWhenNeitherYahooNorDerivationHaveIt(): void
    {
        // priceToBook absent (0.0 → deriveRoe rejects it) as well as ROE.
        $result = $this->callNormalise($this->fetcher(), $this->bankRaw(null, 0.0, 20.31));

        $this->assertNotNull($result);
        $this->assertNull($result['return_on_equity']);
        $this->assertNull($result['return_on_equity_source']);
    }
}
