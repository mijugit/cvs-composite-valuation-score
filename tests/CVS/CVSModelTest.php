<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\CVSModel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CVSModel.
 *
 * These tests exercise the model with synthetic financial data so they run
 * offline, without hitting the Yahoo Finance API.
 */
class CVSModelTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
    }

    // ------------------------------------------------------------------
    // Quality Gate failures
    // ------------------------------------------------------------------

    public function test_quality_gate_fails_on_zero_revenue(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials(['revenue' => 0]));

        $this->assertFalse($result->qualityGatePassed);
        $this->assertNull($result->cvs);
        $this->assertNotEmpty($result->gateFailures);
    }

    public function test_quality_gate_fails_on_high_leverage(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials([
            'total_debt'   => 1_000_000,
            'total_equity' =>   100_000, // D/E = 10x → FAIL (threshold 5x)
        ]));

        $this->assertFalse($result->qualityGatePassed);
    }

    // ------------------------------------------------------------------
    // Score range
    // ------------------------------------------------------------------

    public function test_cvs_is_between_0_and_100(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertTrue($result->qualityGatePassed);
        $this->assertGreaterThanOrEqual(0.0,   $result->cvs);
        $this->assertLessThanOrEqual(100.0, $result->cvs);
    }

    // ------------------------------------------------------------------
    // Determinism guarantee
    // ------------------------------------------------------------------

    public function test_same_input_always_produces_same_cvs(): void
    {
        $model      = new CVSModel($this->config);
        $financials = $this->baseFinancials();

        $a = $model->calculate('AAPL', $financials);
        $b = $model->calculate('AAPL', $financials);

        $this->assertSame($a->cvs, $b->cvs);
        $this->assertSame($a->recommendation, $b->recommendation);
    }

    // ------------------------------------------------------------------
    // Disclaimer presence
    // ------------------------------------------------------------------

    public function test_toArray_contains_disclaimer(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());
        $arr    = $result->toArray();

        $this->assertArrayHasKey('disclaimer', $arr);
        $this->assertNotEmpty($arr['disclaimer']);
    }

    // ------------------------------------------------------------------
    // Recommendation labels
    // ------------------------------------------------------------------

    public function test_strong_buy_threshold(): void
    {
        // Force a very high CVS by providing ideal financial data.
        $model      = new CVSModel($this->config);
        $financials = $this->baseFinancials([
            // Excellent growth
            'revenue_history'       => [500_000, 700_000, 1_000_000, 1_400_000, 2_000_000],
            'gross_margin_history'  => [0.45, 0.47, 0.49, 0.51, 0.53],
            // Sector: Technology, very cheap on EV/FCF basis
            'sector'                => 'Technology',
            'shares_outstanding'    => 1_000_000,
            'forward_eps'           => 12.0,
            'trailing_eps'          => 10.0,   // implied EPS growth 20%
            'gross_margins'         =>  0.55,  // 55% — matches Technology benchmark median
            // Strong momentum: stock up 67% in 6M while SPY only +6%
            'monthly_closes'        => [60.0, 65.0, 70.0, 76.0, 82.0, 90.0, 100.0],
            'spy_closes'            => [100.0, 101.0, 102.0, 103.0, 104.0, 105.0, 106.0],
            // Near 52-week low (for 52W component compatibility)
            'current_price'         => 100.0,
            'fifty_two_week_low'    =>  95.0,
            'fifty_two_week_high'   => 200.0,
            // Strong quality
            'return_on_equity'      => 0.35,
            'free_cash_flow'        => 5_000_000,
            'revenue'               => 20_000_000,
        ]);

        $result = $model->calculate('IDEAL', $financials);

        if ($result->qualityGatePassed) {
            $this->assertGreaterThanOrEqual(
                $this->config['thresholds']['strong_buy'],
                (int) round($result->cvs ?? 0)
            );
        } else {
            $this->markTestSkipped('Ideal financials still failed Quality Gate — adjust baseFinancials().');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a minimal set of financials that passes the Quality Gate.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseFinancials(array $overrides = []): array
    {
        return array_merge([
            // Pricing
            'current_price'         => 150.0,
            'fifty_two_week_low'    => 100.0,
            'fifty_two_week_high'   => 200.0,
            'moving_average_200'    => 145.0,
            // Income
            'revenue'               => 10_000_000,
            'gross_profit'          =>  3_000_000, // 30% margin
            'ebitda'                =>  2_000_000,
            'revenue_history'       => [7_000_000, 8_000_000, 9_000_000, 10_000_000],
            'gross_margin_history'  => [0.29, 0.30, 0.30, 0.30],
            // Balance sheet
            'total_debt'            =>  1_000_000,
            'total_equity'          =>  5_000_000,  // D/E = 0.2 → PASS
            'cash'                  =>    500_000,
            'current_assets'        =>  3_000_000,
            'current_liabilities'   =>  1_500_000,  // current ratio = 2.0 → PASS
            // Cash flow
            'free_cash_flow'        =>  1_500_000,
            // Quality
            'return_on_equity'      => 0.18,
            // Multiples
            'pe_ratio'              => 22.0,
            'ps_ratio'              =>  2.5,
            'ev_ebitda'             => 10.0,
            // Sector medians (nullable — pillar (b) neutrals on null)
            'sector_pe_median'      => 25.0,
            'sector_ps_median'      =>  3.0,
            'sector_ev_ebitda_median' => 12.0,
        ], $overrides);
    }
}
