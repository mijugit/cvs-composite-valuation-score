<?php

declare(strict_types=1);

namespace CVS\Tests\Logo;

use CVS\Logo\TickerLogoPresenter;
use PHPUnit\Framework\TestCase;

class TickerLogoPresenterTest extends TestCase
{
    public function testFoundStatusRendersAnImgWithTheStoredPath(): void
    {
        $html = TickerLogoPresenter::render('AAPL', 'Apple Inc.', [
            'logo_path' => '/images/logos/AAPL.webp',
            'status'    => 'found',
        ]);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="/images/logos/AAPL.webp"', $html);
        $this->assertStringContainsString('class="ticker-logo"', $html);
    }

    public function testNotFoundStatusRendersInitialsPlaceholderFromCompanyName(): void
    {
        $html = TickerLogoPresenter::render('PKN.WA', 'Orlen SA', [
            'logo_path' => null,
            'status'    => 'not_found',
        ]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('class="ticker-logo-fallback"', $html);
        $this->assertStringContainsString('>OS<', $html);
    }

    public function testNullLogoRowFallsBackToPlaceholder(): void
    {
        $html = TickerLogoPresenter::render('MSFT', 'Microsoft Corporation', null);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('class="ticker-logo-fallback"', $html);
        $this->assertStringContainsString('>MC<', $html);
    }

    public function testNullCompanyNameFallsBackToTickerInitials(): void
    {
        $html = TickerLogoPresenter::render('NVDA', null, ['logo_path' => null, 'status' => 'not_found']);

        $this->assertStringContainsString('>NV<', $html);
    }

    public function testSingleWordCompanyNameUsesFirstTwoLettersOfThatWord(): void
    {
        $html = TickerLogoPresenter::render('CFL.WA', 'CyberFolks', null);

        $this->assertStringContainsString('>CY<', $html);
    }

    public function testTickerWithMarketSuffixIsSafelyEscapedInAttributes(): void
    {
        $html = TickerLogoPresenter::render('005930.KS', null, null);

        // No stray characters break out of the style="" attribute.
        $this->assertStringContainsString('style="background:#', $html);
        $this->assertStringContainsString('>00<', $html);
    }

    public function testColorIsDeterministicForTheSameTicker(): void
    {
        $first  = TickerLogoPresenter::render('AAPL', null, null);
        $second = TickerLogoPresenter::render('AAPL', null, null);

        $this->assertSame($first, $second);
    }
}
