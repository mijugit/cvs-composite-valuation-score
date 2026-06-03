<?php

declare(strict_types=1);

namespace CVS\Tests\CVS\Valuation;

use CVS\CVS\Valuation\ValuationMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ValuationMetrics — pure calculation helpers.
 *
 * All tests run fully offline (no I/O).
 * Uses the same base financials shape as CVSModelTest.
 */
class ValuationMetricsTest extends TestCase
{
    // ------------------------------------------------------------------
    // extractForwardGrowth
    // ------------------------------------------------------------------

    public function test_growth_from_eps(): void
    {
        $f = $this->base(['forward_eps' => 12.0, 'trailing_eps' => 10.0]);
        // (12/10 − 1) × 100 = 20 %
        $this->assertEqualsWithDelta(20.0, ValuationMetrics::extractForwardGrowth($f), 0.001);
    }

    public function test_growth_skips_eps_on_base_effect(): void
    {
        // EPS growth > 200 % — skip EPS, fall back to revenue_growth
        $f = $this->base([
            'forward_eps'    => 30.0,
            'trailing_eps'   =>  1.0,  // +2900% → base effect
            'revenue_growth' =>  0.15,
        ]);
        $this->assertEqualsWithDelta(15.0, ValuationMetrics::extractForwardGrowth($f), 0.001);
    }

    public function test_growth_skips_eps_on_eps_revenue_gap(): void
    {
        // EPS growth 60%, revenue growth 5% → ratio = 12× > 3.5 → skip EPS
        $f = $this->base([
            'forward_eps'    => 16.0,
            'trailing_eps'   => 10.0, // +60%
            'revenue_growth' =>  0.05,
        ]);
        $this->assertEqualsWithDelta(5.0, ValuationMetrics::extractForwardGrowth($f), 0.001);
    }

    public function test_growth_from_revenue_growth(): void
    {
        $f = $this->base([
            'forward_eps'    => null,
            'trailing_eps'   => null,
            'revenue_growth' => 0.20,
        ]);
        $this->assertEqualsWithDelta(20.0, ValuationMetrics::extractForwardGrowth($f), 0.001);
    }

    public function test_growth_from_quarterly_earnings(): void
    {
        $f = $this->base([
            'forward_eps'               => null,
            'trailing_eps'              => null,
            'revenue_growth'            => null,
            'earnings_quarterly_growth' => 0.12,
        ]);
        $this->assertEqualsWithDelta(12.0, ValuationMetrics::extractForwardGrowth($f), 0.001);
    }

    public function test_growth_null_when_no_data(): void
    {
        $f = $this->base([
            'forward_eps'               => null,
            'trailing_eps'              => null,
            'revenue_growth'            => null,
            'earnings_quarterly_growth' => null,
        ]);
        $this->assertNull(ValuationMetrics::extractForwardGrowth($f));
    }

    public function test_growth_null_when_trailing_eps_zero(): void
    {
        $f = $this->base([
            'forward_eps'  => 5.0,
            'trailing_eps' => 0.0, // division by zero guard
            'revenue_growth' => null,
            'earnings_quarterly_growth' => null,
        ]);
        $this->assertNull(ValuationMetrics::extractForwardGrowth($f));
    }

    // ------------------------------------------------------------------
    // enterpriseValue
    // ------------------------------------------------------------------

    public function test_ev_computed_correctly(): void
    {
        $f  = $this->base([
            'current_price'      => 100.0,
            'shares_outstanding' => 1_000_000.0,
            'total_debt'         => 500_000.0,
            'cash'               => 200_000.0,
        ]);
        // EV = 100 × 1_000_000 + 500_000 − 200_000 = 100_300_000
        $this->assertEqualsWithDelta(100_300_000.0, ValuationMetrics::enterpriseValue($f), 1.0);
    }

    public function test_ev_null_when_no_price(): void
    {
        $f = $this->base(['current_price' => null]);
        $this->assertNull(ValuationMetrics::enterpriseValue($f));
    }

    public function test_ev_null_when_shares_zero(): void
    {
        $f = $this->base(['shares_outstanding' => 0.0]);
        $this->assertNull(ValuationMetrics::enterpriseValue($f));
    }

    // ------------------------------------------------------------------
    // forwardEvFcf
    // ------------------------------------------------------------------

    public function test_forward_ev_fcf_positive(): void
    {
        $f = $this->base([
            'current_price'      => 100.0,
            'shares_outstanding' => 1_000_000.0,
            'total_debt'         =>       0.0,
            'cash'               =>       0.0,
            'free_cash_flow'     => 5_000_000.0,
        ]);
        // EV = 100M, forwardFCF = 5M × (1.10)^2 ≈ 6.05M, ratio ≈ 16.5
        $ratio = ValuationMetrics::forwardEvFcf($f, 10.0);
        $this->assertNotNull($ratio);
        $this->assertGreaterThan(0.0, $ratio);
    }

    public function test_forward_ev_fcf_null_when_fcf_negative(): void
    {
        $f = $this->base(['free_cash_flow' => -1_000.0]);
        $this->assertNull(ValuationMetrics::forwardEvFcf($f, 10.0));
    }

    public function test_forward_ev_fcf_null_when_fcf_missing(): void
    {
        $f = $this->base(['free_cash_flow' => null]);
        $this->assertNull(ValuationMetrics::forwardEvFcf($f, 10.0));
    }

    // ------------------------------------------------------------------
    // forwardEvSalesAdjusted
    // ------------------------------------------------------------------

    public function test_forward_ev_sales_adjusted_returns_positive(): void
    {
        $f = $this->base([
            'current_price'      => 100.0,
            'shares_outstanding' => 1_000_000.0,
            'total_debt'         =>       0.0,
            'cash'               =>       0.0,
            'free_cash_flow'     =>      -1.0, // FCF negative → Variant B path
            'revenue'            => 50_000_000.0,
            'gross_margins'      => 0.50,
        ]);
        $adj = ValuationMetrics::forwardEvSalesAdjusted($f, 15.0);
        $this->assertNotNull($adj);
        $this->assertGreaterThan(0.0, $adj);
    }

    public function test_forward_ev_sales_null_when_no_revenue(): void
    {
        $f = $this->base(['revenue' => null]);
        $this->assertNull(ValuationMetrics::forwardEvSalesAdjusted($f, 10.0));
    }

    public function test_forward_ev_sales_null_when_no_gross_margin(): void
    {
        $f = $this->base(['gross_margins' => null]);
        $this->assertNull(ValuationMetrics::forwardEvSalesAdjusted($f, 10.0));
    }

    // ------------------------------------------------------------------
    // sectorEvSalesTarget
    // ------------------------------------------------------------------

    public function test_sector_target_computed(): void
    {
        // Technology: median_ev_sales=8.0, max_growth=60, median_gm=0.55
        $target = ValuationMetrics::sectorEvSalesTarget(8.0, 60.0, 0.55);
        // target = 8 / ((60/2) * 0.55) = 8 / 16.5 ≈ 0.485
        $this->assertEqualsWithDelta(0.485, $target, 0.01);
    }

    // ------------------------------------------------------------------
    // Regression: ValuationPillar results unchanged after metrics extraction
    // ------------------------------------------------------------------

    public function test_valuation_pillar_regression_after_extract(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/cvs-weights.php';
        $pillar = new \CVS\CVS\Pillars\ValuationPillar($config['benchmarks']);

        $baseFinancials = [
            'sector'                     => 'Technology',
            'current_price'              => 150.0,
            'shares_outstanding'         => 15_000_000_000.0,
            'total_debt'                 =>  1_000_000.0,
            'cash'                       =>    500_000.0,
            'free_cash_flow'             =>  1_500_000.0,
            'gross_margins'              => 0.45,
            'forward_eps'                => 7.0,
            'trailing_eps'               => 6.0,
            'revenue_growth'             => 0.10,
            'revenue'                    => 10_000_000.0,
            'earnings_quarterly_growth'  => null,
        ];

        $score = $pillar->score($baseFinancials);

        // ValuationPillar must still return a non-neutral score for this fixture.
        $this->assertNotEquals(50.0, $score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function base(array $overrides = []): array
    {
        return array_merge([
            'sector'                     => 'Technology',
            'current_price'              => 150.0,
            'shares_outstanding'         => 15_000_000_000.0,
            'total_debt'                 =>  1_000_000.0,
            'cash'                       =>    500_000.0,
            'free_cash_flow'             =>  1_500_000.0,
            'revenue'                    => 10_000_000.0,
            'gross_margins'              => 0.45,
            'forward_eps'                => 7.0,
            'trailing_eps'               => 6.0,
            'revenue_growth'             => 0.10,
            'earnings_quarterly_growth'  => null,
        ], $overrides);
    }
}
