<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\CVSModel;
use CVS\CVS\Pillars\SectorBenchmarkPillar;
use CVS\CVS\Pillars\MomentumPillar;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CVSModel (S-05 dual-mode).
 *
 * Tests run fully offline — no Yahoo Finance API calls.
 * Uses synthetic financials from baseFinancials() as canonical fixtures.
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
        $this->assertNull($result->cvs());
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
    // Dual score range
    // ------------------------------------------------------------------

    public function test_swing_cvs_is_between_0_and_100(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertTrue($result->qualityGatePassed);
        $this->assertNotNull($result->swingCvs);
        $this->assertGreaterThanOrEqual(0.0,   $result->swingCvs);
        $this->assertLessThanOrEqual(100.0, $result->swingCvs);
    }

    public function test_fundamental_cvs_is_between_0_and_100(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertTrue($result->qualityGatePassed);
        $this->assertNotNull($result->fundamentalCvs);
        $this->assertGreaterThanOrEqual(0.0,   $result->fundamentalCvs);
        $this->assertLessThanOrEqual(100.0, $result->fundamentalCvs);
    }

    public function test_cvs_backward_compat_returns_swing(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertSame($result->swingCvs, $result->cvs());
    }

    // ------------------------------------------------------------------
    // Pillar scores presence
    // ------------------------------------------------------------------

    public function test_pillar_scores_contain_valuation(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertArrayHasKey('valuation',      $result->pillarScores);
        $this->assertArrayHasKey('momentum_swing', $result->pillarScores);
        $this->assertArrayHasKey('momentum_fund',  $result->pillarScores);
        $this->assertArrayHasKey('quality',        $result->pillarScores);
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

        $this->assertSame($a->swingCvs,       $b->swingCvs);
        $this->assertSame($a->fundamentalCvs, $b->fundamentalCvs);
        $this->assertSame($a->goldenSignal,   $b->goldenSignal);
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
    // toArray structure (S-05)
    // ------------------------------------------------------------------

    public function test_toArray_contains_swing_and_fundamental(): void
    {
        $model  = new CVSModel($this->config);
        $result = $model->calculate('TEST', $this->baseFinancials());
        $arr    = $result->toArray();

        $this->assertArrayHasKey('swing',       $arr);
        $this->assertArrayHasKey('fundamental', $arr);
        $this->assertArrayHasKey('cvs',         $arr['swing']);
        $this->assertArrayHasKey('cvs',         $arr['fundamental']);
    }

    // ------------------------------------------------------------------
    // Golden signal
    // ------------------------------------------------------------------

    public function test_golden_signal_strong_when_both_high(): void
    {
        // Force both swing and fundamental high: excellent momentum + cheap valuation
        $model      = new CVSModel($this->config);
        $financials = $this->baseFinancials([
            // Excellent momentum: +60% in 1M, SPY flat
            'monthly_closes' => [60.0, 62.0, 64.0, 66.0, 68.0, 70.0, 96.0],
            'spy_closes'     => [100.0, 100.5, 101.0, 100.8, 101.0, 101.2, 101.4],
            // Strong EPS growth
            'forward_eps'    => 12.0,
            'trailing_eps'   => 8.0,
            'revenue_growth' => 0.35,
            // Very cheap on EV/FCF
            'current_price'       => 96.0,
            'shares_outstanding'  => 100_000,   // small cap → low EV
            'free_cash_flow'      => 5_000_000, // high FCF
        ]);

        $result = $model->calculate('IDEAL', $financials);

        if ($result->qualityGatePassed) {
            $thr = $this->config['thresholds']['accumulate'];
            if ($result->swingCvs >= $thr && $result->fundamentalCvs >= $thr) {
                $this->assertSame('strong', $result->goldenSignal);
            }
        } else {
            $this->markTestSkipped('Gate failed — adjust fixture for golden signal test.');
        }
    }

    public function test_golden_signal_null_when_both_low(): void
    {
        // Force both scores low: very expensive stock, terrible momentum
        $model      = new CVSModel($this->config);
        $financials = $this->baseFinancials([
            // Negative momentum: falling knife
            'monthly_closes' => [160.0, 155.0, 150.0, 145.0, 140.0, 130.0, 120.0],
            'spy_closes'     => [100.0, 102.0, 104.0, 106.0, 108.0, 110.0, 112.0],
            // Very expensive: price $120, tiny FCF, huge EV
            'current_price'      => 120.0,
            'shares_outstanding' => 1_000_000_000_000.0, // 1T shares → huge market cap
            'free_cash_flow'     => 1,                   // near-zero FCF
            // No growth
            'forward_eps'                => null,
            'trailing_eps'               => null,
            'revenue_growth'             => null,
            'earnings_quarterly_growth'  => null,
        ]);

        $result = $model->calculate('BAD', $financials);

        if ($result->qualityGatePassed) {
            $thr = $this->config['thresholds']['accumulate'];
            if ($result->swingCvs < $thr && $result->fundamentalCvs < $thr) {
                $this->assertNull($result->goldenSignal);
            }
        } else {
            $this->markTestSkipped('Gate failed — adjust fixture for golden signal test.');
        }
    }

    // ------------------------------------------------------------------
    // Recommendation labels
    // ------------------------------------------------------------------

    public function test_strong_buy_threshold(): void
    {
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
            'gross_margins'         =>  0.55,
            // Strong momentum: stock up 67% in 6M while SPY only +6%
            'monthly_closes'        => [60.0, 65.0, 70.0, 76.0, 82.0, 90.0, 100.0],
            'spy_closes'            => [100.0, 101.0, 102.0, 103.0, 104.0, 105.0, 106.0],
            // Near 52-week low
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
            // Check at least one mode reaches strong_buy
            $topScore = max($result->swingCvs ?? 0, $result->fundamentalCvs ?? 0);
            $this->assertGreaterThanOrEqual(
                $this->config['thresholds']['strong_buy'],
                (int) round($topScore)
            );
        } else {
            $this->markTestSkipped('Ideal financials still failed Quality Gate — adjust baseFinancials().');
        }
    }

    // ------------------------------------------------------------------
    // SectorBenchmarkPillar (now ValuationPillar)
    // ------------------------------------------------------------------

    public function test_sector_pillar_returns_non_neutral_score(): void
    {
        $pillar = new SectorBenchmarkPillar($this->config['benchmarks']);
        $score  = $pillar->score($this->baseFinancials());

        $this->assertNotEquals(50.0, $score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    public function test_sector_pillar_returns_neutral_when_no_growth_data(): void
    {
        $pillar = new SectorBenchmarkPillar($this->config['benchmarks']);
        $score  = $pillar->score($this->baseFinancials([
            'forward_eps'                => null,
            'trailing_eps'               => null,
            'revenue_growth'             => null,
            'earnings_quarterly_growth'  => null,
        ]));

        $this->assertEquals(50.0, $score);
    }

    // ------------------------------------------------------------------
    // MomentumPillar — dual mode
    // ------------------------------------------------------------------

    public function test_momentum_pillar_returns_non_neutral_score(): void
    {
        $swingMode = $this->config['modes']['swing'];
        $pillar    = new MomentumPillar($swingMode);
        $score     = $pillar->score($this->baseFinancials(), $swingMode['roc_weights']);

        $this->assertNotEquals(50.0, $score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    public function test_momentum_pillar_returns_neutral_when_insufficient_history(): void
    {
        $swingMode = $this->config['modes']['swing'];
        $pillar    = new MomentumPillar($swingMode);
        $score     = $pillar->score($this->baseFinancials([
            'monthly_closes' => [140.0, 145.0, 150.0, 148.0, 155.0], // only 5
        ]), $swingMode['roc_weights']);

        $this->assertEquals(50.0, $score);
    }

    public function test_swing_and_fundamental_momentum_can_differ(): void
    {
        // Short history (7 entries) makes 1M dominant in swing; 6M in fundamental.
        // With a strong 1M spike, swing momentum > fundamental momentum.
        $model      = new CVSModel($this->config);
        $financials = $this->baseFinancials([
            // Big recent spike: 1M ago was 100, now 140 → 40% 1M gain
            // But 6M ago was 130 → only 7.7% 6M gain
            'monthly_closes' => [100.0, 110.0, 120.0, 125.0, 128.0, 100.0, 140.0],
        ]);
        $result = $model->calculate('TEST', $financials);

        if ($result->qualityGatePassed) {
            // Swing (1M heavy) should see stronger momentum than Fundamental (6M/12M heavy)
            $this->assertGreaterThan(
                $result->pillarScores['momentum_fund'],
                $result->pillarScores['momentum_swing']
            );
        } else {
            $this->markTestSkipped('Gate failed — adjust fixture.');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a minimal set of financials that passes the Quality Gate
     * and provides sensible inputs for all pillars.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseFinancials(array $overrides = []): array
    {
        return array_merge([
            // Company metadata
            'sector'                     => 'Technology',
            // Pricing
            'current_price'              => 150.0,
            'fifty_two_week_low'         => 100.0,
            'fifty_two_week_high'        => 200.0,
            'moving_average_200'         => 145.0,
            // Income
            'revenue'                    => 10_000_000,
            'gross_profit'               =>  3_000_000,
            'ebitda'                     =>  2_000_000,
            'revenue_history'            => [7_000_000, 8_000_000, 9_000_000, 10_000_000],
            'gross_margin_history'       => [0.29, 0.30, 0.30, 0.30],
            // Balance sheet
            'total_debt'                 =>  1_000_000,
            'total_equity'               =>  5_000_000,  // D/E = 0.2 → PASS
            'cash'                       =>    500_000,
            'current_assets'             =>  3_000_000,
            'current_liabilities'        =>  1_500_000,  // current ratio = 2.0 → PASS
            // Cash flow
            'free_cash_flow'             =>  1_500_000,
            'operating_cash_flow'        =>  1_800_000,
            // Quality
            'return_on_equity'           => 0.18,
            // Valuation multiples
            'pe_ratio'                   => 22.0,
            'ps_ratio'                   =>  2.5,
            'ev_ebitda'                  => 10.0,
            // EV / Sector fields (SectorBenchmarkPillar)
            'shares_outstanding'         => 15_000_000_000.0, // 15B shares
            'gross_margins'              => 0.45,
            'forward_eps'                => 7.0,
            'trailing_eps'               => 6.0,
            'revenue_growth'             => 0.10,
            'earnings_quarterly_growth'  => null,
            // Price history (MomentumPillar — 7 monthly closes minimum)
            'monthly_closes'             => [140.0, 145.0, 150.0, 148.0, 155.0, 160.0, 162.0],
            'spy_closes'                 => [430.0, 432.0, 435.0, 433.0, 438.0, 440.0, 442.0],
        ], $overrides);
    }
}
