<?php

declare(strict_types=1);

namespace CVS\Tests\Logo;

use CVS\Logo\LogoDevClient;
use PHPUnit\Framework\TestCase;

/**
 * Only the deterministic pieces of LogoDevClient are unit-tested — no
 * transport mock (plan decision: this is a low-stakes batch script, not a
 * user-facing path, so real HTTP behaviour is verified manually instead;
 * see tests are fully offline convention in CLAUDE.md).
 */
class LogoDevClientTest extends TestCase
{
    public function testRetryableStatusesMatchClaudeClientClassification(): void
    {
        $this->assertTrue(LogoDevClient::isRetryableStatus(429));
        $this->assertTrue(LogoDevClient::isRetryableStatus(529));
        $this->assertTrue(LogoDevClient::isRetryableStatus(500));
        $this->assertTrue(LogoDevClient::isRetryableStatus(502));
        $this->assertTrue(LogoDevClient::isRetryableStatus(503));
        $this->assertTrue(LogoDevClient::isRetryableStatus(504));
    }

    public function testNonRetryableStatuses(): void
    {
        // 404 is a real "no logo" answer — must never be retried.
        $this->assertFalse(LogoDevClient::isRetryableStatus(404));
        $this->assertFalse(LogoDevClient::isRetryableStatus(401));
        $this->assertFalse(LogoDevClient::isRetryableStatus(403));
        $this->assertFalse(LogoDevClient::isRetryableStatus(200));
    }

    public function testBackoffDelayDoublesPerAttempt(): void
    {
        $this->assertSame(500, LogoDevClient::backoffDelayMs(0, 500));
        $this->assertSame(1000, LogoDevClient::backoffDelayMs(1, 500));
        $this->assertSame(2000, LogoDevClient::backoffDelayMs(2, 500));
        $this->assertSame(4000, LogoDevClient::backoffDelayMs(3, 500));
    }

    public function testBackoffDelayWithZeroBaseIsAlwaysZero(): void
    {
        $this->assertSame(0, LogoDevClient::backoffDelayMs(0, 0));
        $this->assertSame(0, LogoDevClient::backoffDelayMs(5, 0));
    }
}
