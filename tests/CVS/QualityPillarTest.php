<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\Pillars\QualityPillar;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QualityPillar (S-05).
 *
 * Mirrors Python cvs_analyze.py calc_quality() logic.
 * Tests run fully offline — no API calls required.
 */
class QualityPillarTest extends TestCase
{
    private array $config;
    private array $techBenchmark;

    protected function setUp(): void
    {
        $this->config        = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->techBenchmark = $this->config['benchmarks']['Technology'];
    }

    // ------------------------------------------------------------------
    // Score range
    // ------------------------------------------------------------------

    public function test_score_is_in_range_0_to_100(): void
    {
        $pillar = new QualityPillar($this->techBenchmark);
        $score  = $pillar->score($this->baseFinancials());

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    // ------------------------------------------------------------------
    // Gross margin component
    // ------------------------------------------------------------------

    public function test_high_gross_margin_gets_max_gm_pts(): void
    {
        // Technology benchmark median_gm = 55. gross_margins = 0.80 → gm_delta = 25 → pts_gm = 4
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials(['gross_margins' => 0.80]));

        $steps = $pillar->steps();
        $this->assertSame(4.0, $steps['pts_gm'], 'gm_delta ≥ 15 should yield 4 pts');
    }

    public function test_gross_margin_at_sector_median_yields_2_pts(): void
    {
        // Technology median_gm = 55. gross_margins = 0.55 → gm_delta = 0 → pts_gm = 2
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials(['gross_margins' => 0.55]));

        $steps = $pillar->steps();
        $this->assertSame(2.0, $steps['pts_gm'], 'gm_delta in [−5, +5) should yield 2 pts');
    }

    public function test_very_low_gross_margin_gets_0_gm_pts(): void
    {
        // gross_margins = 0.20 → gm_delta = 20 − 55 = −35 → pts_gm = 0
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials(['gross_margins' => 0.20]));

        $steps = $pillar->steps();
        $this->assertSame(0.0, $steps['pts_gm'], 'gm_delta < −15 should yield 0 pts');
    }

    // ------------------------------------------------------------------
    // Leverage component — EBITDA path
    // ------------------------------------------------------------------

    public function test_low_leverage_gets_max_pts(): void
    {
        // net_debt = 500k − 200k = 300k, ebitda = 2M → ratio = 0.15 ≤ 1 → pts = 3
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials([
            'total_debt' => 500_000,
            'cash'       => 200_000,
            'ebitda'     => 2_000_000,
        ]));

        $steps = $pillar->steps();
        $this->assertSame(3.0, $steps['pts_leverage'], 'net_debt/ebitda ≤ 1 should yield 3 pts');
    }

    public function test_very_high_leverage_gets_0_pts(): void
    {
        // net_debt = 20M, ebitda = 2M → ratio = 10 > 4 → pts = 0
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials([
            'total_debt' => 20_000_000,
            'cash'       =>    100_000,
            'ebitda'     =>  2_000_000,
        ]));

        $steps = $pillar->steps();
        $this->assertSame(0.0, $steps['pts_leverage'], 'net_debt/ebitda > 4 should yield 0 pts');
    }

    public function test_negative_net_debt_treated_as_zero(): void
    {
        // Cash > debt → net_debt = max(0, ...) = 0 → ratio = 0 ≤ 1 → pts = 3
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials([
            'total_debt' =>    100_000,
            'cash'       =>  1_000_000,  // net cash position
            'ebitda'     =>  2_000_000,
        ]));

        $steps = $pillar->steps();
        $this->assertSame(3.0, $steps['pts_leverage'], 'net_debt < 0 clamped to 0 → max pts');
    }

    // ------------------------------------------------------------------
    // Leverage component — cash runway fallback (no EBITDA)
    // ------------------------------------------------------------------

    public function test_cash_runway_fallback_when_no_ebitda(): void
    {
        // ebitda null, cash = 3M, revenue = 5M → cr = 0.60 ≥ 0.30 → pts = 2
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials([
            'ebitda'  => null,
            'cash'    => 3_000_000,
            'revenue' => 5_000_000,
        ]));

        $steps = $pillar->steps();
        $this->assertSame(2.0, $steps['pts_leverage'], 'cash/revenue ≥ 0.30 → 2 pts');
    }

    // ------------------------------------------------------------------
    // Growth component
    // ------------------------------------------------------------------

    public function test_high_growth_gets_3_pts(): void
    {
        // forward_eps / trailing_eps → 15%+ growth → pts_growth = 3 if > 10%
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials([
            'forward_eps'  => 12.0,
            'trailing_eps' =>  8.0,  // EPS growth 50%
            'revenue_growth' => 0.45,
        ]));

        $steps = $pillar->steps();
        $this->assertSame(3.0, $steps['pts_growth'], 'growth > 10% should yield 3 pts');
    }

    public function test_no_growth_data_yields_0_growth_pts(): void
    {
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials([
            'forward_eps'                => null,
            'trailing_eps'               => null,
            'revenue_growth'             => null,
            'earnings_quarterly_growth'  => null,
        ]));

        $steps = $pillar->steps();
        $this->assertSame(0.0, $steps['pts_growth'], 'no growth data → 0 pts');
    }

    // ------------------------------------------------------------------
    // Score computation correctness
    // ------------------------------------------------------------------

    public function test_raw_score_matches_sum_of_pts(): void
    {
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials());

        $steps    = $pillar->steps();
        $expected = $steps['pts_gm'] + $steps['pts_leverage'] + $steps['pts_growth'];
        $this->assertEqualsWithDelta($expected, $steps['score_raw'], 0.001);
    }

    public function test_perfect_company_scores_near_100(): void
    {
        // Maximum pts: gm=4 (very high margin), leverage=3 (very low debt), growth=3 (>10%)
        // Total = 10 → score = 100
        $pillar = new QualityPillar($this->techBenchmark);
        $score  = $pillar->score([
            'gross_margins'   => 0.90,        // gm_delta = 90-55 = 35 → pts_gm = 4
            'total_debt'      => 0,
            'cash'            => 5_000_000,
            'ebitda'          => 3_000_000,   // ratio = 0 → pts_leverage = 3
            'revenue'         => 10_000_000,
            'forward_eps'     => 15.0,
            'trailing_eps'    => 10.0,        // eps growth 50% → pts_growth = 3
            'revenue_growth'  => 0.40,
        ]);

        $this->assertEqualsWithDelta(100.0, $score, 0.1, 'Perfect inputs should score 100');
    }

    public function test_weakest_inputs_score_0(): void
    {
        // pts_gm=0 (very low margin), pts_leverage=0 (huge debt, ebitda >0), pts_growth=0 (no growth)
        $pillar = new QualityPillar($this->techBenchmark);
        $score  = $pillar->score([
            'gross_margins'              => 0.10,  // gm_delta = 10-55 = -45 → 0 pts
            'total_debt'                 => 50_000_000,
            'cash'                       => 100_000,
            'ebitda'                     => 1_000_000,  // ratio = ~50 > 4 → 0 pts
            'revenue'                    => 5_000_000,
            'forward_eps'                => null,
            'trailing_eps'               => null,
            'revenue_growth'             => null,
            'earnings_quarterly_growth'  => null,
        ]);

        $this->assertEqualsWithDelta(0.0, $score, 0.1, 'Worst inputs should score 0');
    }

    // ------------------------------------------------------------------
    // rawScore() accessor
    // ------------------------------------------------------------------

    public function test_raw_score_is_in_0_to_10_range(): void
    {
        $pillar = new QualityPillar($this->techBenchmark);
        $pillar->score($this->baseFinancials());

        $raw = $pillar->rawScore();
        $this->assertGreaterThanOrEqual(0.0, $raw);
        $this->assertLessThanOrEqual(10.0, $raw);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseFinancials(array $overrides = []): array
    {
        return array_merge([
            'sector'                     => 'Technology',
            'gross_margins'              => 0.55,     // 55% — exactly at Technology median
            'total_debt'                 => 2_000_000,
            'cash'                       =>   500_000,
            'ebitda'                     => 3_000_000, // net_debt = 1.5M, ratio = 0.5 → 3 pts
            'revenue'                    => 10_000_000,
            'forward_eps'                => 7.0,
            'trailing_eps'               => 6.0,       // eps growth ~17% → pts_growth = 3
            'revenue_growth'             => 0.12,
            'earnings_quarterly_growth'  => null,
        ], $overrides);
    }
}
