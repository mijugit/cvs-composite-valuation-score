<?php

declare(strict_types=1);

namespace CVS\Lab;

/**
 * Pure, static NAV-series metrics for the /lab view (change: cvs-experimental-portfolios).
 *
 * No I/O — every method takes an already-fetched series and returns a scalar.
 * Mirrors LabEngine's determinism contract: same input, same output, no clock reads.
 */
final class LabMetrics
{
    /**
     * @param list<array{date: string, nav: float}> $series oldest first
     */
    public static function totalReturnPct(array $series): ?float
    {
        if (count($series) < 2) {
            return null;
        }
        $first = (float) $series[0]['nav'];
        $last  = (float) $series[count($series) - 1]['nav'];
        if ($first <= 0.0) {
            return null;
        }
        return round((($last - $first) / $first) * 100.0, 2);
    }

    /**
     * Max peak-to-trough decline over the series, as a positive percentage
     * (e.g. 12.5 means -12.5% at the worst point). Null if not enough data.
     *
     * @param list<array{date: string, nav: float}> $series oldest first
     */
    public static function maxDrawdownPct(array $series): ?float
    {
        if (count($series) < 2) {
            return null;
        }

        $peak = (float) $series[0]['nav'];
        $worst = 0.0;
        foreach ($series as $point) {
            $nav = (float) $point['nav'];
            if ($nav > $peak) {
                $peak = $nav;
            }
            if ($peak > 0.0) {
                $drawdown = (($peak - $nav) / $peak) * 100.0;
                if ($drawdown > $worst) {
                    $worst = $drawdown;
                }
            }
        }
        return round($worst, 2);
    }

    /**
     * Rebases a series so its own first point becomes 100 — the common
     * basis the /lab chart needs to compare portfolios that started with
     * slightly different day-0 NAV (seed-day fees) on one scale.
     *
     * @param list<array<string, mixed>> $series oldest first, each row has 'date' and $valueKey
     * @return list<array{date: string, value: float}>
     */
    public static function normaliseToBase100(array $series, string $valueKey = 'nav'): array
    {
        if ($series === []) {
            return [];
        }
        $base = (float) $series[0][$valueKey];
        if ($base <= 0.0) {
            return [];
        }
        return array_map(
            static fn (array $p): array => ['date' => (string) $p['date'], 'value' => round(((float) $p[$valueKey] / $base) * 100.0, 3)],
            $series
        );
    }
}
