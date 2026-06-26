<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\MarketCalendar;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class MarketCalendarTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'market' => [
                'open_time'  => '09:30',
                'close_time' => '16:00',
                'timezone'   => 'America/New_York',
            ],
            'rebalance_window_minutes' => 30,
            'holidays' => [
                '2026-01-01', // New Year's Day
                '2026-01-19', // MLK Day
                '2026-04-03', // Good Friday
            ],
        ];
    }

    private function calendar(): MarketCalendar
    {
        return new MarketCalendar($this->config());
    }

    // --- isMarketDay ---

    public function testSaturdayIsNotMarketDay(): void
    {
        // 2026-06-27 is a Saturday
        $now = new DateTimeImmutable('2026-06-27 12:00:00', new DateTimeZone('America/New_York'));
        $this->assertFalse($this->calendar()->isMarketDay($now));
    }

    public function testSundayIsNotMarketDay(): void
    {
        // 2026-06-28 is a Sunday
        $now = new DateTimeImmutable('2026-06-28 12:00:00', new DateTimeZone('America/New_York'));
        $this->assertFalse($this->calendar()->isMarketDay($now));
    }

    public function testNyseHolidayIsNotMarketDay(): void
    {
        // 2026-01-01 New Year's Day
        $now = new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('America/New_York'));
        $this->assertFalse($this->calendar()->isMarketDay($now));
    }

    public function testRegularWednesdayIsMarketDay(): void
    {
        // 2026-06-24 is a Wednesday, not a holiday
        $now = new DateTimeImmutable('2026-06-24 12:00:00', new DateTimeZone('America/New_York'));
        $this->assertTrue($this->calendar()->isMarketDay($now));
    }

    // --- isInRebalanceWindow ---

    public function testInsideWindowAt1535Et(): void
    {
        // 15:35 ET is inside [15:30, 16:00)
        $now = new DateTimeImmutable('2026-06-24 15:35:00', new DateTimeZone('America/New_York'));
        $this->assertTrue($this->calendar()->isInRebalanceWindow($now));
    }

    public function testAtWindowStartAt1530Et(): void
    {
        // 15:30 ET — window starts here (inclusive)
        $now = new DateTimeImmutable('2026-06-24 15:30:00', new DateTimeZone('America/New_York'));
        $this->assertTrue($this->calendar()->isInRebalanceWindow($now));
    }

    public function testBeforeWindowAt1525Et(): void
    {
        // 15:25 ET is before [15:30, 16:00)
        $now = new DateTimeImmutable('2026-06-24 15:25:00', new DateTimeZone('America/New_York'));
        $this->assertFalse($this->calendar()->isInRebalanceWindow($now));
    }

    public function testAfterCloseAt1605Et(): void
    {
        // 16:05 ET is past market close
        $now = new DateTimeImmutable('2026-06-24 16:05:00', new DateTimeZone('America/New_York'));
        $this->assertFalse($this->calendar()->isInRebalanceWindow($now));
    }

    public function testAtCloseAt1600EtIsNotInWindow(): void
    {
        // 16:00 ET — window is [15:30, 16:00), so 16:00 is excluded
        $now = new DateTimeImmutable('2026-06-24 16:00:00', new DateTimeZone('America/New_York'));
        $this->assertFalse($this->calendar()->isInRebalanceWindow($now));
    }

    // --- getStatus ---

    public function testGetStatusMarketClosed(): void
    {
        // Saturday
        $now = new DateTimeImmutable('2026-06-27 15:35:00', new DateTimeZone('America/New_York'));
        $this->assertSame('market_closed', $this->calendar()->getStatus($now));
    }

    public function testGetStatusOutsideWindow(): void
    {
        // Wednesday but 12:00 ET (outside window)
        $now = new DateTimeImmutable('2026-06-24 12:00:00', new DateTimeZone('America/New_York'));
        $this->assertSame('outside_rebalance_window', $this->calendar()->getStatus($now));
    }

    public function testGetStatusReady(): void
    {
        // Wednesday 15:35 ET — in window
        $now = new DateTimeImmutable('2026-06-24 15:35:00', new DateTimeZone('America/New_York'));
        $this->assertSame('ready', $this->calendar()->getStatus($now));
    }

    // --- DST scenarios ---

    /**
     * DST gap: EU switched to summer time, US hasn't yet.
     * Warsaw is CET (UTC+1), NYC is EST (UTC-5) → 6h gap.
     * 21:35 Warsaw = 15:35 ET → inside window.
     *
     * Note: This scenario (EU forward, US not yet) occurs briefly in March.
     * We use a date well outside any DST transition for clarity.
     */
    public function testDstScenarioSixHourOffset(): void
    {
        // Summer: CEST (UTC+2), EDT (UTC-4) → 6h gap
        // 2026-07-01 21:35 Warsaw = 15:35 New York
        $warsaw = new DateTimeZone('Europe/Warsaw');
        $now = new DateTimeImmutable('2026-07-01 21:35:00', $warsaw);
        $this->assertTrue($this->calendar()->isInRebalanceWindow($now));
    }

    public function testDstScenarioFiveHourOffset(): void
    {
        // Winter: CET (UTC+1), EST (UTC-5) → 6h gap... wait:
        // Actually winter: UTC+1 - UTC-5 = 6h gap too.
        // The 5h gap occurs when US has switched but EU hasn't (or vice versa).
        // Late Oct: EU fell back (CET UTC+1), US still EDT (UTC-4) → 5h gap.
        // 2026-10-26 is the EU fall-back Sunday; US falls back on Nov 1 2026.
        // So week of Oct 26-Nov 1: EU=CET (UTC+1), US=EDT (UTC-4) → 5h gap.
        // 20:35 Warsaw (CET) = 15:35 NYC (EDT) → inside window.
        $warsaw = new DateTimeZone('Europe/Warsaw');
        $now = new DateTimeImmutable('2026-10-28 20:35:00', $warsaw); // Wednesday in transition week
        $this->assertTrue($this->calendar()->isInRebalanceWindow($now));
    }

    /**
     * 2026-03-10 is US spring-forward day (EDT starts).
     * EU already on CEST since last Sunday of March... wait:
     * EU spring forward: last Sunday of March = 2026-03-29.
     * US spring forward: 2026-03-08 (2nd Sunday of March).
     * So on 2026-03-10 (Tuesday): US=EDT (UTC-4), EU=CET (UTC+1) → 5h gap.
     * 20:35 Warsaw (CET UTC+1) = 15:35 NYC (EDT UTC-4) → inside window.
     */
    public function testDstTransitionMarch2026(): void
    {
        $warsaw = new DateTimeZone('Europe/Warsaw');
        $now = new DateTimeImmutable('2026-03-10 20:35:00', $warsaw);
        $this->assertTrue($this->calendar()->isInRebalanceWindow($now));
    }
}
