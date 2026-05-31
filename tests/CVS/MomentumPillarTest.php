<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\Pillars\MomentumPillar;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MomentumPillar (S-05).
 *
 * Tests run fully offline — no API calls required.
 *
 * Primary purpose: regression guard for FR-010 — the sigmoid steepness `sigmoid_k`
 * MUST be read from config, not hardcoded. (It was previously hardcoded as 3.0 in
 * the score() sigmoid; see context/changes/cvs-math-research/research.md.)
 */
class MomentumPillarTest extends TestCase
{
    /**
     * Shared mode-config without sigmoid_k (so individual tests can inject it).
     *
     * @return array<string, float>
     */
    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'momentum_divisor' => 40.0,
            'momentum_cap_min' =>  5.0,
            'momentum_cap_max' => 95.0,
        ], $overrides);
    }

    /**
     * Steadily rising monthly closes → positive but modest composite ROC.
     * Swing roc_weights, no spy_closes → spyCalib falls back to 15.0.
     *
     * Hand-computed (swing weights 1m=0.5, 3m=0.3, 6m=0.2):
     *   now=106, m1=105, m3=103, m6=100
     *   roc1m=0.952%, roc3m=2.913%, roc6m=6.0%
     *   composite = 0.5·0.952 + 0.3·2.913 + 0.2·6.0 ≈ 2.55
     *   excess    = 2.55 − 15 (SPY fallback) = −12.45
     *   normRatio = 1 − (−12.45/40) ≈ 1.311
     *   raw(k=3) = 100/(1+exp(3·0.311)) ≈ 28.23
     *   raw(k=6) = 100/(1+exp(6·0.311)) ≈ 13.40   (both inside [5, 95] cap)
     *
     * @return array<string, mixed>
     */
    private function risingFinancials(): array
    {
        return ['monthly_closes' => [100, 101, 102, 103, 104, 105, 106]];
    }

    /** @return array<string, float> */
    private function swingRocWeights(): array
    {
        return ['1m' => 0.50, '3m' => 0.30, '6m' => 0.20];
    }

    // ------------------------------------------------------------------
    // FR-010 regression: sigmoid_k is config-driven
    // ------------------------------------------------------------------

    public function test_sigmoid_k_is_read_from_config_and_changes_the_score(): void
    {
        $financials = $this->risingFinancials();
        $weights    = $this->swingRocWeights();

        $scoreK3 = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 3.0])))->score($financials, $weights);
        $scoreK6 = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 6.0])))->score($financials, $weights);

        // A steeper k must move a sub-50 (bearish) score further from 50.
        $this->assertNotEqualsWithDelta(
            $scoreK3,
            $scoreK6,
            0.5,
            'sigmoid_k must affect the score — if these are equal, k is hardcoded (FR-010 violation)'
        );
        $this->assertLessThan($scoreK3, $scoreK6, 'larger sigmoid_k → steeper curve → further below 50');
    }

    public function test_sigmoid_k_matches_hand_computed_values(): void
    {
        $financials = $this->risingFinancials();
        $weights    = $this->swingRocWeights();

        $scoreK3 = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 3.0])))->score($financials, $weights);
        $scoreK6 = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 6.0])))->score($financials, $weights);

        $this->assertEqualsWithDelta(28.23, $scoreK3, 0.5, 'k=3 raw ≈ 28.23');
        $this->assertEqualsWithDelta(13.40, $scoreK6, 0.5, 'k=6 raw ≈ 13.40');
    }

    public function test_missing_sigmoid_k_defaults_to_3(): void
    {
        $financials = $this->risingFinancials();
        $weights    = $this->swingRocWeights();

        $scoreDefault = (new MomentumPillar($this->baseConfig()))->score($financials, $weights);
        $scoreK3      = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 3.0])))->score($financials, $weights);

        $this->assertEqualsWithDelta($scoreK3, $scoreDefault, 0.001, 'absent sigmoid_k must default to 3.0');
    }

    // ------------------------------------------------------------------
    // Baseline behaviour
    // ------------------------------------------------------------------

    public function test_score_is_in_range_0_to_100(): void
    {
        $score = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 3.0])))
            ->score($this->risingFinancials(), $this->swingRocWeights());

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    public function test_insufficient_history_returns_neutral_50(): void
    {
        $score = (new MomentumPillar($this->baseConfig(['sigmoid_k' => 3.0])))
            ->score(['monthly_closes' => [100, 101, 102]], $this->swingRocWeights());

        $this->assertSame(50.0, $score, 'fewer than 7 monthly closes → neutral 50');
    }
}
