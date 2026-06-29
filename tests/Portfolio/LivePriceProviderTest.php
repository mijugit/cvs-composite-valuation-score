<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Api\LatestPriceSource;
use CVS\Portfolio\LivePriceProvider;
use PHPUnit\Framework\TestCase;

/** Fake source: returns a fixed map, null for anything absent. */
final class FakePriceSource implements LatestPriceSource
{
    /** @param array<string, float|null> $prices */
    public function __construct(private array $prices) {}

    public function fetchLatestPrice(string $ticker, string $range = '1d'): ?float
    {
        return $this->prices[strtoupper($ticker)] ?? null;
    }
}

class LivePriceProviderTest extends TestCase
{
    public function testReturnsLivePriceWhenAvailable(): void
    {
        $p = new LivePriceProvider(new FakePriceSource(['MU' => 1370.31]));

        $res = $p->fetch(['MU'], ['MU' => 1096.25]);

        $this->assertSame(1370.31, $res['MU']['price']);
        $this->assertTrue($res['MU']['is_live']);
    }

    public function testFallsBackToSnapshotWhenLiveFails(): void
    {
        $p = new LivePriceProvider(new FakePriceSource(['MU' => null]));

        $res = $p->fetch(['MU'], ['MU' => 1096.25]);

        $this->assertSame(1096.25, $res['MU']['price']);
        $this->assertFalse($res['MU']['is_live']);
    }

    public function testNonUsTickerUsesSnapshotWithoutFetching(): void
    {
        // A suffixed ticker (e.g. Warsaw) must not be fetched live (currency mismatch).
        $source = new FakePriceSource(['KGH.WA' => 142.50]); // would be PLN — must be ignored
        $p = new LivePriceProvider($source);

        $res = $p->fetch(['KGH.WA'], ['KGH.WA' => 38.10]);

        $this->assertSame(38.10, $res['KGH.WA']['price']);
        $this->assertFalse($res['KGH.WA']['is_live']);
    }

    public function testZeroOrNegativeLivePriceFallsBack(): void
    {
        $p = new LivePriceProvider(new FakePriceSource(['MU' => 0.0]));

        $res = $p->fetch(['MU'], ['MU' => 1096.25]);

        $this->assertSame(1096.25, $res['MU']['price']);
        $this->assertFalse($res['MU']['is_live']);
    }
}
