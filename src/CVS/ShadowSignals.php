<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Pure predictive-signal adjustments for the SHADOW model_version 3.2 block
 * (Phase 7, slice 2 — FR-005..FR-008).
 *
 * Mirrors the shape and conventions of `OverlayPenalties`/`EarningsGuard`:
 * static, deterministic, side-effect-free. Every function returns 0.0
 * (no-op adjustment) when its input signal is missing — "missing coverage"
 * never silently moves the score (FR-010, determinism).
 */
final class ShadowSignals
{
    /**
     * Directional PEAD (post-earnings-announcement-drift) guard — replaces the
     * symmetric `EarningsGuard::penalty()` tempering for the 3.2 block.
     *
     * Only active in the post-earnings states ('after' or 'in_transit' —
     * plan-review F2: in_transit is functionally a post-earnings sub-case):
     *   - surprise > 0  -> `cfg['beat_bonus']` (default 0.0 = neutralise the 3.1 guard penalty)
     *   - surprise < 0  -> `max(-cfg['cap'], $basePenalty * cfg['miss_multiplier'])`
     *   - surprise === 0.0 or null -> `$basePenalty` unchanged
     *
     * 'before'/null state -> `$basePenalty` unchanged (parity with 3.1).
     *
     * @param 'before'|'after'|'in_transit'|null $state        EarningsGuard::state()
     * @param float|null                         $surprisePct  EarningsSurpriseParser::surprisePct()
     * @param float                              $basePenalty  3.1 earnings_guard penalty for this ticker (≤ 0)
     * @param array{miss_multiplier?: float, beat_bonus?: float, cap?: float} $cfg
     */
    public static function peadGuard(?string $state, ?float $surprisePct, float $basePenalty, array $cfg): float
    {
        if ($state !== 'after' && $state !== 'in_transit') {
            return $basePenalty;
        }

        if ($surprisePct === null || $surprisePct === 0.0) {
            return $basePenalty;
        }

        if ($surprisePct > 0.0) {
            return (float) ($cfg['beat_bonus'] ?? 0.0);
        }

        $multiplier = (float) ($cfg['miss_multiplier'] ?? 1.0);
        $cap        = (float) ($cfg['cap'] ?? 0.0);

        return round(max(-$cap, $basePenalty * $multiplier), 1);
    }

    /**
     * Analyst-revision-breadth signal — symmetric, clamp(weight * breadth, ±cap).
     *
     * @param float|null $breadth  EarningsTrendParser::revisionBreadth(), in [-1, 1]; null = no coverage
     * @param array{weight?: float, cap?: float} $cfg
     */
    public static function breadth(?float $breadth, array $cfg): float
    {
        if ($breadth === null) {
            return 0.0;
        }

        $weight = (float) ($cfg['weight'] ?? 0.0);
        $cap    = (float) ($cfg['cap']    ?? 0.0);

        return round(max(-$cap, min($cap, $weight * $breadth)), 1);
    }

    /**
     * 52-week-high proximity signal — symmetric around `cfg['baseline']`, with
     * asymmetric caps (plan-review F4: the negative arm is steep near a
     * baseline of 0.85 and partially duplicates the Overlay A "trap" penalty).
     *
     * proximity = price / high
     * adjustment = weight * (proximity - baseline) / (1 - baseline)
     * clamped to [-cap_down, +cap_up]
     *
     * @param float|null $price  current_price; null = no coverage
     * @param float|null $high   fifty_two_week_high; null or <= 0 = no coverage
     * @param array{weight?: float, cap_up?: float, cap_down?: float, baseline?: float} $cfg
     */
    public static function high52w(?float $price, ?float $high, array $cfg): float
    {
        if ($price === null || $high === null || $high <= 0.0) {
            return 0.0;
        }

        $weight   = (float) ($cfg['weight']   ?? 0.0);
        $capUp    = (float) ($cfg['cap_up']   ?? 0.0);
        $capDown  = (float) ($cfg['cap_down'] ?? 0.0);
        $baseline = (float) ($cfg['baseline'] ?? 0.0);

        if ($baseline >= 1.0) {
            return 0.0;
        }

        $proximity = $price / $high;
        $adjustment = $weight * ($proximity - $baseline) / (1.0 - $baseline);

        return round(max(-$capDown, min($capUp, $adjustment)), 1);
    }

    /**
     * Beat-consistency signal — symmetric, clamp(weight * (beatCount - 2) / 2, ±cap).
     * 2/4 beats over the trailing 4 quarters is treated as neutral. `weight`
     * defaults to 0.0 (FR-008: off until calibrated), so this returns 0.0.
     *
     * @param int|null $beatCount  EarningsSurpriseParser::beatCount(), in [0, 4]; null = no coverage
     * @param array{weight?: float, cap?: float} $cfg
     */
    public static function consistency(?int $beatCount, array $cfg): float
    {
        if ($beatCount === null) {
            return 0.0;
        }

        $weight = (float) ($cfg['weight'] ?? 0.0);
        $cap    = (float) ($cfg['cap']    ?? 0.0);

        return round(max(-$cap, min($cap, $weight * ($beatCount - 2) / 2)), 1);
    }
}
