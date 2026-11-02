<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FundamentalOverrideMerger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FundamentalOverrideMergerTest extends TestCase
{
    public function test_validated_row_overwrites_field_with_correctly_typed_value(): void
    {
        $financials = ['gross_profit' => null];
        $overrides  = [
            'gross_profit' => [
                'value'        => '6195700000',
                'status'       => 'validated',
                'source'       => 'gemini_validation',
                'validated_at' => '2026-08-21 12:00:00',
            ],
        ];

        $merged = FundamentalOverrideMerger::merge($financials, $overrides);

        $this->assertSame(6_195_700_000.0, $merged['gross_profit']);
        $this->assertIsFloat($merged['gross_profit']);
    }

    public function test_int_typed_field_casts_to_int(): void
    {
        $financials = ['days_since_earnings' => 3007];
        $overrides  = [
            'days_since_earnings' => [
                'value'        => '81',
                'status'       => 'validated',
                'source'       => 'gemini_validation',
                'validated_at' => '2026-08-21 12:00:00',
            ],
        ];

        $merged = FundamentalOverrideMerger::merge($financials, $overrides);

        $this->assertSame(81, $merged['days_since_earnings']);
        $this->assertIsInt($merged['days_since_earnings']);
    }

    public function test_checked_no_data_row_is_a_noop(): void
    {
        $financials = ['gross_profit' => null];
        $overrides  = [
            'gross_profit' => [
                'value'        => null,
                'status'       => 'checked_no_data',
                'source'       => 'gemini_validation',
                'validated_at' => '2026-08-21 12:00:00',
            ],
        ];

        $merged = FundamentalOverrideMerger::merge($financials, $overrides);

        $this->assertNull($merged['gross_profit']);
    }

    public function test_field_absent_from_registry_is_a_noop(): void
    {
        $financials = ['sector' => 'Consumer Defensive'];
        $overrides  = [
            'sector' => [
                'value'        => 'Financial Services',
                'status'       => 'validated',
                'source'       => 'gemini_validation',
                'validated_at' => '2026-08-21 12:00:00',
            ],
        ];

        $merged = FundamentalOverrideMerger::merge($financials, $overrides);

        $this->assertSame('Consumer Defensive', $merged['sector']);
    }

    public function test_empty_overrides_returns_financials_unchanged(): void
    {
        $financials = ['revenue' => 18_424_600_576];

        $merged = FundamentalOverrideMerger::merge($financials, []);

        $this->assertSame($financials, $merged);
    }

    // ------------------------------------------------------------------
    // Release rule (2026-11-02): an override must not outlive the Yahoo error
    // it corrected. TSM scored for two months on figures frozen on 08-27.
    // ------------------------------------------------------------------

    private const POLICY = ['release_tolerance' => 0.05, 'release_after_earnings' => true, 'max_days_since_earnings' => 120];

    /** @return array{value: string, status: string, source: string, validated_at: string} */
    private static function row(string $value, string $validatedAt = '2026-08-27 19:07:34', string $status = 'validated'): array
    {
        return ['value' => $value, 'status' => $status, 'source' => 'gemini_validation', 'validated_at' => $validatedAt];
    }

    public function test_override_confirmed_by_yahoo_within_tolerance_is_released(): void
    {
        $live = ['free_cash_flow' => 41_000_000_000.0, 'days_since_earnings' => 200];
        $rows = ['free_cash_flow' => self::row('41595817052.178')];

        $released = FundamentalOverrideMerger::releasable($live, $rows, self::POLICY, new DateTimeImmutable('2026-09-10'));

        $this->assertSame(['free_cash_flow' => FundamentalOverrideMerger::RELEASE_CONFIRMED], $released);
    }

    public function test_override_yahoo_still_disagrees_with_is_kept(): void
    {
        // Yahoo still broken (e.g. FCF above its own operating cash flow) and no
        // report since confirmation: the correction stays in force.
        $live = ['free_cash_flow' => 90_000_000_000.0, 'days_since_earnings' => 30];
        $rows = ['free_cash_flow' => self::row('41595817052.178', '2026-08-27 19:07:34')];
        $asOf = new DateTimeImmutable('2026-09-10'); // last report 2026-08-11, before confirmation

        $this->assertSame([], FundamentalOverrideMerger::releasable($live, $rows, self::POLICY, $asOf));
        $this->assertSame(41595817052.178, FundamentalOverrideMerger::merge($live, $rows, self::POLICY, $asOf)['free_cash_flow']);
    }

    public function test_override_confirmed_before_latest_report_is_released_as_stale(): void
    {
        // TSM: confirmed 2026-08-27, reported Q3 on 2026-10-16 (17 days before
        // 2026-11-02). Yahoo has moved on to the new quarter.
        $live = ['free_cash_flow' => 36_900_000_000.0, 'trailing_eps' => 15.43, 'days_since_earnings' => 17];
        $rows = ['free_cash_flow' => self::row('41595817052.178'), 'trailing_eps' => self::row('13.44')];
        $asOf = new DateTimeImmutable('2026-11-02 22:45:00');

        $released = FundamentalOverrideMerger::releasable($live, $rows, self::POLICY, $asOf);
        $merged   = FundamentalOverrideMerger::merge($live, $rows, self::POLICY, $asOf);

        $this->assertSame(['free_cash_flow' => 'stale', 'trailing_eps' => 'stale'], $released);
        $this->assertSame(36_900_000_000.0, $merged['free_cash_flow'], 'live Yahoo value again');
        $this->assertSame(15.43, $merged['trailing_eps']);
    }

    public function test_confirmation_on_the_report_day_itself_is_not_stale(): void
    {
        $live = ['free_cash_flow' => 90.0, 'days_since_earnings' => 3];
        $rows = ['free_cash_flow' => self::row('40', '2026-10-30 08:00:00')];

        $this->assertSame([], FundamentalOverrideMerger::releasable($live, $rows, self::POLICY, new DateTimeImmutable('2026-11-02 23:59:00')));
    }

    public function test_implausible_days_since_earnings_does_not_date_a_report(): void
    {
        // Yahoo has returned days_since_earnings ~37x off; such a value must not
        // silently release every override.
        $live = ['free_cash_flow' => 90.0, 'days_since_earnings' => 3007];
        $rows = ['free_cash_flow' => self::row('40', '2026-08-27 19:07:34')];

        $this->assertSame([], FundamentalOverrideMerger::releasable($live, $rows, self::POLICY, new DateTimeImmutable('2026-11-02')));
    }

    public function test_released_and_checked_no_data_rows_are_never_merged_or_released(): void
    {
        $live = ['free_cash_flow' => 41.0, 'days_since_earnings' => 1];
        $rows = [
            'free_cash_flow' => self::row('40', '2026-01-01 00:00:00', 'released'),
            'gross_profit'   => ['value' => null, 'status' => 'checked_no_data', 'source' => 'gemini_validation', 'validated_at' => '2026-01-01 00:00:00'],
        ];
        $asOf = new DateTimeImmutable('2026-11-02');

        $this->assertSame([], FundamentalOverrideMerger::releasable($live, $rows, self::POLICY, $asOf));
        $this->assertSame($live, FundamentalOverrideMerger::merge($live, $rows, self::POLICY, $asOf));
    }

    public function test_empty_policy_keeps_pre_release_behavior(): void
    {
        $live = ['free_cash_flow' => 40.0, 'days_since_earnings' => 1];
        $rows = ['free_cash_flow' => self::row('40.5', '2026-01-01 00:00:00')];

        $this->assertSame(40.5, FundamentalOverrideMerger::merge($live, $rows)['free_cash_flow']);
    }
}
