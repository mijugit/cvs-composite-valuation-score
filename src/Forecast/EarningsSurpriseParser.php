<?php

declare(strict_types=1);

namespace CVS\Forecast;

/**
 * Pure parser for Yahoo Finance `earningsHistory` data (Phase 7, slice 2).
 *
 * Extracts the most recently reported quarter's EPS surprise and a beat-count
 * across up to the last 4 reported quarters — the inputs feeding the
 * directional PEAD guard (shadow model_version 3.2). Intentionally free of
 * any I/O so it can be unit-tested offline (mirrors EarningsTrendParser — see
 * CLAUDE.md on why FinancialDataFetcher itself stays untested).
 */
final class EarningsSurpriseParser
{
    /**
     * Surprise percent (signed fraction) of the most recently reported quarter.
     *
     * Looks up `earningsHistory.history[]`, picks the row with the highest
     * `quarter.raw` (most recent), and unwraps `surprisePercent.{raw,fmt}`.
     *
     * Returns null (→ "missing coverage", never a silent zero) when:
     *   - `earningsHistory.history` is absent/not an array/empty
     *   - the most recent row has no `surprisePercent.raw`
     *
     * @param array<string, mixed> $raw  Raw quoteSummary result[0]
     */
    public static function surprisePct(array $raw): ?float
    {
        $row = self::mostRecentRow($raw);

        if ($row === null) {
            return null;
        }

        return self::raw($row['surprisePercent'] ?? null);
    }

    /**
     * Number of beats (surprisePercent > 0) among the available reported
     * quarters (up to 4).
     *
     * Returns null (distinct from "0 beats") when `earningsHistory.history`
     * is absent/not an array/empty.
     *
     * @param array<string, mixed> $raw  Raw quoteSummary result[0]
     */
    public static function beatCount(array $raw): ?int
    {
        $history = $raw['earningsHistory']['history'] ?? null;

        if (!is_array($history) || $history === []) {
            return null;
        }

        $count = 0;

        foreach (array_slice($history, -4) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $surprise = self::raw($row['surprisePercent'] ?? null);

            if ($surprise !== null && $surprise > 0.0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Pick the row for the most recently reported quarter from
     * `earningsHistory.history[]`, ranked by `quarter.raw` (epoch timestamp).
     * Falls back to the last array entry when `quarter.raw` is missing on
     * every row (Yahoo orders history oldest-first).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function mostRecentRow(array $raw): ?array
    {
        $history = $raw['earningsHistory']['history'] ?? null;

        if (!is_array($history) || $history === []) {
            return null;
        }

        $best      = null;
        $bestEpoch = null;

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $epoch = self::raw($row['quarter'] ?? null);

            if ($epoch !== null && ($bestEpoch === null || $epoch > $bestEpoch)) {
                $bestEpoch = $epoch;
                $best      = $row;
            }
        }

        if ($best !== null) {
            return $best;
        }

        // No row carried a usable `quarter.raw` — fall back to the last entry.
        $last = end($history);

        return is_array($last) ? $last : null;
    }

    /** Unwrap Yahoo's {"raw": x, "fmt": "y"} value objects. */
    private static function raw(mixed $obj): ?float
    {
        return is_array($obj) && isset($obj['raw']) && is_numeric($obj['raw'])
            ? (float) $obj['raw']
            : null;
    }
}
