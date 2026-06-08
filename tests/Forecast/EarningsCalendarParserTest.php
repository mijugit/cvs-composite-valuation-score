<?php

declare(strict_types=1);

namespace CVS\Tests\Forecast;

use CVS\Forecast\EarningsCalendarParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EarningsCalendarParser (Phase 5, slice 2).
 *
 * Pure offline tests using synthetic raw Yahoo payloads and a FIXED reference
 * date — no network, no fetcher, no `date()`/`time()` (mirrors
 * EarningsTrendParserTest / ForecastParserTest conventions; proves the
 * determinism seam: same (raw, referenceDate) -> identical output).
 */
class EarningsCalendarParserTest extends TestCase
{
    private const SECONDS_PER_DAY = 86400;

    /** Fixed "now" for all tests — 2026-06-08 12:00:00 UTC. */
    private function referenceDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-08 12:00:00', new \DateTimeZone('UTC'));
    }

    /** Yahoo wraps numerics/dates as {"raw": x, "fmt": "y"}. */
    private function num(int $epoch): array
    {
        return ['raw' => $epoch, 'fmt' => date('Y-m-d', $epoch)];
    }

    /** @return array<string, mixed> raw payload with mostRecentQuarter at the given epoch. */
    private function rawWithMostRecentQuarter(?int $epoch): array
    {
        return [
            'defaultKeyStatistics' => $epoch !== null ? ['mostRecentQuarter' => $this->num($epoch)] : [],
        ];
    }

    /** @return array<string, mixed> raw payload with a single or ranged earningsDate. */
    private function rawWithEarningsDate(mixed $earningsDate): array
    {
        return [
            'calendarEvents' => [
                'earnings' => [
                    'earningsDate' => $earningsDate,
                ],
            ],
        ];
    }

    private function daysToEpoch(DateTimeImmutable $ref, int $days): int
    {
        return $ref->getTimestamp() + ($days * self::SECONDS_PER_DAY);
    }

    // ------------------------------------------------------------------
    // days_since_earnings — mostRecentQuarter
    // ------------------------------------------------------------------

    public function test_computes_days_since_last_earnings_from_most_recent_quarter(): void
    {
        $ref  = $this->referenceDate();
        $raw  = $this->rawWithMostRecentQuarter($this->daysToEpoch($ref, -30));

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertSame(30, $result['days_since_earnings']);
    }

    public function test_days_since_earnings_is_zero_for_same_day(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithMostRecentQuarter($ref->getTimestamp());

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertSame(0, $result['days_since_earnings']);
    }

    public function test_days_since_earnings_is_null_when_most_recent_quarter_missing(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithMostRecentQuarter(null);

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertNull($result['days_since_earnings']);
    }

    public function test_days_since_earnings_is_null_when_default_key_statistics_absent(): void
    {
        $result = EarningsCalendarParser::parse([], $this->referenceDate());

        $this->assertNull($result['days_since_earnings']);
    }

    // ------------------------------------------------------------------
    // days_to_earnings — calendarEvents.earnings.earningsDate
    // ------------------------------------------------------------------

    public function test_computes_days_to_next_earnings_from_single_date(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithEarningsDate($this->num($this->daysToEpoch($ref, 5)));

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertSame(5, $result['days_to_earnings']);
    }

    public function test_takes_first_earliest_date_from_a_reporting_window_range(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithEarningsDate([
            $this->num($this->daysToEpoch($ref, 3)),
            $this->num($this->daysToEpoch($ref, 7)),
        ]);

        $result = EarningsCalendarParser::parse($raw, $ref);

        // Conservative: take the EARLIEST date in the range, not the latest.
        $this->assertSame(3, $result['days_to_earnings']);
    }

    public function test_days_to_earnings_can_be_negative_when_calendar_lags_most_recent_quarter(): void
    {
        $ref = $this->referenceDate();
        // The calendar date has technically passed (data lag) — must NOT be clamped to zero.
        $raw = $this->rawWithEarningsDate($this->num($this->daysToEpoch($ref, -2)));

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertSame(-2, $result['days_to_earnings']);
    }

    public function test_days_to_earnings_is_null_when_earnings_date_missing(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithEarningsDate(null);

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertNull($result['days_to_earnings']);
    }

    public function test_days_to_earnings_is_null_when_calendar_events_module_absent(): void
    {
        $result = EarningsCalendarParser::parse([], $this->referenceDate());

        $this->assertNull($result['days_to_earnings']);
    }

    public function test_days_to_earnings_is_null_when_earnings_date_is_empty_array(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithEarningsDate([]);

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertNull($result['days_to_earnings']);
    }

    public function test_days_to_earnings_is_null_when_raw_value_is_non_numeric(): void
    {
        $ref = $this->referenceDate();
        $raw = $this->rawWithEarningsDate(['raw' => 'not-a-number', 'fmt' => '2026-06-15']);

        $result = EarningsCalendarParser::parse($raw, $ref);

        $this->assertNull($result['days_to_earnings']);
    }

    // ------------------------------------------------------------------
    // Determinism — same inputs, same reference date -> identical output
    // ------------------------------------------------------------------

    public function test_is_deterministic_for_identical_inputs(): void
    {
        $ref = $this->referenceDate();
        $raw = array_merge(
            $this->rawWithMostRecentQuarter($this->daysToEpoch($ref, -45)),
            $this->rawWithEarningsDate($this->num($this->daysToEpoch($ref, 4)))
        );

        $first  = EarningsCalendarParser::parse($raw, $ref);
        $second = EarningsCalendarParser::parse($raw, $ref);

        $this->assertSame($first, $second);
    }
}
