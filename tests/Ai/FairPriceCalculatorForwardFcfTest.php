<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\FairPriceCalculator;
use CVS\CVS\Pillars\ValuationPillar;
use PHPUnit\Framework\TestCase;

/**
 * FairPriceCalculator must resolve forward FCF (variant A — EV/FCF) the same
 * way ValuationPillar::scoreVariantA() does, or the two disagree about the
 * same company right next to each other on the page — the exact failure
 * FairPriceCalculatorRoeTest already guards for variant C (P/B ÷ ROE).
 *
 * Before this was shared (ValuationMetrics::resolveForwardFcfEst()), fair
 * value always used trailing_fcf × (1+g)² while the pillar preferred the
 * analyst forward-FCF estimate whenever it passed the FR-011 sanity bounds —
 * observed live on NVDA: fair value implied +35% upside while the pillar
 * scored it 0.5/100 (maximally overvalued). These tests reproduce that
 * MU-style trough-capex shape (also used in CVSModelTest's FR-011 tests) and
 * assert fair value and the pillar score move together.
 */
class FairPriceCalculatorForwardFcfTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        // Legacy/cold-start benchmarks — deterministic, no peer-group resolver needed.
        $this->config['peer_group']['enabled'] = false;
    }

    /**
     * MU-style trough capex: trailing FCF depressed, but forward_fcf_est (from
     * analyst forward EPS × historical FCF/EPS conversion) is ~8x larger.
     * Mirrors CVSModelTest::test_valuation_score_improves_for_trough_fcf_company().
     *
     * @return array<string, mixed>
     */
    private function troughFcfCompany(): array
    {
        $fcf         = 500_000_000.0;
        $trailingEps = 1.0;
        $forwardEps  = 8.0;
        $shares      = 1_100_000_000.0;

        return [
            'sector'              => 'Technology',
            'industry'            => 'Semiconductors',
            'current_price'       => 100.0,
            'shares_outstanding'  => $shares,
            'total_debt'          => 2_000_000_000.0,
            'cash'                =>   500_000_000.0,
            'free_cash_flow'      => $fcf,
            'trailing_eps'        => $trailingEps,
            'forward_eps'         => $forwardEps,
            'revenue_growth'      => 0.50,
            'forward_fcf_est'     => $forwardEps * ($fcf / $trailingEps), // 4 000M
        ];
    }

    public function testFairValueUsesForwardFcfEstimateWhenPillarWould(): void
    {
        $financials = $this->troughFcfCompany();

        $fairValueOn  = FairPriceCalculator::compute($financials, $this->config);

        $cfgOff = $this->config;
        $cfgOff['valuation']['use_forward_fcf_estimate'] = false;
        $fairValueOff = FairPriceCalculator::compute($financials, $cfgOff);

        $this->assertNotNull($fairValueOn);
        $this->assertNotNull($fairValueOff);

        // forward_fcf_est (4 000M) is ~8x trailing_fcf × (1+g)^2 (500M × 1.5^2 = 1 125M),
        // so the estimate-based fair value must be materially higher.
        $this->assertGreaterThan($fairValueOff, $fairValueOn,
            "Fair value with forward_fcf_est should exceed the trailing-growth fallback. "
            . "Got ON=$fairValueOn OFF=$fairValueOff");
    }

    /**
     * The core regression: fair value's direction (over/undervalued vs current
     * price) must agree with the Valuation pillar's direction (score vs 50) for
     * the SAME financials — this is what broke live for NVDA before the fix.
     */
    public function testFairValueDirectionAgreesWithValuationPillar(): void
    {
        $financials = $this->troughFcfCompany();

        $fairValue = FairPriceCalculator::compute($financials, $this->config);
        $this->assertNotNull($fairValue);

        $pillar = new ValuationPillar(
            benchmarks: $this->config['benchmarks'],
            resolver: null, // legacy/cold-start path — same static benchmark FairPriceCalculator falls back to
            valuationConfig: $this->config['valuation'],
        );
        $score = $pillar->score($financials);

        $currentPrice = (float) $financials['current_price'];
        $fairValueSaysCheap = $fairValue > $currentPrice; // fair value above price = model sees upside
        $pillarSaysCheap    = $score > 50.0;               // pillar score above 50 = cheaper than peer median

        $this->assertSame(
            $pillarSaysCheap,
            $fairValueSaysCheap,
            "Fair value ($fairValue vs price $currentPrice) and the Valuation pillar "
            . "(score=$score) must agree on direction for the same financials."
        );
    }

    /**
     * GOOGL-shaped 2026-09: trailing EPS inflated by equity-stake gains (net
     * margin 54.8% vs operating 34.0%). forward_fcf_est must be ignored and
     * growth taken from revenue (+24.2%), not from forward/trailing EPS (−22%).
     * Before the shared growth helper, fair value took revenue growth while the
     * pillar took the EPS decline — exactly the fallback path this guard enables.
     */
    public function testDistortedTrailingEpsUsesRevenueGrowthInBothFairValueAndPillar(): void
    {
        $financials = [
            'sector'             => 'Technology',
            'industry'           => 'Internet Content & Information',
            'current_price'      => 100.0,
            'shares_outstanding' => 1_000_000_000.0,
            'total_debt'         => 0.0,
            'cash'               => 0.0,
            'free_cash_flow'     => 2_000_000_000.0,
            'trailing_eps'       => 4.5,
            'forward_eps'        => 3.5,     // below inflated trailing → "−22% growth"
            'revenue_growth'     => 0.242,
            'profit_margin'      => 0.548,
            'operating_margin'   => 0.340,
            // fcf/share 2.0 ÷ trailing EPS 4.5 = 0.44 — inside the FR-011 bounds, so
            // only the distortion guard keeps this (too low) estimate out.
            'forward_fcf_est'    => 3.5 * 2_000_000_000.0 / 4.5,
        ];

        $fairValue = FairPriceCalculator::compute($financials, $this->config);
        $this->assertNotNull($fairValue);

        // Static Technology benchmark: median_ev_fcf 32, max_growth 60.
        // Revenue growth 24.2% → fwd FCF = 2B × 1.242² ≈ 3.085B → fair EV ≈ 98.7B → ≈ $98.72.
        $expected = 32 * 2_000_000_000.0 * (1.242 ** 2) / 1_000_000_000.0;
        $this->assertEqualsWithDelta($expected, $fairValue, 0.05);

        $pillar = new ValuationPillar(
            benchmarks: $this->config['benchmarks'],
            resolver: null,
            valuationConfig: $this->config['valuation'],
        );
        $score = $pillar->score($financials);

        // Fair value ≈ price → pillar ≈ 50, and both must land on the same side.
        $this->assertEqualsWithDelta(50.0, $score, 5.0, "Pillar should sit near parity, got $score");
        $this->assertSame($fairValue > 100.0, $score > 50.0,
            "Fair value $fairValue and pillar score $score must agree on direction.");
    }
}
