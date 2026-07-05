<?php

declare(strict_types=1);

namespace CVS\Lab;

/**
 * Pure, static statistical inference for the /lab hypothesis chips
 * (change: cvs-experimental-portfolios, Phase 4).
 *
 * Percentile bootstrap on daily log-return differences (variant vs. the
 * portfolio named in hypothesis.versus) — deliberately conservative: a chip
 * only reads "supported"/"refuted" once the whole 95% CI clears zero on the
 * hypothesized side. No I/O, no clock reads; determinism comes from calling
 * mt_srand($seed) fresh inside bootstrapCiOfMeanDiff on every invocation.
 */
final class LabStats
{
    /**
     * Daily log-returns from a NAV series, oldest first. First point has no
     * prior day to diff against, so the result is always one shorter.
     *
     * @param list<array{date: string, nav: float}> $navSeries
     * @return list<array{date: string, ret: float}>
     */
    public static function dailyReturns(array $navSeries): array
    {
        $out = [];
        for ($i = 1; $i < count($navSeries); $i++) {
            $prev = (float) $navSeries[$i - 1]['nav'];
            $cur  = (float) $navSeries[$i]['nav'];
            if ($prev <= 0.0 || $cur <= 0.0) {
                continue;
            }
            $out[] = ['date' => (string) $navSeries[$i]['date'], 'ret' => log($cur / $prev)];
        }
        return $out;
    }

    /**
     * Pairs two daily-return series by date and returns variant-minus-reference
     * diffs for dates present in both — defends against the two series having
     * slightly different session sets (e.g. one portfolio missing a NAV row).
     *
     * @param list<array{date: string, ret: float}> $variantReturns
     * @param list<array{date: string, ret: float}> $referenceReturns
     * @return list<float>
     */
    public static function pairedDiffs(array $variantReturns, array $referenceReturns): array
    {
        $refByDate = [];
        foreach ($referenceReturns as $r) {
            $refByDate[$r['date']] = $r['ret'];
        }

        $diffs = [];
        foreach ($variantReturns as $v) {
            if (array_key_exists($v['date'], $refByDate)) {
                $diffs[] = $v['ret'] - $refByDate[$v['date']];
            }
        }
        return $diffs;
    }

    /**
     * Percentile bootstrap 95% CI of the mean of $diffs. Empty input has no
     * meaningful mean, so it collapses to a zero-width CI at 0.0 (read as
     * "too_early" by hypothesisStatus() via the n=0 < min_sessions guard).
     *
     * @param list<float> $diffs
     * @return array{0: float, 1: float} [lo, hi]
     */
    public static function bootstrapCiOfMeanDiff(array $diffs, int $iterations, int $seed): array
    {
        $n = count($diffs);
        if ($n === 0 || $iterations < 1) {
            return [0.0, 0.0];
        }

        mt_srand($seed);
        $means = [];
        for ($b = 0; $b < $iterations; $b++) {
            $sum = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $sum += $diffs[mt_rand(0, $n - 1)];
            }
            $means[] = $sum / $n;
        }
        sort($means);

        $loIdx = (int) floor(0.025 * ($iterations - 1));
        $hiIdx = (int) ceil(0.975 * ($iterations - 1));

        return [$means[$loIdx], $means[$hiIdx]];
    }

    /**
     * Maps a CI + session count + hypothesis direction to one of four statuses:
     *   too_early     — n below the configured minimum, regardless of CI
     *   inconclusive  — CI straddles zero (can't tell direction apart from noise)
     *   supported     — CI entirely on the hypothesized side of zero
     *   refuted       — CI entirely on the opposite side
     *
     * @param array{0: float, 1: float} $ci
     * @param array{claim: string, source: string, versus: string, direction: string} $hypothesis
     * @param array{bootstrap_iterations?: int, bootstrap_seed?: int, min_sessions?: int} $statsCfg
     */
    public static function hypothesisStatus(array $ci, int $n, array $hypothesis, array $statsCfg): string
    {
        $minSessions = (int) ($statsCfg['min_sessions'] ?? 0);
        if ($n < $minSessions) {
            return 'too_early';
        }

        [$lo, $hi] = $ci;
        if ($lo <= 0.0 && $hi >= 0.0) {
            return 'inconclusive';
        }

        $isPositive = $lo > 0.0;
        $direction  = $hypothesis['direction'];

        if ($direction === 'above') {
            return $isPositive ? 'supported' : 'refuted';
        }
        if ($direction === 'below') {
            return $isPositive ? 'refuted' : 'supported';
        }
        return 'inconclusive';
    }
}
