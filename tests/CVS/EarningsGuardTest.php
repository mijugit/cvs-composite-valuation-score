<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\EarningsGuard;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EarningsGuard (Phase 5, slice 2).
 *
 * Pure offline tests of the two deterministic functions — state classification
 * and the proximity-based tempering penalty. Boundary coverage (K edges, the
 * three named states + null, proximity 0/partial/1.0, caps, null/disabled inputs)
 * mirrors OverlayPenaltiesTest conventions (Phase 5, slice 1).
 *
 * @see \CVS\Tests\CVS\CVSModelTest for end-to-end coverage of the always-present
 *      `earnings_timing` badge and the shadow `penalties.earnings_guard` wiring.
 */
class EarningsGuardTest extends TestCase
{
    private const K = 5;

    /** @return array{enabled: bool, window_sessions: int, penalty: array{slope: float, cap: float}} */
    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'enabled'         => true,
            'window_sessions' => self::K,
            'penalty'         => ['slope' => 10.0, 'cap' => 10.0],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // state()
    // ------------------------------------------------------------------

    public function test_state_is_before_when_days_to_within_window(): void
    {
        $this->assertSame('before', EarningsGuard::state(3, null, self::K));
    }

    public function test_state_before_includes_lower_boundary_zero(): void
    {
        $this->assertSame('before', EarningsGuard::state(0, null, self::K));
    }

    public function test_state_before_includes_upper_boundary_k(): void
    {
        $this->assertSame('before', EarningsGuard::state(self::K, null, self::K));
    }

    public function test_state_is_null_just_outside_before_window(): void
    {
        $this->assertNull(EarningsGuard::state(self::K + 1, null, self::K));
    }

    public function test_state_is_after_when_days_since_within_window(): void
    {
        $this->assertSame('after', EarningsGuard::state(null, 2, self::K));
    }

    public function test_state_after_includes_lower_boundary_zero(): void
    {
        $this->assertSame('after', EarningsGuard::state(null, 0, self::K));
    }

    public function test_state_after_includes_upper_boundary_k(): void
    {
        $this->assertSame('after', EarningsGuard::state(null, self::K, self::K));
    }

    public function test_state_is_null_just_outside_after_window(): void
    {
        $this->assertNull(EarningsGuard::state(null, self::K + 1, self::K));
    }

    public function test_state_is_in_transit_when_calendar_lags_and_recently_reported(): void
    {
        // Calendar shows a passed date (days_to < 0) AND mostRecentQuarter is fresh
        // (days_since within the window) — the classic Yahoo data-lag signal.
        $this->assertSame('in_transit', EarningsGuard::state(-2, 1, self::K));
    }

    public function test_state_in_transit_takes_precedence_over_after(): void
    {
        // Both 'after' and 'in_transit' conditions are technically satisfied —
        // 'in_transit' (the more specific, lag-aware signal) must win.
        $this->assertSame('in_transit', EarningsGuard::state(-1, 0, self::K));
    }

    public function test_state_negative_days_to_without_recent_report_is_null(): void
    {
        // Stale calendar date, but mostRecentQuarter is old too → no coherent signal.
        $this->assertNull(EarningsGuard::state(-3, self::K + 10, self::K));
    }

    public function test_state_is_null_when_both_inputs_missing(): void
    {
        $this->assertNull(EarningsGuard::state(null, null, self::K));
    }

    public function test_state_is_null_when_window_is_zero(): void
    {
        $this->assertNull(EarningsGuard::state(0, 0, 0));
    }

    public function test_state_is_null_when_window_is_negative(): void
    {
        $this->assertNull(EarningsGuard::state(1, 1, -1));
    }

    // ------------------------------------------------------------------
    // penalty()
    // ------------------------------------------------------------------

    public function test_penalty_is_zero_when_guard_disabled(): void
    {
        $this->assertSame(0.0, EarningsGuard::penalty(0, null, $this->cfg(['enabled' => false])));
    }

    public function test_penalty_is_zero_when_window_sessions_is_zero(): void
    {
        $this->assertSame(0.0, EarningsGuard::penalty(0, null, $this->cfg(['window_sessions' => 0])));
    }

    public function test_penalty_is_zero_when_both_inputs_missing(): void
    {
        $this->assertSame(0.0, EarningsGuard::penalty(null, null, $this->cfg()));
    }

    public function test_penalty_is_zero_when_outside_the_window(): void
    {
        $this->assertSame(0.0, EarningsGuard::penalty(self::K + 1, self::K + 10, $this->cfg()));
    }

    public function test_penalty_is_at_full_strength_at_zero_days(): void
    {
        // proximity = (5 - 0) / 5 = 1.0 → penalty = max(-10, -10 * 1.0) = -10.0
        $this->assertSame(-10.0, EarningsGuard::penalty(0, null, $this->cfg()));
    }

    public function test_penalty_is_zero_at_far_edge_of_window(): void
    {
        // proximity = (5 - 5) / 5 = 0.0 → penalty = max(-10, -10 * 0.0) = -0.0 (rounds to 0.0)
        $this->assertSame(-0.0, EarningsGuard::penalty(self::K, null, $this->cfg()));
    }

    public function test_penalty_scales_at_partial_proximity(): void
    {
        // days_to = 2 → proximity = (5 - 2) / 5 = 0.6 → penalty = max(-10, -10 * 0.6) = -6.0
        $this->assertEqualsWithDelta(-6.0, EarningsGuard::penalty(2, null, $this->cfg()), 0.001);
    }

    public function test_penalty_uses_days_since_when_in_after_window(): void
    {
        // days_since = 1 → proximity = (5 - 1) / 5 = 0.8 → penalty = max(-10, -10 * 0.8) = -8.0
        $this->assertEqualsWithDelta(-8.0, EarningsGuard::penalty(null, 1, $this->cfg()), 0.001);
    }

    public function test_penalty_takes_the_nearest_candidate_when_both_present(): void
    {
        // days_to = 4 (proximity 0.2), days_since = 1 (proximity 0.8) → nearest wins → -8.0
        $this->assertEqualsWithDelta(-8.0, EarningsGuard::penalty(4, 1, $this->cfg()), 0.001);
    }

    public function test_penalty_ignores_out_of_window_candidate(): void
    {
        // days_to = 50 (outside window, ignored), days_since = 3 (proximity 0.4) → -4.0
        $this->assertEqualsWithDelta(-4.0, EarningsGuard::penalty(50, 3, $this->cfg()), 0.001);
    }

    public function test_penalty_ignores_negative_days_to_as_a_candidate(): void
    {
        // days_to = -2 is never a withinWindow candidate (guarded against by >= 0);
        // only days_since = 2 counts → proximity = 0.6 → -6.0
        $this->assertEqualsWithDelta(-6.0, EarningsGuard::penalty(-2, 2, $this->cfg()), 0.001);
    }

    public function test_penalty_is_capped_at_max_penalty(): void
    {
        // slope=50 would compute -50 at full proximity → capped at -cap=-10
        $cfg = $this->cfg(['penalty' => ['slope' => 50.0, 'cap' => 10.0]]);
        $this->assertSame(-10.0, EarningsGuard::penalty(0, null, $cfg));
    }

    public function test_penalty_is_deterministic(): void
    {
        $cfg = $this->cfg();

        $first  = EarningsGuard::penalty(2, 1, $cfg);
        $second = EarningsGuard::penalty(2, 1, $cfg);

        $this->assertSame($first, $second);
    }
}
