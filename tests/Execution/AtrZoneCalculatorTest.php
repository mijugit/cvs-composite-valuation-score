<?php

declare(strict_types=1);

namespace CVS\Tests\Execution;

use CVS\Execution\AtrZoneCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AtrZoneCalculator (Phase 8, slice 2) — pure, no I/O.
 *
 * Test series is constructed so True Range is a constant 2.0 every day
 * (high = 101, low = 99, close = 100): TR = max(high-low=2, |high-prevClose|=1,
 * |low-prevClose|=1) = 2 → Wilder ATR = 2.0 exactly, making assertions deterministic.
 */
class AtrZoneCalculatorTest extends TestCase
{
    /** @return array{high: float[], low: float[], close: float[]} */
    private function flatSeries(int $days): array
    {
        return [
            'high'  => array_fill(0, $days, 101.0),
            'low'   => array_fill(0, $days, 99.0),
            'close' => array_fill(0, $days, 100.0),
        ];
    }

    /** @return array<string, mixed> */
    private function cfg(): array
    {
        return [
            'atr_period'     => 14,
            'support_window' => 20,
            'zone_atr_mult'  => 1.0,
            'fallback_k'     => 1.0,
            'stop_mult'      => ['swing' => 1.5, 'fund' => 3.0],
        ];
    }

    public function test_insufficient_data_has_no_zone(): void
    {
        $out = AtrZoneCalculator::compute($this->flatSeries(10), 100.0, $this->cfg());
        $this->assertFalse($out['has_zone']);
        $this->assertNull($out['atr']);
        $this->assertNull($out['zone_low']);
    }

    public function test_empty_series_has_no_zone(): void
    {
        $out = AtrZoneCalculator::compute(['high' => [], 'low' => [], 'close' => []], 100.0, $this->cfg());
        $this->assertFalse($out['has_zone']);
    }

    public function test_support_anchored_zone_and_stops(): void
    {
        // 25 days ≥ support_window(20) → support branch; ATR = 2.0, support = 99.
        $out = AtrZoneCalculator::compute($this->flatSeries(25), 100.0, $this->cfg());

        $this->assertTrue($out['has_zone']);
        $this->assertSame('support', $out['source']);
        $this->assertEqualsWithDelta(2.0, $out['atr'], 0.001);
        $this->assertEqualsWithDelta(99.0, $out['support'], 0.001);
        $this->assertEqualsWithDelta(99.0, $out['zone_low'], 0.001);   // support
        $this->assertEqualsWithDelta(101.0, $out['zone_high'], 0.001); // support + 1×ATR
        $this->assertEqualsWithDelta(96.0, $out['stop_swing'], 0.001); // 99 − 1.5×2
        $this->assertEqualsWithDelta(93.0, $out['stop_fund'], 0.001);  // 99 − 3×2
        $this->assertSame('in_zone', $out['state']);                   // 99 ≤ 100 ≤ 101
    }

    public function test_fallback_zone_when_below_support_window(): void
    {
        // 16 days: ≥ atr_period+1(15) but < support_window(20) → fallback branch.
        $out = AtrZoneCalculator::compute($this->flatSeries(16), 100.0, $this->cfg());

        $this->assertTrue($out['has_zone']);
        $this->assertSame('fallback', $out['source']);
        $this->assertNull($out['support']);
        $this->assertEqualsWithDelta(98.0, $out['zone_low'], 0.001);  // price − 1×ATR
        $this->assertEqualsWithDelta(100.0, $out['zone_high'], 0.001); // price
    }

    public function test_state_above_when_price_over_zone(): void
    {
        $out = AtrZoneCalculator::compute($this->flatSeries(25), 120.0, $this->cfg());
        $this->assertSame('above', $out['state']);
    }

    public function test_state_below_when_price_under_zone(): void
    {
        $out = AtrZoneCalculator::compute($this->flatSeries(25), 90.0, $this->cfg());
        $this->assertSame('below', $out['state']);
    }

    public function test_zero_price_has_no_zone(): void
    {
        $out = AtrZoneCalculator::compute($this->flatSeries(25), 0.0, $this->cfg());
        $this->assertFalse($out['has_zone']);
    }
}
