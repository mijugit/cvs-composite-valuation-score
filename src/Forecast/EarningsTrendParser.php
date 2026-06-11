<?php

declare(strict_types=1);

namespace CVS\Forecast;

/**
 * Pure parser for Yahoo Finance earnings-estimate revision data (Phase 5, slice 1).
 *
 * Extracts the +1q (next-quarter) consensus EPS estimate revision as a signed
 * fraction — the input feeding Overlay A (revision penalty, shadow model_version
 * 3.1). Intentionally free of any I/O so it can be unit-tested offline (mirrors
 * ForecastParser — see CLAUDE.md on why FinancialDataFetcher itself stays
 * untested).
 */
final class EarningsTrendParser
{
    /**
     * Compute the +1q consensus EPS estimate revision as a signed fraction.
     *
     * Looks up `earningsTrend.trend[]` for the row with `period === '+1q'`,
     * then compares `epsTrend.current` against `epsTrend.90daysAgo`:
     *
     *   revisionPct = (current / 90daysAgo) - 1
     *
     * e.g. current = 1.74, 90daysAgo = 2.00 → -0.13 (estimate cut 13 %).
     *
     * Returns null (→ "missing coverage" in the model, never a silent zero) when:
     *   - `earningsTrend.trend` is absent/not an array
     *   - no row has `period === '+1q'`
     *   - `epsTrend.90daysAgo` is missing, zero, or negative (division guard)
     *   - `epsTrend.current` is missing
     *
     * @param array<string, mixed> $raw  Raw quoteSummary result[0]
     */
    public static function revisionPct(array $raw): ?float
    {
        $trend = $raw['earningsTrend']['trend'] ?? null;

        if (!is_array($trend)) {
            return null;
        }

        foreach ($trend as $row) {
            if (!is_array($row) || ($row['period'] ?? null) !== '+1q') {
                continue;
            }

            $epsTrend = $row['epsTrend'] ?? null;
            if (!is_array($epsTrend)) {
                return null;
            }

            $current = self::raw($epsTrend['current']   ?? null);
            $ago     = self::raw($epsTrend['90daysAgo'] ?? null);

            if ($current === null || $ago === null || $ago <= 0.0) {
                return null;
            }

            return ($current / $ago) - 1.0;
        }

        return null;
    }

    /**
     * Compute the +1q analyst-revision breadth as a signed fraction in [-1, 1].
     *
     * Looks up `earningsTrend.trend[]` for the row with `period === '+1q'`,
     * then reads `epsRevisions.upLast30days` / `downLast30days`:
     *
     *   breadth = (up30d - down30d) / (up30d + down30d)
     *
     * e.g. up=8, down=2 → 0.6 (broad upward consensus shift). Orthogonal to
     * the magnitude-based revisionPct() above (Overlay A) — this measures how
     * many analysts moved, not by how much.
     *
     * Returns null (→ "missing coverage", never a silent zero) when:
     *   - `earningsTrend.trend` is absent/not an array
     *   - no row has `period === '+1q'`
     *   - `epsRevisions` is missing on that row
     *   - `upLast30days`/`downLast30days` are missing
     *   - the denominator (up30d + down30d) is 0 (zero opinions != neutral consensus)
     *
     * @param array<string, mixed> $raw  Raw quoteSummary result[0]
     */
    public static function revisionBreadth(array $raw): ?float
    {
        $trend = $raw['earningsTrend']['trend'] ?? null;

        if (!is_array($trend)) {
            return null;
        }

        foreach ($trend as $row) {
            if (!is_array($row) || ($row['period'] ?? null) !== '+1q') {
                continue;
            }

            $revisions = $row['epsRevisions'] ?? null;
            if (!is_array($revisions)) {
                return null;
            }

            $up   = self::raw($revisions['upLast30days']   ?? null);
            $down = self::raw($revisions['downLast30days'] ?? null);

            if ($up === null || $down === null) {
                return null;
            }

            $denominator = $up + $down;
            if ($denominator === 0.0) {
                return null;
            }

            return ($up - $down) / $denominator;
        }

        return null;
    }

    /** Unwrap Yahoo's {"raw": x, "fmt": "y"} value objects. */
    private static function raw(mixed $obj): ?float
    {
        return is_array($obj) && isset($obj['raw']) && is_numeric($obj['raw'])
            ? (float) $obj['raw']
            : null;
    }
}
