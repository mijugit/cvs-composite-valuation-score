<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\CVSModel;
use CVS\CVS\Pillars\ValuationPillar;
use CVS\CVS\Pillars\MomentumPillar;
use CVS\CVS\Valuation\PeerMedianRepository;
use PDO;
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
    private PeerMedianRepository $peerRepo;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';

        // Provide an in-memory SQLite peer-median repo so CVSModel does not
        // try to connect to a real MySQL database during offline tests.
        // Empty DB → MedianResolver always falls back to cold-start (static
        // benchmarks) → identical scoring to the legacy path.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE peer_medians (
            id INTEGER PRIMARY KEY,
            level TEXT NOT NULL,
            bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL,
            model_version TEXT NOT NULL,
            metric_type TEXT NOT NULL,
            median_value REAL NULL,
            sample_count INTEGER NOT NULL DEFAULT 0,
            computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(level, bucket_key, model_version, metric_type)
        )');
        $this->peerRepo = new PeerMedianRepository($pdo);
    }

    private function model(): CVSModel
    {
        return new CVSModel($this->config, $this->peerRepo);
    }

    // ------------------------------------------------------------------
    // Quality Gate failures
    // ------------------------------------------------------------------

    public function test_quality_gate_fails_on_zero_revenue(): void
    {
        $model  = $this->model();
        $result = $model->calculate('TEST', $this->baseFinancials(['revenue' => 0]));

        $this->assertFalse($result->qualityGatePassed);
        $this->assertNull($result->cvs());
        $this->assertNotEmpty($result->gateFailures);
    }

    public function test_quality_gate_fails_on_high_leverage(): void
    {
        $model  = $this->model();
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
        $model  = $this->model();
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertTrue($result->qualityGatePassed);
        $this->assertNotNull($result->swingCvs);
        $this->assertGreaterThanOrEqual(0.0,   $result->swingCvs);
        $this->assertLessThanOrEqual(100.0, $result->swingCvs);
    }

    public function test_fundamental_cvs_is_between_0_and_100(): void
    {
        $model  = $this->model();
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertTrue($result->qualityGatePassed);
        $this->assertNotNull($result->fundamentalCvs);
        $this->assertGreaterThanOrEqual(0.0,   $result->fundamentalCvs);
        $this->assertLessThanOrEqual(100.0, $result->fundamentalCvs);
    }

    public function test_cvs_backward_compat_returns_swing(): void
    {
        $model  = $this->model();
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertSame($result->swingCvs, $result->cvs());
    }

    // ------------------------------------------------------------------
    // Pillar scores presence
    // ------------------------------------------------------------------

    public function test_pillar_scores_contain_valuation(): void
    {
        $model  = $this->model();
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertArrayHasKey('valuation',      $result->pillarScores);
        $this->assertArrayHasKey('momentum_swing', $result->pillarScores);
        $this->assertArrayHasKey('momentum_fund',  $result->pillarScores);
        $this->assertArrayHasKey('quality',        $result->pillarScores);
    }

    /**
     * value/variant power the sector-history-modal overlay on the /analysis
     * valuation badge (analysis.php) — the company's own EV/FCF (variant A)
     * or EV/Sales (variant B) multiple plotted against the peer-group's
     * historical median. Both must come from the SAME resolution as
     * source/bucket, never a stale/mismatched value.
     */
    public function test_valuation_reference_contains_value_and_variant(): void
    {
        $model  = $this->model();
        $result = $model->calculate('TEST', $this->baseFinancials());

        $this->assertArrayHasKey('value',   $result->valuationReference);
        $this->assertArrayHasKey('variant', $result->valuationReference);
        $this->assertContains($result->valuationReference['variant'], ['A', 'B']);
        $this->assertIsFloat($result->valuationReference['value']);
    }

    // ------------------------------------------------------------------
    // Determinism guarantee
    // ------------------------------------------------------------------

    public function test_same_input_always_produces_same_cvs(): void
    {
        $model      = $this->model();
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
        $model  = $this->model();
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
        $model  = $this->model();
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
        $model      = $this->model();
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
        $model      = $this->model();
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
        $model      = $this->model();
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
        $pillar = new ValuationPillar($this->config['benchmarks']);
        $score  = $pillar->score($this->baseFinancials());

        $this->assertNotEquals(50.0, $score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    public function test_sector_pillar_returns_neutral_when_no_growth_data(): void
    {
        $pillar = new ValuationPillar($this->config['benchmarks']);
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
        $model      = $this->model();
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
    // Overlay penalties (Phase 5, slice 1) — golden reproduction of sim_overlay.php
    //
    // These fixtures are 1:1 transcriptions of the MU/STX/AVGO cases validated in
    // sim_overlay.php (real researched financials from the 2026-06-05 crash week).
    // The empty in-memory peer_medians table (see setUp()) makes MedianResolver
    // fall back to cold-start/static benchmarks — identical to sim_overlay.php's
    // legacy-mode ValuationPillar — so absolute pillar scores match the sim 1:1.
    //
    // Expected numbers below were captured by running `php sim_overlay.php`.
    // ------------------------------------------------------------------

    private const SPY_CLOSES = [500, 510, 520, 530, 545, 555, 565, 575, 585, 595, 600, 605, 608];

    /** Micron — overlay B (target gate) fires; overlay A no-op (rising estimates). */
    private function muFinancials(): array
    {
        return $this->baseFinancials([
            'sector'                => 'Technology',
            'current_price'         => 864.01,
            'shares_outstanding'    => 1.13e9,
            'free_cash_flow'        => 10.28e9,
            'operating_cash_flow'   => 18.0e9,
            'ebitda'                => 36.80e9,
            'total_debt'            => 10.80e9,
            'total_equity'          => 50.0e9,  // D/E ≈ 0.22x — clears the gate (override default 5M)
            'cash'                  => 14.59e9,
            'revenue'               => 58.12e9,
            'gross_margins'         => 0.5844,
            'trailing_eps'          => 21.26,
            'forward_eps'           => 24.00,
            'revenue_growth'        => 0.40,
            'monthly_closes'        => [400, 440, 490, 540, 590, 650, 710, 760, 810, 850, 880, 900, 905],
            'spy_closes'            => self::SPY_CLOSES,
            // Phase 5 overlay inputs — eps_revision_90d=+0.08 (rising; A=no-op);
            // upside = (739.48 - 864.01) / 864.01 ≈ -0.1441 (price above target; B fires)
            'eps_revision_pct'      => +0.08,
            'analyst_target_upside' => (739.48 - 864.01) / 864.01,
        ]);
    }

    /** Seagate — both overlays effectively no-op (estimates ~stable, price ~at target). */
    private function stxFinancials(): array
    {
        return $this->baseFinancials([
            'sector'                => 'Technology',
            'current_price'         => 847.47,
            'shares_outstanding'    => 0.22625e9,
            'free_cash_flow'        => 2.41e9,
            'operating_cash_flow'   => 3.0e9,
            'ebitda'                => 3.51e9,
            'total_debt'            => 4.18e9,
            'total_equity'          => 2.0e9,   // D/E ≈ 2.09x — clears the gate (override default 5M)
            'cash'                  => 1.15e9,
            'revenue'               => 11.01e9,
            'gross_margins'         => 0.4157,
            'trailing_eps'          => 10.56,
            'forward_eps'           => 21.90,
            'revenue_growth'        => 0.30,
            'monthly_closes'        => [300, 340, 390, 450, 510, 570, 630, 690, 740, 790, 830, 860, 872],
            'spy_closes'            => self::SPY_CLOSES,
            // eps_revision_90d=+0.03 (≈stable; A=no-op);
            // upside = (829.05 - 847.47) / 847.47 ≈ -0.0217 (small B penalty)
            'eps_revision_pct'      => +0.03,
            'analyst_target_upside' => (829.05 - 847.47) / 847.47,
        ]);
    }

    /** Broadcom — real opportunity: both overlays no-op (rising estimates + deep positive upside). */
    private function avgoFinancials(): array
    {
        return $this->baseFinancials([
            'sector'                => 'Technology',
            'current_price'         => 385.73,
            'shares_outstanding'    => 4.73e9,
            'free_cash_flow'        => 32.76e9,
            'operating_cash_flow'   => 38.0e9,
            'ebitda'                => 41.95e9,
            'total_debt'            => 64.91e9,
            'total_equity'          => 70.0e9,  // D/E ≈ 0.93x — clears the gate (override default 5M)
            'cash'                  => 19.63e9,
            'revenue'               => 75.47e9,
            'gross_margins'         => 0.7628,
            'trailing_eps'          => 6.01,
            'forward_eps'           => 8.00,
            'revenue_growth'        => 0.20,
            'monthly_closes'        => [230, 250, 275, 300, 330, 360, 390, 415, 435, 450, 460, 465, 452],
            'spy_closes'            => self::SPY_CLOSES,
            // eps_revision_90d=+0.06 (rising; A=no-op);
            // upside = (510.62 - 385.73) / 385.73 ≈ +0.3238 (positive — B never rewards, no-op)
            'eps_revision_pct'      => +0.06,
            'analyst_target_upside' => (510.62 - 385.73) / 385.73,
        ]);
    }

    public function test_overlay_golden_mu_target_gate_fires_base_unchanged(): void
    {
        $result = $this->model()->calculate('MU', $this->muFinancials());
        $this->assertTrue($result->qualityGatePassed);

        // Base (3.0) — sim baseline: swing=39.6 REDUKUJ, fund=31.5 REDUKUJ — untouched by overlays.
        $this->assertSame(39.6, $result->swingCvs);
        $this->assertSame(31.5, $result->fundamentalCvs);
        $this->assertSame('⬇ REDUKUJ', $result->swingRecommendation);
        $this->assertSame('⬇ REDUKUJ', $result->fundamentalRecommendation);

        // Shadow (3.1) — sim final: revision=0 (rising estimates), target=-8.6 (price > target)
        // → swing 39.6-8.6=31.0 REDUKUJ, fund 31.5-8.6=22.9 UNIKAJ.
        $overlay = $result->overlay;
        $this->assertNotNull($overlay);
        $this->assertSame('3.1', $overlay['shadow_version']);
        $this->assertSame(0.0,  $overlay['penalties']['revision']);
        $this->assertEqualsWithDelta(-8.6, $overlay['penalties']['target'], 0.05);
        $this->assertEqualsWithDelta(-8.6, $overlay['penalties']['total'],  0.05);
        $this->assertEqualsWithDelta(31.0, $overlay['swing'], 0.05);
        $this->assertEqualsWithDelta(22.9, $overlay['fund'],  0.05);
        $this->assertSame('⬇ REDUKUJ',  $overlay['swing_reco']);
        $this->assertSame('⬇⬇ UNIKAJ', $overlay['fund_reco']);
        $this->assertFalse($overlay['coverage']['missing_eps_trend']);
        $this->assertFalse($overlay['coverage']['missing_target']);
    }

    public function test_overlay_golden_stx_small_target_gate_penalty_base_unchanged(): void
    {
        $result = $this->model()->calculate('STX', $this->stxFinancials());
        $this->assertTrue($result->qualityGatePassed);

        // Base (3.0) — sim baseline: swing=47.8 NEUTRALNIE, fund=40.3 REDUKUJ — untouched.
        $this->assertSame(47.8, $result->swingCvs);
        $this->assertSame(40.3, $result->fundamentalCvs);
        $this->assertSame('→ NEUTRALNIE', $result->swingRecommendation);
        $this->assertSame('⬇ REDUKUJ',    $result->fundamentalRecommendation);

        // Shadow (3.1) — sim final: revision=0, target≈-1.3 → swing 46.5 NEUTRALNIE, fund 39.0 REDUKUJ.
        $overlay = $result->overlay;
        $this->assertNotNull($overlay);
        $this->assertSame(0.0, $overlay['penalties']['revision']);
        $this->assertEqualsWithDelta(-1.3, $overlay['penalties']['target'], 0.05);
        $this->assertEqualsWithDelta(46.5, $overlay['swing'], 0.05);
        $this->assertEqualsWithDelta(39.0, $overlay['fund'],  0.05);
        $this->assertSame('→ NEUTRALNIE', $overlay['swing_reco']);
        $this->assertSame('⬇ REDUKUJ',    $overlay['fund_reco']);
    }

    public function test_overlay_golden_avgo_real_opportunity_untouched_by_overlays(): void
    {
        $result = $this->model()->calculate('AVGO', $this->avgoFinancials());
        $this->assertTrue($result->qualityGatePassed);

        // Base (3.0) — sim baseline: swing=55.4 NEUTRALNIE, fund=63.2 AKUMULUJ.
        $this->assertSame(55.4, $result->swingCvs);
        $this->assertSame(63.2, $result->fundamentalCvs);

        // Shadow (3.1) — both overlays no-op (rising estimates + deep positive upside):
        // proves the design doesn't punish a real opportunity. 3.1 == 3.0 exactly.
        $overlay = $result->overlay;
        $this->assertNotNull($overlay);
        $this->assertSame(0.0, $overlay['penalties']['revision']);
        $this->assertSame(0.0, $overlay['penalties']['target']);
        $this->assertSame(0.0, $overlay['penalties']['total']);
        $this->assertSame($result->swingCvs,       $overlay['swing']);
        $this->assertSame($result->fundamentalCvs, $overlay['fund']);
        $this->assertSame($result->swingRecommendation,       $overlay['swing_reco']);
        $this->assertSame($result->fundamentalRecommendation, $overlay['fund_reco']);
    }

    public function test_overlay_is_null_when_overlays_disabled_in_config(): void
    {
        $config             = $this->config;
        $config['overlays'] = ['enabled' => false] + $config['overlays'];

        $model  = new CVSModel($config, $this->peerRepo);
        $result = $model->calculate('MU', $this->muFinancials());

        $this->assertTrue($result->qualityGatePassed);
        $this->assertNull($result->overlay);
        // Base fields (3.0) remain fully populated regardless of the overlay switch.
        $this->assertSame(39.6, $result->swingCvs);
    }

    public function test_overlay_marks_coverage_flags_when_inputs_are_missing(): void
    {
        $financials = $this->muFinancials();
        unset($financials['eps_revision_pct'], $financials['analyst_target_upside']);

        $result  = $this->model()->calculate('MU', $financials);
        $overlay = $result->overlay;

        $this->assertNotNull($overlay);
        $this->assertTrue($overlay['coverage']['missing_eps_trend']);
        $this->assertTrue($overlay['coverage']['missing_target']);
        // No inputs → no penalties → 3.1 collapses to 3.0.
        $this->assertSame(0.0, $overlay['penalties']['total']);
        $this->assertSame($result->swingCvs,       $overlay['swing']);
        $this->assertSame($result->fundamentalCvs, $overlay['fund']);
    }

    public function test_overlay_calculation_is_deterministic(): void
    {
        $financials = $this->muFinancials();

        $r1 = $this->model()->calculate('MU', $financials);
        $r2 = $this->model()->calculate('MU', $financials);

        $this->assertSame($r1->toArray(), $r2->toArray());
        $this->assertSame($r1->overlay, $r2->overlay);
    }

    // ------------------------------------------------------------------
    // Earnings-timing badge (Phase 5, slice 2 — always present, additive)
    // ------------------------------------------------------------------

    public function test_earnings_timing_is_null_when_calendar_coverage_is_missing(): void
    {
        $financials = $this->baseFinancials(); // no days_since_earnings / days_to_earnings keys

        $result = $this->model()->calculate('TST', $financials);

        $this->assertTrue($result->qualityGatePassed);
        $this->assertNull($result->earningsTiming);
        $this->assertNull($result->toArray()['earnings_timing']);
    }

    public function test_earnings_timing_reports_before_state_within_window(): void
    {
        $financials = $this->baseFinancials([
            'days_since_earnings' => 60,
            'days_to_earnings'    => 3,
        ]);

        $result  = $this->model()->calculate('TST', $financials);
        $timing  = $result->earningsTiming;

        $this->assertNotNull($timing);
        $this->assertSame(60,      $timing['days_since']);
        $this->assertSame(3,       $timing['days_to']);
        $this->assertSame('before', $timing['state']);
        $this->assertTrue($timing['guard_active']);
    }

    public function test_earnings_timing_reports_after_state_within_window(): void
    {
        $financials = $this->baseFinancials([
            'days_since_earnings' => 1,
            'days_to_earnings'    => 89,
        ]);

        $timing = $this->model()->calculate('TST', $financials)->earningsTiming;

        $this->assertNotNull($timing);
        $this->assertSame('after', $timing['state']);
        $this->assertTrue($timing['guard_active']);
    }

    public function test_earnings_timing_reports_in_transit_state_on_calendar_lag(): void
    {
        $financials = $this->baseFinancials([
            'days_since_earnings' => 1,
            'days_to_earnings'    => -2,
        ]);

        $timing = $this->model()->calculate('TST', $financials)->earningsTiming;

        $this->assertNotNull($timing);
        $this->assertSame('in_transit', $timing['state']);
        $this->assertTrue($timing['guard_active']);
    }

    public function test_earnings_timing_reports_null_state_outside_window_with_inactive_guard(): void
    {
        $financials = $this->baseFinancials([
            'days_since_earnings' => 60,
            'days_to_earnings'    => 30,
        ]);

        $timing = $this->model()->calculate('TST', $financials)->earningsTiming;

        $this->assertNotNull($timing);
        $this->assertNull($timing['state']);
        $this->assertFalse($timing['guard_active']);
    }

    public function test_earnings_timing_is_present_regardless_of_overlay_and_guard_flags(): void
    {
        $config                     = $this->config;
        $config['overlays']         = ['enabled' => false] + $config['overlays'];
        $config['earnings_guard']   = ['enabled' => false] + $config['earnings_guard'];

        $financials = $this->baseFinancials([
            'days_since_earnings' => 60,
            'days_to_earnings'    => 2,
        ]);

        $result = (new CVSModel($config, $this->peerRepo))->calculate('TST', $financials);

        // FR-010/FR-017: the badge is independent of the shadow-mode flags above —
        // both overlays AND earnings_guard are disabled, yet the badge still renders.
        $this->assertNull($result->overlay);
        $timing = $result->earningsTiming;
        $this->assertNotNull($timing);
        $this->assertSame('before', $timing['state']);
        $this->assertTrue($timing['guard_active']);
    }

    public function test_earnings_timing_calculation_is_deterministic(): void
    {
        $financials = $this->baseFinancials([
            'days_since_earnings' => 2,
            'days_to_earnings'    => 88,
        ]);

        $r1 = $this->model()->calculate('TST', $financials);
        $r2 = $this->model()->calculate('TST', $financials);

        $this->assertSame($r1->earningsTiming, $r2->earningsTiming);
        $this->assertSame($r1->toArray(), $r2->toArray());
    }

    // ------------------------------------------------------------------
    // Earnings guard penalty — wired into the shadow overlay (Phase 5, slice 2)
    // ------------------------------------------------------------------

    public function test_overlay_earnings_guard_penalty_is_added_to_shadow_total(): void
    {
        $financials = $this->muFinancials();
        // MU baseline shadow total is target-only (-8.6, see golden test above).
        // Add a near-term earnings date → guard penalty layers on top, additively.
        $financials['days_since_earnings'] = 60;
        $financials['days_to_earnings']    = 0; // proximity = 1.0 → penalty = -10.0 (cfg default)

        $overlay = $this->model()->calculate('MU', $financials)->overlay;

        $this->assertNotNull($overlay);
        $this->assertEqualsWithDelta(-10.0, $overlay['penalties']['earnings_guard'], 0.05);
        // total = revision(0) + target(-8.6) + earnings_guard(-10.0), capped per-component not jointly.
        $this->assertEqualsWithDelta(-18.6, $overlay['penalties']['total'], 0.05);
        $this->assertFalse($overlay['coverage']['missing_earnings_calendar']);
    }

    public function test_overlay_earnings_guard_penalty_is_zero_outside_window(): void
    {
        $financials = $this->muFinancials();
        $financials['days_since_earnings'] = 60;
        $financials['days_to_earnings']    = 90;

        $overlay = $this->model()->calculate('MU', $financials)->overlay;

        $this->assertNotNull($overlay);
        $this->assertSame(0.0, $overlay['penalties']['earnings_guard']);
    }

    public function test_overlay_marks_missing_earnings_calendar_coverage(): void
    {
        // MU fixture carries no days_since_earnings/days_to_earnings (pre-Phase-5-slice-2 shape).
        $overlay = $this->model()->calculate('MU', $this->muFinancials())->overlay;

        $this->assertNotNull($overlay);
        $this->assertTrue($overlay['coverage']['missing_earnings_calendar']);
        $this->assertSame(0.0, $overlay['penalties']['earnings_guard']);
    }

    public function test_overlay_earnings_guard_penalty_is_zero_when_guard_disabled_in_config(): void
    {
        $config                   = $this->config;
        $config['earnings_guard'] = ['enabled' => false] + $config['earnings_guard'];

        $financials = $this->muFinancials();
        $financials['days_since_earnings'] = 60;
        $financials['days_to_earnings']    = 0;

        $overlay = (new CVSModel($config, $this->peerRepo))->calculate('MU', $financials)->overlay;

        $this->assertNotNull($overlay);
        $this->assertSame(0.0, $overlay['penalties']['earnings_guard']);
        // Shadow falls back to revision+target only (-8.6) — base 3.0 still untouched.
        $this->assertEqualsWithDelta(-8.6, $overlay['penalties']['total'], 0.05);
    }

    // ------------------------------------------------------------------
    // Predictive signals — shadow model_version 3.2 (Phase 7, slice 2)
    // ------------------------------------------------------------------

    /**
     * Neutral fixture: current_price/fifty_two_week_high ratio == signals_32.high_52w.baseline
     * (0.85), no eps_revision_pct/analyst_target_upside (3.1 penalties == 0), no
     * earnings calendar, no surprise/breadth/beatCount coverage. Every 3.2 signal
     * is a coverage no-op -> 3.2 should collapse onto 3.1 (and 3.0).
     */
    private function neutralFinancials(array $overrides = []): array
    {
        return $this->baseFinancials(array_merge([
            'current_price'        => 170.0,
            'fifty_two_week_high'  => 200.0, // 170/200 = 0.85 == baseline
        ], $overrides));
    }

    public function test_shadow32_present_in_shadows_list_alongside_31(): void
    {
        $result = $this->model()->calculate('TST', $this->neutralFinancials());

        $this->assertCount(2, $result->shadows);
        $this->assertSame('3.1', $result->shadows[0]['shadow_version']);
        $this->assertSame('3.2', $result->shadows[1]['shadow_version']);

        // overlay alias resolves to the 3.1 block (config['overlays']['shadow_version']).
        $this->assertSame($result->shadows[0], $result->overlay);
        $this->assertSame($result->shadows, $result->toArray()['shadows']);
    }

    public function test_shadow32_parity_with_31_when_all_signals_are_coverage_noops(): void
    {
        $result = $this->model()->calculate('TST', $this->neutralFinancials());

        $overlay31 = $result->shadows[0];
        $shadow32  = $result->shadows[1];

        $this->assertSame($overlay31['penalties']['revision'], $shadow32['penalties']['revision']);
        $this->assertSame($overlay31['penalties']['target'],   $shadow32['penalties']['target']);
        $this->assertSame($overlay31['penalties']['total'],    $shadow32['penalties']['total']);
        $this->assertSame($overlay31['swing'], $shadow32['swing']);
        $this->assertSame($overlay31['fund'],  $shadow32['fund']);
        $this->assertSame(0.0, $shadow32['signals']['adjustments']['total']);
    }

    public function test_shadow32_is_null_when_signals_32_disabled(): void
    {
        $config                = $this->config;
        $config['signals_32']  = ['enabled' => false] + $config['signals_32'];

        $result = (new CVSModel($config, $this->peerRepo))->calculate('TST', $this->neutralFinancials());

        $this->assertCount(1, $result->shadows);
        $this->assertSame('3.1', $result->shadows[0]['shadow_version']);
    }

    public function test_shadow32_is_null_when_overlays_disabled_hierarchical_kill_switch(): void
    {
        // Plan-review F3: signals_32.enabled stays true, but overlays.enabled=false
        // disables the entire shadow stack including 3.2.
        $config             = $this->config;
        $config['overlays'] = ['enabled' => false] + $config['overlays'];

        $result = (new CVSModel($config, $this->peerRepo))->calculate('TST', $this->neutralFinancials());

        $this->assertSame([], $result->shadows);
        $this->assertNull($result->overlay);
    }

    /**
     * US-03: a post-earnings beat neutralises the 3.1 earnings-guard penalty in
     * 3.2 (3.2 >= 3.1), while a beat-twin's miss amplifies it (3.2 < 3.1) — both
     * visible in the penalty breakdown.
     */
    public function test_shadow32_us03_post_earnings_beat_neutralises_guard_penalty(): void
    {
        $financials = $this->neutralFinancials([
            'days_since_earnings' => 2, // proximity = (5-2)/5 = 0.6 -> base penalty -6.0
            'days_to_earnings'    => 89,
            'eps_surprise_pct'    => 0.05, // beat
        ]);

        $result    = $this->model()->calculate('TST', $financials);
        $overlay31 = $result->shadows[0];
        $shadow32  = $result->shadows[1];

        $this->assertEqualsWithDelta(-6.0, $overlay31['penalties']['earnings_guard'], 0.05);
        $this->assertSame(0.0, $shadow32['penalties']['earnings_guard']); // beat_bonus default 0.0
        $this->assertGreaterThanOrEqual($overlay31['swing'], $shadow32['swing']);
        $this->assertGreaterThanOrEqual($overlay31['fund'],  $shadow32['fund']);
    }

    public function test_shadow32_us03_post_earnings_miss_amplifies_guard_penalty(): void
    {
        $financials = $this->neutralFinancials([
            'days_since_earnings' => 2, // base penalty -6.0
            'days_to_earnings'    => 89,
            'eps_surprise_pct'    => -0.05, // miss
        ]);

        $result    = $this->model()->calculate('TST', $financials);
        $overlay31 = $result->shadows[0];
        $shadow32  = $result->shadows[1];

        $this->assertEqualsWithDelta(-6.0, $overlay31['penalties']['earnings_guard'], 0.05);
        // -6.0 * miss_multiplier(1.5) = -9.0 -- stronger than 3.1's -6.0.
        $this->assertEqualsWithDelta(-9.0, $shadow32['penalties']['earnings_guard'], 0.05);
        $this->assertLessThan($overlay31['swing'], $shadow32['swing']);
        $this->assertLessThan($overlay31['fund'],  $shadow32['fund']);
    }

    /**
     * Value-trap fixture (deep cheapness + estimate cuts, MU-style): 3.1 already
     * carries revision/target penalties. With no post-earnings/breadth/52w
     * signal active, 3.2 reuses those penalties unchanged -> 3.2 <= 3.1
     * (signals never claw back penalties).
     */
    public function test_shadow32_value_trap_does_not_exceed_31(): void
    {
        // muFinancials() doesn't set fifty_two_week_high (defaults to 200.0,
        // far below the 864.01 price) — neutralise the 52w-proximity signal
        // by setting it so price/high == baseline (0.85), isolating the
        // revision/target penalties this test is about.
        $financials                       = $this->muFinancials();
        $financials['fifty_two_week_high'] = $financials['current_price'] / 0.85;

        $result = $this->model()->calculate('MU', $financials);

        $overlay31 = $result->shadows[0];
        $shadow32  = $result->shadows[1];

        $this->assertNotNull($overlay31);
        $this->assertNotNull($shadow32);
        $this->assertLessThanOrEqual($overlay31['swing'], $shadow32['swing']);
        $this->assertLessThanOrEqual($overlay31['fund'],  $shadow32['fund']);
    }

    /**
     * Beat-with-positive-breadth fixture (PRD success criterion #3): a positive
     * revision-breadth signal lifts 3.2 above 3.1.
     */
    public function test_shadow32_beat_with_positive_breadth_meets_or_exceeds_31(): void
    {
        $financials = $this->neutralFinancials([
            'eps_revision_breadth' => 0.6,
        ]);

        $result    = $this->model()->calculate('TST', $financials);
        $overlay31 = $result->shadows[0];
        $shadow32  = $result->shadows[1];

        $this->assertEqualsWithDelta(2.4, $shadow32['signals']['adjustments']['breadth'], 0.05);
        $this->assertGreaterThanOrEqual($overlay31['swing'], $shadow32['swing']);
        $this->assertGreaterThanOrEqual($overlay31['fund'],  $shadow32['fund']);
    }

    public function test_shadow32_signals_and_coverage_reflect_inputs(): void
    {
        $financials = $this->neutralFinancials([
            'eps_surprise_pct'     => 0.04,
            'eps_revision_breadth' => -0.2,
            'eps_beat_count_4q'    => 3,
        ]);

        $shadow32 = $this->model()->calculate('TST', $financials)->shadows[1];

        $this->assertSame(0.04, $shadow32['signals']['surprise_pct']);
        $this->assertSame(-0.2, $shadow32['signals']['breadth']);
        $this->assertSame(3,    $shadow32['signals']['beat_count_4q']);
        $this->assertEqualsWithDelta(0.85, $shadow32['signals']['high_52w_proximity'], 0.001);

        $this->assertFalse($shadow32['coverage']['missing_surprise']);
        $this->assertFalse($shadow32['coverage']['missing_breadth']);
        $this->assertFalse($shadow32['coverage']['missing_52w']);
        $this->assertFalse($shadow32['coverage']['missing_consistency']);
    }

    public function test_shadow32_coverage_flags_missing_signals(): void
    {
        $shadow32 = $this->model()->calculate('TST', $this->neutralFinancials())->shadows[1];

        $this->assertNull($shadow32['signals']['surprise_pct']);
        $this->assertNull($shadow32['signals']['breadth']);
        $this->assertNull($shadow32['signals']['beat_count_4q']);

        $this->assertTrue($shadow32['coverage']['missing_surprise']);
        $this->assertTrue($shadow32['coverage']['missing_breadth']);
        $this->assertTrue($shadow32['coverage']['missing_consistency']);
    }

    public function test_shadow32_calculation_is_deterministic(): void
    {
        $financials = $this->neutralFinancials([
            'days_since_earnings'  => 2,
            'days_to_earnings'     => 89,
            'eps_surprise_pct'     => -0.05,
            'eps_revision_breadth' => 0.3,
            'eps_beat_count_4q'    => 1,
        ]);

        $r1 = $this->model()->calculate('TST', $financials);
        $r2 = $this->model()->calculate('TST', $financials);

        $this->assertSame($r1->shadows, $r2->shadows);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a minimal set of financials that passes the Quality Gate
     * and provides sensible inputs for all pillars.
     *
    // ------------------------------------------------------------------
    // FR-011: FCF normalization — trough and stability
    // ------------------------------------------------------------------

    /**
     * MU-style trough capex: trailing FCF depressed by HBM capex, but analyst
     * EPS estimates show strong recovery. forward_fcf_est should raise the
     * valuation score by using the analyst-projected FCF as denominator.
     *
     * Fixture design:
     *   shares = 1.1B, fcf = 500M → fcf_per_share ≈ 0.45
     *   trailing_eps = 1.0 → ratio = 0.45 ∈ [0.3, 3.0] → normalization applies
     *   forward_eps  = 8.0 → +700% EPS (base effect) → growthPct from revenue_growth=50%
     *   forward_fcf_est = 8.0 × 500M / 1.0 = 4 000M (8.5× trailing FCF)
     */
    public function test_valuation_score_improves_for_trough_fcf_company(): void
    {
        $cfg = $this->config;
        $cfg['peer_group']['enabled'] = false; // deterministic legacy benchmarks

        $fcf         = 500_000_000.0;
        $trailingEps = 1.0;
        $forwardEps  = 8.0;
        $shares      = 1_100_000_000.0;

        $financials = $this->baseFinancials([
            'current_price'       => 100.0,
            'shares_outstanding'  => $shares,
            'total_debt'          => 10_000_000_000.0,
            'total_equity'        => 50_000_000_000.0,
            'cash'                =>    500_000_000.0,
            'current_assets'      => 30_000_000_000.0,
            'current_liabilities' => 10_000_000_000.0,
            'revenue'             => 25_000_000_000.0,
            'gross_profit'        => 14_000_000_000.0,
            'gross_margins'       => 0.55,
            'free_cash_flow'      => $fcf,
            'trailing_eps'        => $trailingEps,
            'forward_eps'         => $forwardEps,
            'revenue_growth'      => 0.50,
            'forward_fcf_est'     => $forwardEps * ($fcf / $trailingEps), // 4 000M
        ]);

        $modelOn = new CVSModel($cfg, $this->peerRepo);

        $cfgOff = $cfg;
        $cfgOff['valuation']['use_forward_fcf_estimate'] = false;
        $modelOff = new CVSModel($cfgOff, $this->peerRepo);

        $resultOn  = $modelOn->calculate('MU', $financials);
        $resultOff = $modelOff->calculate('MU', $financials);

        $this->assertNotNull($resultOn,  'QualityGate should pass for MU fixture');
        $this->assertNotNull($resultOff, 'QualityGate should pass for MU fixture (flag off)');

        $scoreOn  = (float) $resultOn->toArray()['pillar_scores']['valuation'];
        $scoreOff = (float) $resultOff->toArray()['pillar_scores']['valuation'];

        // Normalization must produce a higher (less expensive) valuation score
        $this->assertGreaterThan($scoreOff, $scoreOn,
            "With forward_fcf_est (4B vs trailing 500M): score should improve. Got ON=$scoreOn OFF=$scoreOff");
    }

    /**
     * Healthy company with moderate FCF growth: forward_fcf_est ≈ trailing × (1+g),
     * so using it instead of trailing × (1+g)^2 causes only a small (~5%) denominator
     * difference. The resulting score change must be ≤ 5 points.
     *
     * Fixture design:
     *   shares = 150K, fcf = 1.5M → fcf_per_share = 10.0
     *   trailing_eps = 6.0 → ratio = 10/6 ≈ 1.67 ∈ [0.3, 3.0] → normalization applies
     *   forward_eps  = 6.3 (+5%) → no base effect; forward_fcf_est = 1.575M
     *   Old formula:  1.5M × (1.05)^2 = 1.654M
     *   New formula:  1.575M  (5% smaller denominator → score Δ < 5 pts)
     */
    public function test_valuation_score_stable_for_healthy_fcf_company(): void
    {
        $cfg = $this->config;
        $cfg['peer_group']['enabled'] = false;

        $fcf         = 1_500_000.0;
        $trailingEps = 6.0;
        $forwardEps  = 6.3; // +5% — moderate, predictable
        $shares      = 150_000.0;

        $financials = $this->baseFinancials([
            'shares_outstanding' => $shares,
            'free_cash_flow'     => $fcf,
            'trailing_eps'       => $trailingEps,
            'forward_eps'        => $forwardEps,
            'revenue_growth'     => 0.05,
            'forward_fcf_est'    => $forwardEps * ($fcf / $trailingEps), // 1 575 000
        ]);

        $modelOn = new CVSModel($cfg, $this->peerRepo);

        $cfgOff = $cfg;
        $cfgOff['valuation']['use_forward_fcf_estimate'] = false;
        $modelOff = new CVSModel($cfgOff, $this->peerRepo);

        $resultOn  = $modelOn->calculate('STABLE', $financials);
        $resultOff = $modelOff->calculate('STABLE', $financials);

        $this->assertNotNull($resultOn,  'QualityGate should pass for healthy fixture');
        $this->assertNotNull($resultOff, 'QualityGate should pass for healthy fixture (flag off)');

        $scoreOn  = (float) $resultOn->toArray()['pillar_scores']['valuation'];
        $scoreOff = (float) $resultOff->toArray()['pillar_scores']['valuation'];

        $this->assertLessThanOrEqual(5.0, abs($scoreOn - $scoreOff),
            "Score change for healthy company should be <= 5 pts. Got ON=$scoreOn OFF=$scoreOff, diff=" . abs($scoreOn - $scoreOff));
    }

    // ------------------------------------------------------------------

    /**
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
