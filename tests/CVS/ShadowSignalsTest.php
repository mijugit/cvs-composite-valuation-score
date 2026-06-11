<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\ShadowSignals;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShadowSignals (Phase 7, slice 2 — FR-005..FR-008).
 *
 * Pure offline tests — every branch of peadGuard() plus the symmetric
 * breadth/52w/consistency signals (clamps and null-coverage no-ops).
 */
class ShadowSignalsTest extends TestCase
{
    private const PEAD_CFG = ['miss_multiplier' => 1.5, 'beat_bonus' => 0.0, 'cap' => 10.0];

    // ------------------------------------------------------------------
    // peadGuard
    // ------------------------------------------------------------------

    public function test_pead_guard_before_state_returns_base_penalty_unchanged(): void
    {
        $this->assertSame(-6.0, ShadowSignals::peadGuard('before', -0.05, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_null_state_returns_base_penalty_unchanged(): void
    {
        $this->assertSame(-6.0, ShadowSignals::peadGuard(null, 0.05, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_after_beat_returns_beat_bonus(): void
    {
        $this->assertSame(0.0, ShadowSignals::peadGuard('after', 0.05, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_after_miss_amplifies_base_penalty(): void
    {
        // -6.0 * 1.5 = -9.0
        $this->assertSame(-9.0, ShadowSignals::peadGuard('after', -0.05, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_in_transit_beat_returns_beat_bonus(): void
    {
        $this->assertSame(0.0, ShadowSignals::peadGuard('in_transit', 0.02, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_in_transit_miss_amplifies_base_penalty(): void
    {
        $this->assertSame(-9.0, ShadowSignals::peadGuard('in_transit', -0.02, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_after_null_surprise_returns_base_penalty_unchanged(): void
    {
        $this->assertSame(-6.0, ShadowSignals::peadGuard('after', null, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_after_zero_surprise_returns_base_penalty_unchanged(): void
    {
        $this->assertSame(-6.0, ShadowSignals::peadGuard('after', 0.0, -6.0, self::PEAD_CFG));
    }

    public function test_pead_guard_miss_amplification_is_capped(): void
    {
        // -8.0 * 1.5 = -12.0 -> capped at -10.0
        $this->assertSame(-10.0, ShadowSignals::peadGuard('after', -0.10, -8.0, self::PEAD_CFG));
    }

    public function test_pead_guard_beat_bonus_can_be_nonzero(): void
    {
        $cfg = ['miss_multiplier' => 1.5, 'beat_bonus' => 2.0, 'cap' => 10.0];

        $this->assertSame(2.0, ShadowSignals::peadGuard('after', 0.05, -6.0, $cfg));
    }

    // ------------------------------------------------------------------
    // breadth
    // ------------------------------------------------------------------

    public function test_breadth_returns_zero_when_null(): void
    {
        $this->assertSame(0.0, ShadowSignals::breadth(null, ['weight' => 4.0, 'cap' => 4.0]));
    }

    public function test_breadth_positive_scales_by_weight(): void
    {
        $this->assertSame(2.4, ShadowSignals::breadth(0.6, ['weight' => 4.0, 'cap' => 4.0]));
    }

    public function test_breadth_negative_scales_by_weight(): void
    {
        $this->assertSame(-2.4, ShadowSignals::breadth(-0.6, ['weight' => 4.0, 'cap' => 4.0]));
    }

    public function test_breadth_is_clamped_to_cap(): void
    {
        $this->assertSame(4.0,  ShadowSignals::breadth(1.0,  ['weight' => 4.0, 'cap' => 4.0]));
        $this->assertSame(-4.0, ShadowSignals::breadth(-1.0, ['weight' => 4.0, 'cap' => 4.0]));
    }

    // ------------------------------------------------------------------
    // high52w
    // ------------------------------------------------------------------

    private const HIGH52W_CFG = ['weight' => 8.0, 'cap_up' => 8.0, 'cap_down' => 4.0, 'baseline' => 0.85];

    public function test_high52w_returns_zero_when_price_or_high_missing(): void
    {
        $this->assertSame(0.0, ShadowSignals::high52w(null, 100.0, self::HIGH52W_CFG));
        $this->assertSame(0.0, ShadowSignals::high52w(100.0, null, self::HIGH52W_CFG));
        $this->assertSame(0.0, ShadowSignals::high52w(100.0, 0.0, self::HIGH52W_CFG));
    }

    public function test_high52w_returns_zero_at_baseline_proximity(): void
    {
        // proximity = 85 / 100 = 0.85 == baseline -> 0.0
        $this->assertSame(0.0, ShadowSignals::high52w(85.0, 100.0, self::HIGH52W_CFG));
    }

    public function test_high52w_positive_above_baseline_clamped_to_cap_up(): void
    {
        // proximity = 1.0 -> (1.0 - 0.85) / (1 - 0.85) = 1.0 -> weight * 1.0 = 8.0 -> clamp cap_up=8.0
        $this->assertSame(8.0, ShadowSignals::high52w(100.0, 100.0, self::HIGH52W_CFG));
    }

    public function test_high52w_negative_below_baseline_clamped_to_cap_down(): void
    {
        // proximity = 0.0 -> (0 - 0.85) / 0.15 = -5.667 -> weight * -5.667 = -45.3 -> clamp cap_down=4.0
        $this->assertSame(-4.0, ShadowSignals::high52w(0.0, 100.0, self::HIGH52W_CFG));
    }

    public function test_high52w_returns_zero_when_baseline_is_one_or_more(): void
    {
        $this->assertSame(0.0, ShadowSignals::high52w(100.0, 100.0, ['weight' => 8.0, 'cap_up' => 8.0, 'cap_down' => 4.0, 'baseline' => 1.0]));
    }

    // ------------------------------------------------------------------
    // consistency
    // ------------------------------------------------------------------

    public function test_consistency_returns_zero_when_null(): void
    {
        $this->assertSame(0.0, ShadowSignals::consistency(null, ['weight' => 2.0, 'cap' => 4.0]));
    }

    public function test_consistency_is_zero_by_default_weight(): void
    {
        // FR-008: weight defaults to 0.0 -> always 0.0 regardless of beatCount.
        $this->assertSame(0.0, ShadowSignals::consistency(4, ['weight' => 0.0, 'cap' => 4.0]));
        $this->assertSame(0.0, ShadowSignals::consistency(0, ['weight' => 0.0, 'cap' => 4.0]));
    }

    public function test_consistency_neutral_at_two_of_four(): void
    {
        $this->assertSame(0.0, ShadowSignals::consistency(2, ['weight' => 2.0, 'cap' => 4.0]));
    }

    public function test_consistency_positive_above_neutral(): void
    {
        // weight * (4 - 2) / 2 = weight * 1.0
        $this->assertSame(2.0, ShadowSignals::consistency(4, ['weight' => 2.0, 'cap' => 4.0]));
    }

    public function test_consistency_negative_below_neutral_clamped(): void
    {
        // weight * (0 - 2) / 2 = -weight = -3.0, clamp cap=2.0 -> -2.0
        $this->assertSame(-2.0, ShadowSignals::consistency(0, ['weight' => 3.0, 'cap' => 2.0]));
    }
}
