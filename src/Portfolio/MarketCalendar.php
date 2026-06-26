<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use DateTimeImmutable;
use DateTimeZone;

/**
 * NYSE market calendar and rebalance-window gate.
 *
 * Receives the current time as an injected DateTimeImmutable so it is
 * deterministic and unit-testable. Never calls new DateTimeImmutable()
 * internally — timezone handling is always explicit.
 */
final class MarketCalendar
{
    private readonly DateTimeZone $easternTz;

    /** @param array<string, mixed> $config keys: market (open_time, close_time, timezone), rebalance_window_minutes, holidays */
    public function __construct(private readonly array $config)
    {
        $this->easternTz = new DateTimeZone($this->config['market']['timezone']);
    }

    /**
     * Returns true when $now falls on an NYSE trading day
     * (Mon–Fri, excluding configured holidays).
     */
    public function isMarketDay(DateTimeImmutable $now): bool
    {
        $eastern = $now->setTimezone($this->easternTz);
        $dayOfWeek = (int) $eastern->format('N'); // 1=Mon … 7=Sun

        if ($dayOfWeek >= 6) {
            return false;
        }

        $dateStr = $eastern->format('Y-m-d');

        return !in_array($dateStr, $this->config['holidays'], true);
    }

    /**
     * Returns true when $now is within the rebalance window
     * [close_time - rebalance_window_minutes, close_time) in ET.
     */
    public function isInRebalanceWindow(DateTimeImmutable $now): bool
    {
        $eastern = $now->setTimezone($this->easternTz);

        $windowMinutes = (int) $this->config['rebalance_window_minutes'];
        $closeTime     = $this->config['market']['close_time']; // e.g. '16:00'

        [$closeH, $closeM] = array_map('intval', explode(':', $closeTime));
        $closeTotal = $closeH * 60 + $closeM;
        $startTotal = $closeTotal - $windowMinutes;

        $currentTotal = (int) $eastern->format('G') * 60 + (int) $eastern->format('i');

        return $currentTotal >= $startTotal && $currentTotal < $closeTotal;
    }

    /**
     * Convenience method — returns one of:
     *   'market_closed'           — non-trading day (weekend or holiday)
     *   'outside_rebalance_window' — trading day but wrong time slot
     *   'ready'                   — trading day, within rebalance window
     */
    public function getStatus(DateTimeImmutable $now): string
    {
        if (!$this->isMarketDay($now)) {
            return 'market_closed';
        }

        if (!$this->isInRebalanceWindow($now)) {
            return 'outside_rebalance_window';
        }

        return 'ready';
    }
}
