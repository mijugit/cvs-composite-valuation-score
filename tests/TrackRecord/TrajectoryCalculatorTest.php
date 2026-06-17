<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\TrackRecord\TrajectoryCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TrajectoryCalculator (Phase 8, slice 1) — pure, no DB.
 */
class TrajectoryCalculatorTest extends TestCase
{
    /** @param array<int, array{score_date: string, cvs_swing: float|null}> $rows */
    private function rows(array $rows): array
    {
        return $rows;
    }

    public function test_empty_series_has_no_trajectory(): void
    {
        $out = TrajectoryCalculator::summarise([]);

        $this->assertSame([], $out['points']);
        $this->assertNull($out['latest']);
        $this->assertNull($out['delta_daily']);
        $this->assertNull($out['delta_weekly']);
        $this->assertFalse($out['has_trajectory']);
    }

    public function test_single_point_is_not_a_trajectory(): void
    {
        $out = TrajectoryCalculator::summarise([
            ['score_date' => '2026-06-18', 'cvs_swing' => 70.0],
        ]);

        $this->assertCount(1, $out['points']);
        $this->assertSame(70.0, $out['latest']);
        $this->assertNull($out['delta_daily']);
        $this->assertNull($out['delta_weekly']);
        $this->assertFalse($out['has_trajectory']);
    }

    public function test_two_points_give_daily_delta_and_trajectory(): void
    {
        $out = TrajectoryCalculator::summarise([
            ['score_date' => '2026-06-17', 'cvs_swing' => 60.0],
            ['score_date' => '2026-06-18', 'cvs_swing' => 65.0],
        ]);

        $this->assertTrue($out['has_trajectory']);
        $this->assertSame(65.0, $out['latest']);
        $this->assertSame(5.0, $out['delta_daily']);
        // Both points are within 7 days → no week-over-week reference.
        $this->assertNull($out['delta_weekly']);
    }

    public function test_week_over_week_uses_point_at_least_seven_days_older(): void
    {
        $out = TrajectoryCalculator::summarise([
            ['score_date' => '2026-06-08', 'cvs_swing' => 50.0], // 10 days before latest
            ['score_date' => '2026-06-17', 'cvs_swing' => 60.0], // 1 day before latest
            ['score_date' => '2026-06-18', 'cvs_swing' => 70.0], // latest
        ]);

        $this->assertSame(70.0, $out['latest']);
        $this->assertSame(10.0, $out['delta_daily']);   // 70 - 60
        $this->assertSame(20.0, $out['delta_weekly']);  // 70 - 50 (the ≥7d-older point)
    }

    public function test_null_cvs_rows_are_skipped(): void
    {
        $out = TrajectoryCalculator::summarise([
            ['score_date' => '2026-06-16', 'cvs_swing' => null], // gate-failed
            ['score_date' => '2026-06-17', 'cvs_swing' => 62.0],
            ['score_date' => '2026-06-18', 'cvs_swing' => 64.0],
        ]);

        $this->assertCount(2, $out['points']);
        $this->assertSame(64.0, $out['latest']);
        $this->assertSame(2.0, $out['delta_daily']);
        $this->assertTrue($out['has_trajectory']);
    }

    public function test_min_points_threshold_is_configurable(): void
    {
        $rows = [
            ['score_date' => '2026-06-17', 'cvs_swing' => 60.0],
            ['score_date' => '2026-06-18', 'cvs_swing' => 65.0],
        ];

        $this->assertTrue(TrajectoryCalculator::summarise($rows, 2)['has_trajectory']);
        $this->assertFalse(TrajectoryCalculator::summarise($rows, 3)['has_trajectory']);
    }
}
