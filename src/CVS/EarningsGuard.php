<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Deterministic earnings-proximity guard (Phase 5, slice 2).
 *
 * Two pure, side-effect-free functions — mirror of `OverlayPenalties`'
 * shape and conventions:
 *
 *   state():   classifies "where are we relative to the earnings date" —
 *     'before' | 'after' | 'in_transit' | null — purely from the pre-computed
 *     `days_to_earnings` / `days_since_earnings` integers (never `date()`/
 *     `time()` — those are fixed at fetch-time, see EarningsCalendarParser
 *     and the FR-015 determinism seam).
 *
 *   penalty(): a proximity-based tempering penalty (≤ 0, capped) layered into
 *     the shadow overlay's `penalties.total` (Phase 5, slice 1 pattern) — momentum
 *     conversion is less trustworthy in the ~K-session window around an earnings
 *     event, so the shadow score is gently tempered, never hard-punished.
 *
 * Both are pure functions of (days_to, days_since, config) — no randomness,
 * no I/O (FR-010, determinism).
 */
final class EarningsGuard
{
    /**
     * Classify the earnings-timing state relative to a symmetric K-session window.
     *
     * - 'before':     0 ≤ days_to_earnings ≤ K           — upcoming earnings within the window
     * - 'after':      0 ≤ days_since_earnings ≤ K        — earnings reported within the window
     * - 'in_transit': days_to_earnings < 0 AND 'after'   — Yahoo's calendar shows a passed
     *                 date that hasn't rolled over to the next quarter yet (a known data-lag);
     *                 checked BEFORE 'before'/'after' so the lag signal isn't masked by 'after'
     * - null:         outside the window, or insufficient data, or window disabled (K ≤ 0)
     *
     * @param int|null $daysToEarnings    May be negative (data-lag signal — see EarningsCalendarParser)
     * @param int|null $daysSinceEarnings Always ≥ 0 when present (a past date)
     * @param int      $windowSessions    K — symmetric before/after window, in sessions (config: earnings_guard.window_sessions)
     * @return 'before'|'after'|'in_transit'|null
     */
    public static function state(?int $daysToEarnings, ?int $daysSinceEarnings, int $windowSessions): ?string
    {
        if ($windowSessions <= 0) {
            return null;
        }

        $isBefore = self::withinWindow($daysToEarnings, $windowSessions);
        $isAfter  = self::withinWindow($daysSinceEarnings, $windowSessions);

        // 'in_transit' is the more specific signal — a stale calendar date (days_to < 0)
        // co-occurring with a freshly-reported quarter (days_since within the window).
        // Check it first so it isn't swallowed by the plain 'after' branch.
        if ($daysToEarnings !== null && $daysToEarnings < 0 && $isAfter) {
            return 'in_transit';
        }

        return match (true) {
            $isBefore => 'before',
            $isAfter  => 'after',
            default   => null,
        };
    }

    /**
     * Proximity-based tempering penalty, ≤ 0, capped.
     *
     * proximity = max(0, (K - nearest_in_window_days) / K) ∈ [0, 1]
     *   — "nearest_in_window_days" is the smaller of days_to/days_since that
     *     actually falls within [0, K]; candidates outside the window don't count.
     * penalty   = round(max(-cap, -slope * proximity), 1)
     *
     * Returns 0.0 when the guard is disabled, the window is misconfigured (K ≤ 0),
     * or neither day-count falls within the window (no proximity signal).
     *
     * @param int|null $daysToEarnings
     * @param int|null $daysSinceEarnings
     * @param array{enabled?: bool, window_sessions?: int, penalty?: array{slope?: float, cap?: float}} $cfg  config['earnings_guard']
     */
    public static function penalty(?int $daysToEarnings, ?int $daysSinceEarnings, array $cfg): float
    {
        if (empty($cfg['enabled'])) {
            return 0.0;
        }

        $windowSessions = (int) ($cfg['window_sessions'] ?? 0);

        if ($windowSessions <= 0) {
            return 0.0;
        }

        $candidates = array_filter(
            [$daysToEarnings, $daysSinceEarnings],
            static fn (?int $days): bool => self::withinWindow($days, $windowSessions)
        );

        if ($candidates === []) {
            return 0.0;
        }

        $slope = (float) ($cfg['penalty']['slope'] ?? 0.0);
        $cap   = (float) ($cfg['penalty']['cap']   ?? 0.0);

        $proximity = max(0.0, ($windowSessions - min($candidates)) / $windowSessions);

        return round(max(-$cap, -$slope * $proximity), 1);
    }

    /** True when `$days` is a non-negative count that falls within [0, K]. */
    private static function withinWindow(?int $days, int $windowSessions): bool
    {
        return $days !== null && $days >= 0 && $days <= $windowSessions;
    }
}
