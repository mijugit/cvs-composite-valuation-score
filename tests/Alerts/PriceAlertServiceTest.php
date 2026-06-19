<?php

declare(strict_types=1);

namespace CVS\Tests\Alerts;

use CVS\Alerts\PriceAlertService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure transition decision in PriceAlertService (Phase 8, slice 3).
 * Zone = [99, 101] throughout.
 */
class PriceAlertServiceTest extends TestCase
{
    public function test_out_to_in_sends(): void
    {
        $d = PriceAlertService::decide(100.0, 99.0, 101.0, 'out', 0.0);
        $this->assertSame('send', $d['action']);
        $this->assertSame('in', $d['new_state']);
    }

    public function test_null_state_in_zone_sends(): void
    {
        $d = PriceAlertService::decide(100.0, 99.0, 101.0, null, 0.0);
        $this->assertSame('send', $d['action']);
    }

    public function test_in_to_in_stays_silent(): void
    {
        $d = PriceAlertService::decide(100.0, 99.0, 101.0, 'in', 0.0);
        $this->assertSame('none', $d['action']);
    }

    public function test_in_to_out_rearms_no_send(): void
    {
        $d = PriceAlertService::decide(105.0, 99.0, 101.0, 'in', 0.0);
        $this->assertSame('rearm', $d['action']);
        $this->assertSame('out', $d['new_state']);
    }

    public function test_below_zone_rearms(): void
    {
        $d = PriceAlertService::decide(90.0, 99.0, 101.0, 'in', 0.0);
        $this->assertSame('rearm', $d['action']);
    }

    public function test_hysteresis_margin_holds_state_within_buffer(): void
    {
        // zone width = 2; margin frac 0.5 → margin 1.0. Price 101.5 is above zone_high
        // but within the 1.0 buffer → not yet 'out', so no re-arm while last_state='in'.
        $d = PriceAlertService::decide(101.5, 99.0, 101.0, 'in', 0.5);
        $this->assertSame('none', $d['action']);
    }

    public function test_hysteresis_margin_rearms_beyond_buffer(): void
    {
        // Price 102.5 exceeds zone_high + margin(1.0)=102.0 → re-arm.
        $d = PriceAlertService::decide(102.5, 99.0, 101.0, 'in', 0.5);
        $this->assertSame('rearm', $d['action']);
    }
}
