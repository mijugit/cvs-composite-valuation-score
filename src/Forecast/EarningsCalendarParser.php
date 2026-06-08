<?php

declare(strict_types=1);

namespace CVS\Forecast;

use DateTimeImmutable;

/**
 * Pure parser for Yahoo Finance earnings-timing data (Phase 5, slice 2).
 *
 * Extracts "days since last earnings" and "days to next earnings" as plain
 * integers, computed against an injected reference date — never `date()`/
 * `time()` internally. This is the determinism seam (FR-015): the fetcher
 * determines "now" exactly once (fetch-time) and hands it down here, so the
 * parser stays a pure function of (raw payload, reference date) and is fully
 * unit-testable offline (mirrors EarningsTrendParser / ForecastParser — see
 * CLAUDE.md on why FinancialDataFetcher itself stays untested).
 */
final class EarningsCalendarParser
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * Compute days-since/days-to-earnings relative to `$referenceDate`.
     *
     * @param array<string, mixed> $raw            Raw quoteSummary result[0]
     * @param DateTimeImmutable    $referenceDate  Fetch-time "now" (injected — determinism seam)
     * @return array{days_since_earnings: ?int, days_to_earnings: ?int}
     */
    public static function parse(array $raw, DateTimeImmutable $referenceDate): array
    {
        return [
            'days_since_earnings' => self::daysSinceLastEarnings($raw, $referenceDate),
            'days_to_earnings'    => self::daysToNextEarnings($raw, $referenceDate),
        ];
    }

    /**
     * Days since the most recently reported quarter, from
     * `defaultKeyStatistics.mostRecentQuarter` (epoch timestamp).
     *
     * daysSince = floor((referenceDate - mostRecentQuarter) / 86400)
     *
     * Returns null when the field is missing/non-numeric — "missing coverage",
     * never a silent zero (mirrors EarningsTrendParser convention).
     *
     * @param array<string, mixed> $raw
     */
    private static function daysSinceLastEarnings(array $raw, DateTimeImmutable $referenceDate): ?int
    {
        $epoch = self::epoch($raw['defaultKeyStatistics']['mostRecentQuarter'] ?? null);

        if ($epoch === null) {
            return null;
        }

        $deltaSeconds = $referenceDate->getTimestamp() - $epoch;

        return (int) floor($deltaSeconds / self::SECONDS_PER_DAY);
    }

    /**
     * Days to the next scheduled earnings date, from
     * `calendarEvents.earnings.earningsDate` (epoch timestamp, or an array of
     * 1-2 timestamps when Yahoo reports a reporting-window range — we take the
     * first/earliest entry, conservatively assuming the soonest possible date).
     *
     * daysTo = ceil((earningsDate - referenceDate) / 86400)
     *
     * May be NEGATIVE when the calendar date has technically passed but Yahoo's
     * `mostRecentQuarter` hasn't caught up yet (a known data-lag) — this is a
     * deliberate signal (the 'in_transit' state in EarningsGuard), not an error;
     * do not clamp to zero here.
     *
     * Returns null when the field is missing/non-numeric — "missing coverage".
     *
     * @param array<string, mixed> $raw
     */
    private static function daysToNextEarnings(array $raw, DateTimeImmutable $referenceDate): ?int
    {
        $earningsDate = $raw['calendarEvents']['earnings']['earningsDate'] ?? null;

        $epoch = self::firstEarningsDateEpoch($earningsDate);

        if ($epoch === null) {
            return null;
        }

        $deltaSeconds = $epoch - $referenceDate->getTimestamp();

        return (int) ceil($deltaSeconds / self::SECONDS_PER_DAY);
    }

    /**
     * `earningsDate` is sometimes a single {"raw": x, "fmt": "y"} object and
     * sometimes a list of such objects (a reporting-window range, e.g. two
     * possible dates). We conservatively take the first/earliest entry so the
     * guard activates no later than necessary.
     */
    private static function firstEarningsDateEpoch(mixed $earningsDate): ?int
    {
        if (!is_array($earningsDate)) {
            return null;
        }

        // Single {"raw": x, "fmt": "y"} object.
        if (isset($earningsDate['raw'])) {
            return self::epoch($earningsDate);
        }

        // List of {"raw": x, "fmt": "y"} objects — take the first.
        foreach ($earningsDate as $entry) {
            $epoch = self::epoch($entry);
            if ($epoch !== null) {
                return $epoch;
            }
        }

        return null;
    }

    /** Unwrap Yahoo's {"raw": x, "fmt": "y"} value objects into an integer epoch timestamp. */
    private static function epoch(mixed $obj): ?int
    {
        return is_array($obj) && isset($obj['raw']) && is_numeric($obj['raw'])
            ? (int) $obj['raw']
            : null;
    }
}
