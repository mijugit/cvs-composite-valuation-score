<?php

declare(strict_types=1);

namespace CVS\Tests\Screener;

use CVS\Screener\MarketResolver;
use PHPUnit\Framework\TestCase;

class MarketResolverTest extends TestCase
{
    /** @var array{default_label?: string, labels?: array<string, string>} */
    private array $config = [
        'default_label' => 'USA (NYSE/NASDAQ)',
        'labels' => [
            '.WA' => 'GPW (Warszawa)',
            '.KS' => 'Giełda Korei (KOSPI)',
        ],
    ];

    public function testSuffixForTickerExtractsUppercasedSuffix(): void
    {
        $this->assertSame('.WA', MarketResolver::suffixForTicker('PKN.WA'));
        $this->assertSame('.WA', MarketResolver::suffixForTicker('pkn.wa'));
    }

    public function testSuffixForTickerReturnsNullForPlainUsTicker(): void
    {
        $this->assertNull(MarketResolver::suffixForTicker('AAPL'));
    }

    public function testLabelForSuffixReturnsDefaultLabelForNull(): void
    {
        $this->assertSame('USA (NYSE/NASDAQ)', MarketResolver::labelForSuffix(null, $this->config));
    }

    public function testLabelForSuffixReturnsConfiguredLabel(): void
    {
        $this->assertSame('GPW (Warszawa)', MarketResolver::labelForSuffix('.WA', $this->config));
    }

    public function testLabelForSuffixFallsBackToRawSuffixWhenUnmapped(): void
    {
        $this->assertSame('.XX', MarketResolver::labelForSuffix('.XX', $this->config));
    }

    public function testLabelForTickerCombinesSuffixExtractionAndLookup(): void
    {
        $this->assertSame('GPW (Warszawa)', MarketResolver::labelForTicker('PKN.WA', $this->config));
        $this->assertSame('USA (NYSE/NASDAQ)', MarketResolver::labelForTicker('AAPL', $this->config));
    }

    public function testEmptyConfigStillProducesSensibleFallbacks(): void
    {
        $this->assertSame('USA', MarketResolver::labelForTicker('AAPL', []));
        $this->assertSame('.WA', MarketResolver::labelForTicker('PKN.WA', []));
    }
}
