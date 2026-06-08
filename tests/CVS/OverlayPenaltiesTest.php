<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\OverlayPenalties;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OverlayPenalties (Phase 5, slice 1).
 *
 * Pure offline tests of the two deterministic penalty functions — boundary
 * coverage (null inputs, non-negative inputs, trap saturation, caps). The
 * slope/cap values mirror config/cvs-weights.php → overlays (not re-read from
 * config here, to keep these tests focused on the pure arithmetic contract).
 *
 * @see \CVS\Tests\CVS\CVSModelTest for end-to-end golden reproduction of
 *      sim_overlay.php against the real CVSModel pipeline.
 */
class OverlayPenaltiesTest extends TestCase
{
    /** @return array{revision: array{slope: float, cap: float}, target_gate: array{slope: float, cap: float}} */
    private function cfg(): array
    {
        return [
            'revision'    => ['slope' => 120.0, 'cap' => 18.0],
            'target_gate' => ['slope' => 60.0,  'cap' => 18.0],
        ];
    }

    // ------------------------------------------------------------------
    // Overlay A — revision()
    // ------------------------------------------------------------------

    public function test_revision_returns_zero_when_revision_is_null(): void
    {
        $this->assertSame(0.0, OverlayPenalties::revision(80.0, null, $this->cfg()));
    }

    public function test_revision_returns_zero_when_revision_is_zero(): void
    {
        $this->assertSame(0.0, OverlayPenalties::revision(80.0, 0.0, $this->cfg()));
    }

    public function test_revision_returns_zero_when_revision_is_positive(): void
    {
        $this->assertSame(0.0, OverlayPenalties::revision(80.0, 0.08, $this->cfg()));
    }

    public function test_revision_returns_zero_when_trap_is_zero_at_val_score_50(): void
    {
        // trap = clamp((50-50)/50, 0, 1) = 0 → penalty = slope * rev * 0 = 0
        $this->assertSame(0.0, OverlayPenalties::revision(50.0, -0.10, $this->cfg()));
    }

    public function test_revision_clamps_trap_to_zero_below_50(): void
    {
        // valScore < 50 → (valScore-50)/50 is negative → clamp(...,0,1) = 0 → penalty = 0
        $this->assertSame(0.0, OverlayPenalties::revision(20.0, -0.20, $this->cfg()));
    }

    public function test_revision_scales_with_trap_at_partial_saturation(): void
    {
        // valScore = 75 → trap = (75-50)/50 = 0.5
        // penalty = max(-18, 120 * -0.10 * 0.5) = max(-18, -6.0) = -6.0
        $this->assertEqualsWithDelta(-6.0, OverlayPenalties::revision(75.0, -0.10, $this->cfg()), 0.001);
    }

    public function test_revision_full_saturation_at_val_score_100(): void
    {
        // valScore = 100 → trap = clamp((100-50)/50, 0, 1) = 1.0
        // penalty = max(-18, 120 * -0.10 * 1.0) = max(-18, -12.0) = -12.0
        $this->assertEqualsWithDelta(-12.0, OverlayPenalties::revision(100.0, -0.10, $this->cfg()), 0.001);
    }

    public function test_revision_clamps_trap_at_one_above_100(): void
    {
        // valScore beyond 100 still clamps trap to 1.0 (defensive — pillar scores are 0-100)
        $this->assertEqualsWithDelta(-12.0, OverlayPenalties::revision(140.0, -0.10, $this->cfg()), 0.001);
    }

    public function test_revision_is_capped_at_max_penalty(): void
    {
        // valScore = 100 → trap = 1.0; raw = 120 * -0.50 * 1.0 = -60.0 → capped at -18.0
        $this->assertSame(-18.0, OverlayPenalties::revision(100.0, -0.50, $this->cfg()));
    }

    // ------------------------------------------------------------------
    // Overlay B — targetGate()
    // ------------------------------------------------------------------

    public function test_target_gate_returns_zero_when_upside_is_null(): void
    {
        $this->assertSame(0.0, OverlayPenalties::targetGate(null, $this->cfg()));
    }

    public function test_target_gate_returns_zero_when_upside_is_zero(): void
    {
        $this->assertSame(0.0, OverlayPenalties::targetGate(0.0, $this->cfg()));
    }

    public function test_target_gate_returns_zero_when_upside_is_positive(): void
    {
        // Conservative/asymmetric: positive upside is never rewarded.
        $this->assertSame(0.0, OverlayPenalties::targetGate(0.324, $this->cfg()));
    }

    public function test_target_gate_penalises_negative_upside_linearly(): void
    {
        // upside = -0.10 → penalty = max(-18, -0.10 * 60) = max(-18, -6.0) = -6.0
        $this->assertEqualsWithDelta(-6.0, OverlayPenalties::targetGate(-0.10, $this->cfg()), 0.001);
    }

    public function test_target_gate_is_capped_at_max_penalty(): void
    {
        // upside = -0.80 → raw = -0.80 * 60 = -48.0 → capped at -18.0
        $this->assertSame(-18.0, OverlayPenalties::targetGate(-0.80, $this->cfg()));
    }

    public function test_target_gate_at_cap_boundary_is_not_clipped_early(): void
    {
        // upside = -0.30 → raw = -0.30 * 60 = -18.0 → exactly at cap, not below it
        $this->assertSame(-18.0, OverlayPenalties::targetGate(-0.30, $this->cfg()));
    }
}
