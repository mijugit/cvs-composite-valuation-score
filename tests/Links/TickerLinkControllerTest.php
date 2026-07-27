<?php

declare(strict_types=1);

namespace CVS\Tests\Links;

use CVS\Links\TickerLinkController;
use PHPUnit\Framework\TestCase;

class TickerLinkControllerTest extends TestCase
{
    public function testIsValidTickerAcceptsPlainAndSuffixedTickers(): void
    {
        $this->assertTrue(TickerLinkController::isValidTicker('AAPL'));
        $this->assertTrue(TickerLinkController::isValidTicker('PKN.WA'));
    }

    public function testIsValidTickerRejectsGarbage(): void
    {
        $this->assertFalse(TickerLinkController::isValidTicker(''));
        $this->assertFalse(TickerLinkController::isValidTicker('lowercase'));
        $this->assertFalse(TickerLinkController::isValidTicker('TOO-LONG-TICKER-1'));
        $this->assertFalse(TickerLinkController::isValidTicker('HAS SPACE'));
    }

    public function testIsValidLabelAcceptsNonEmptyUpTo80Chars(): void
    {
        $this->assertTrue(TickerLinkController::isValidLabel('TradingView'));
        $this->assertTrue(TickerLinkController::isValidLabel(str_repeat('a', 80)));
    }

    public function testIsValidLabelRejectsEmptyOrTooLong(): void
    {
        $this->assertFalse(TickerLinkController::isValidLabel(''));
        $this->assertFalse(TickerLinkController::isValidLabel(str_repeat('a', 81)));
    }

    public function testIsValidUrlAcceptsHttpAndHttps(): void
    {
        $this->assertTrue(TickerLinkController::isValidUrl('https://pl.tradingview.com/chart/A5nLjaVd/?symbol=GPW%3APKN'));
        $this->assertTrue(TickerLinkController::isValidUrl('http://example.com'));
    }

    public function testIsValidUrlRejectsNonHttpSchemes(): void
    {
        $this->assertFalse(TickerLinkController::isValidUrl('javascript:alert(1)'));
        $this->assertFalse(TickerLinkController::isValidUrl('ftp://example.com/file'));
        $this->assertFalse(TickerLinkController::isValidUrl('data:text/html,<script>alert(1)</script>'));
    }

    public function testIsValidUrlRejectsMalformedOrOverlong(): void
    {
        $this->assertFalse(TickerLinkController::isValidUrl('not a url'));
        $this->assertFalse(TickerLinkController::isValidUrl('https://' . str_repeat('a', 500) . '.com'));
    }
}
