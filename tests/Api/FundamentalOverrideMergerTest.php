<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FundamentalOverrideMerger;
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
}
