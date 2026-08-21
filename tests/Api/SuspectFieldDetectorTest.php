<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\SuspectFieldDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SuspectFieldDetectorTest extends TestCase
{
    public function test_flags_free_cash_flow_when_it_exceeds_operating_cash_flow(): void
    {
        $flags = SuspectFieldDetector::detect([
            'free_cash_flow'      => 2_300_000_000,
            'operating_cash_flow' => 2_100_000_000,
        ]);

        $this->assertArrayHasKey('free_cash_flow', $flags);
    }

    public function test_does_not_flag_free_cash_flow_when_it_is_at_or_below_operating_cash_flow(): void
    {
        $flagsBelow = SuspectFieldDetector::detect([
            'free_cash_flow'      => 1_600_000_000,
            'operating_cash_flow' => 2_100_000_000,
        ]);
        $flagsEqual = SuspectFieldDetector::detect([
            'free_cash_flow'      => 2_100_000_000,
            'operating_cash_flow' => 2_100_000_000,
        ]);

        $this->assertArrayNotHasKey('free_cash_flow', $flagsBelow);
        $this->assertArrayNotHasKey('free_cash_flow', $flagsEqual);
    }

    public function test_does_not_flag_free_cash_flow_when_either_value_is_missing(): void
    {
        $flags = SuspectFieldDetector::detect(['free_cash_flow' => 2_300_000_000]);

        $this->assertArrayNotHasKey('free_cash_flow', $flags);
    }

    public function test_flags_days_since_earnings_beyond_cadence_threshold(): void
    {
        $flags = SuspectFieldDetector::detect(['days_since_earnings' => 3007]);

        $this->assertArrayHasKey('days_since_earnings', $flags);
    }

    public function test_does_not_flag_days_since_earnings_within_cadence_threshold(): void
    {
        $flags = SuspectFieldDetector::detect(['days_since_earnings' => 81]);

        $this->assertArrayNotHasKey('days_since_earnings', $flags);
    }

    public function test_does_not_flag_days_since_earnings_when_absent(): void
    {
        $flags = SuspectFieldDetector::detect([]);

        $this->assertArrayNotHasKey('days_since_earnings', $flags);
    }

    #[DataProvider('expectedNonNullFieldsProvider')]
    public function test_flags_expected_non_null_field_when_missing(string $field): void
    {
        $flags = SuspectFieldDetector::detect([]);

        $this->assertArrayHasKey($field, $flags);
    }

    #[DataProvider('expectedNonNullFieldsProvider')]
    public function test_does_not_flag_expected_non_null_field_when_present(string $field): void
    {
        $flags = SuspectFieldDetector::detect([$field => 123.45]);

        $this->assertArrayNotHasKey($field, $flags);
    }

    /** @return array<int, array{0: string}> */
    public static function expectedNonNullFieldsProvider(): array
    {
        return [
            ['gross_profit'],
            ['total_equity'],
            ['current_assets'],
            ['current_liabilities'],
            ['ps_ratio'],
            ['moving_average_200'],
        ];
    }

    public function test_never_flags_trailing_pe_even_when_null(): void
    {
        $flags = SuspectFieldDetector::detect(['trailing_pe' => null]);

        $this->assertArrayNotHasKey('trailing_pe', $flags);
    }

    public function test_combined_flags_case(): void
    {
        $flags = SuspectFieldDetector::detect([
            'free_cash_flow'      => 2_300_000_000,
            'operating_cash_flow' => 2_100_000_000,
            'days_since_earnings' => 3007,
            'gross_profit'        => null,
            'total_equity'        => 7_400_000_000,
        ]);

        $this->assertArrayHasKey('free_cash_flow', $flags);
        $this->assertArrayHasKey('days_since_earnings', $flags);
        $this->assertArrayHasKey('gross_profit', $flags);
        $this->assertArrayNotHasKey('total_equity', $flags);
    }
}
