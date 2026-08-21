<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\MovingAverageCalculator;
use PHPUnit\Framework\TestCase;

class MovingAverageCalculatorTest extends TestCase
{
    public function test_returns_null_when_fewer_than_minimum_closes_available(): void
    {
        $dailyOhlc = ['close' => array_fill(0, 149, 100.0)];

        $result = MovingAverageCalculator::computeMa200($dailyOhlc, 1.0);

        $this->assertNull($result);
    }

    public function test_computes_average_of_last_200_closes_when_more_are_available(): void
    {
        // 250 closes: first 50 are 999 (must be excluded by the 200-window
        // slice), last 200 are a known constant so the expected average is trivial.
        $closes = array_merge(array_fill(0, 50, 999.0), array_fill(0, 200, 40.0));

        $result = MovingAverageCalculator::computeMa200(['close' => $closes], 1.0);

        $this->assertSame(40.0, $result);
    }

    public function test_computes_average_of_all_closes_when_between_min_and_window(): void
    {
        $closes = array_fill(0, 150, 50.0);

        $result = MovingAverageCalculator::computeMa200(['close' => $closes], 1.0);

        $this->assertSame(50.0, $result);
    }

    public function test_applies_price_fx_rate(): void
    {
        $closes = array_fill(0, 200, 100.0);

        $result = MovingAverageCalculator::computeMa200(['close' => $closes], 4.0);

        $this->assertSame(400.0, $result);
    }

    public function test_returns_null_when_close_key_missing(): void
    {
        $result = MovingAverageCalculator::computeMa200([], 1.0);

        $this->assertNull($result);
    }
}
