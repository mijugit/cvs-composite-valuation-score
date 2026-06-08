<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Deterministic post-aggregation overlay penalties (Phase 5, slice 1).
 *
 * Two pure, side-effect-free penalty functions — signatures and formulas are
 * 1:1 with the validated `sim_overlay.php` reference harness:
 *
 *   Overlay A (revision):  punishes a "cheap" valuation thesis when forward EPS
 *     estimates (+1q) are being CUT. The penalty scales with how strongly the
 *     base valuation score is driving the "cheap" narrative (the "trap" factor)
 *     and with the magnitude of the cut. Stable/rising estimates → no penalty.
 *
 *   Overlay B (target gate): punishes a price that already trades ABOVE the
 *     analyst consensus target (negative upside). Conservative and asymmetric —
 *     positive upside is never rewarded (we don't chase price).
 *
 * Both penalties are non-positive (≤ 0), capped, and computed entirely from
 * `$financials` + `config['overlays']` — no randomness, no I/O (FR-010, determinism).
 *
 * @see sim_overlay.php for the reference simulation this mirrors (NVO/AVGO/QCOM/MU).
 */
final class OverlayPenalties
{
    /**
     * Overlay A — revision penalty.
     *
     * trap = clamp((valScore - 50) / 50, 0, 1)   — how much "cheap" drives the score
     * penalty = max(-cap, slope * rev * trap)    — only when rev < 0 (estimate cuts)
     *
     * @param float      $valScore  Base valuation pillar score (0-100)
     * @param float|null $rev       +1q EPS estimate revision, signed fraction (e.g. -0.13); null = no coverage
     * @param array{revision?: array{slope?: float, cap?: float}} $cfg  config['overlays']
     */
    public static function revision(float $valScore, ?float $rev, array $cfg): float
    {
        if ($rev === null || $rev >= 0.0) {
            return 0.0;
        }

        $slope = (float) ($cfg['revision']['slope'] ?? 0.0);
        $cap   = (float) ($cfg['revision']['cap']   ?? 0.0);

        $trap = max(0.0, min(1.0, ($valScore - 50.0) / 50.0));

        return round(max(-$cap, $slope * $rev * $trap), 1);
    }

    /**
     * Overlay B — target-gate penalty.
     *
     * penalty = max(-cap, upside * slope)   — only when upside < 0 (price above target)
     *
     * @param float|null $upside  Analyst target upside, signed fraction (e.g. -0.08); null = no coverage
     * @param array{target_gate?: array{slope?: float, cap?: float}} $cfg  config['overlays']
     */
    public static function targetGate(?float $upside, array $cfg): float
    {
        if ($upside === null || $upside >= 0.0) {
            return 0.0;
        }

        $slope = (float) ($cfg['target_gate']['slope'] ?? 0.0);
        $cap   = (float) ($cfg['target_gate']['cap']   ?? 0.0);

        return round(max(-$cap, $upside * $slope), 1);
    }
}
