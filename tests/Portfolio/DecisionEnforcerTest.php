<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\DecisionEnforcer;
use PHPUnit\Framework\TestCase;

class DecisionEnforcerTest extends TestCase
{
    private DecisionEnforcer $enforcer;

    protected function setUp(): void
    {
        // base for these tests is $10 000 → sector cap $4 000, stock cap $1 500
        $this->enforcer = new DecisionEnforcer([
            'max_sector_pct' => 40.0,
            'max_weight_pct' => 15.0,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function buy(string $ticker, int $qty, array $extra = []): array
    {
        return array_merge(
            ['action' => 'BUY', 'ticker' => $ticker, 'quantity' => $qty, 'price_usd' => null, 'reason' => null],
            $extra
        );
    }

    public function testSectorCapTrimsAndDropsExcessTechBuys(): void
    {
        $decisions = [
            $this->buy('MU', 1),    // 1096.25 → tech 1096.25
            $this->buy('FFIV', 2),  // 820.00  → tech 1916.25
            $this->buy('NOW', 9),   // 915.30  → tech 2831.55
            $this->buy('CSCO', 8),  // 931.28  → tech 3762.83
            $this->buy('QCOM', 5),  // would push tech > 4000 → trimmed
            $this->buy('ADBE', 4),  // no room left → dropped
        ];
        $prices = [
            'MU' => 1096.25, 'FFIV' => 410.0, 'NOW' => 101.70,
            'CSCO' => 116.41, 'QCOM' => 189.47, 'ADBE' => 207.19,
        ];
        $sectors = array_fill_keys(array_keys($prices), 'Technology');

        $res = $this->enforcer->apply($decisions, [], $prices, $sectors, 10000.0);

        // Total tech spend must never exceed the $4 000 cap.
        $techSpend = 0.0;
        foreach ($res['decisions'] as $d) {
            $techSpend += (int) $d['quantity'] * $prices[$d['ticker']];
        }
        $this->assertLessThanOrEqual(4000.0, $techSpend);

        // ADBE fully dropped (no sector room left).
        $tickers = array_column($res['decisions'], 'ticker');
        $this->assertNotContains('ADBE', $tickers);
    }

    public function testPerStockCapTrimsOversizedPosition(): void
    {
        // GME at $400, model wants 5 (=$2000) but stock cap is $1500 → max 3 shares.
        $res = $this->enforcer->apply(
            [$this->buy('GME', 5)],
            [],
            ['GME' => 400.0],
            ['GME' => 'Consumer Cyclical'],
            10000.0
        );

        $this->assertSame(3, $res['decisions'][0]['quantity']);
    }

    public function testExpensiveSingleShareWithinStockCapIsKept(): void
    {
        // MU at $1096 ≤ $1500 stock cap → 1 share survives.
        $res = $this->enforcer->apply(
            [$this->buy('MU', 1)],
            [],
            ['MU' => 1096.25],
            ['MU' => 'Technology'],
            10000.0
        );

        $this->assertCount(1, $res['decisions']);
        $this->assertSame(1, $res['decisions'][0]['quantity']);
    }

    public function testBuyWithoutPriceIsDropped(): void
    {
        $res = $this->enforcer->apply(
            [$this->buy('XYZ', 5)],
            [],
            [],            // no price for XYZ
            ['XYZ' => 'Technology'],
            10000.0
        );

        $this->assertEmpty($res['decisions']);
        $this->assertNotEmpty($res['notes']);
    }

    public function testHoldAndNoActionPassThroughUntouched(): void
    {
        $decisions = [
            ['action' => 'HOLD', 'ticker' => 'NVDA', 'quantity' => null, 'price_usd' => null, 'reason' => null],
            ['action' => 'NO_ACTION', 'ticker' => null, 'quantity' => null, 'price_usd' => null, 'reason' => null],
        ];

        $res = $this->enforcer->apply($decisions, [], ['NVDA' => 500.0], ['NVDA' => 'Technology'], 10000.0);

        $this->assertCount(2, $res['decisions']);
        $this->assertSame('HOLD', $res['decisions'][0]['action']);
        $this->assertSame('NO_ACTION', $res['decisions'][1]['action']);
    }

    public function testCashCapTrimsWhenInsufficientFunds(): void
    {
        // Base must be large enough that the per-stock cap isn't the binding limit:
        // a $9 500 holding lifts base to $10 000 (stock cap $1 500, sector cap $4 000),
        // while only $500 cash remains. Share $200 → cash allows 2, model asked 5.
        $holdings = [['ticker' => 'HELD', 'quantity' => 1, 'avg_entry_price' => 9500.0]];

        $res = $this->enforcer->apply(
            [$this->buy('T', 5)],
            $holdings,
            ['HELD' => 9500.0, 'T' => 200.0],
            ['HELD' => 'Other', 'T' => 'Communication Services'],
            500.0
        );

        $this->assertSame(2, $res['decisions'][0]['quantity']);
    }

    public function testExistingHoldingsCountTowardSectorCap(): void
    {
        // Already hold $3 800 of tech (NVDA). Base = 3800 + 6200 cash = 10000,
        // sector cap = 4000 → only $200 of new tech allowed.
        $holdings = [['ticker' => 'NVDA', 'quantity' => 10, 'avg_entry_price' => 380.0]];

        $res = $this->enforcer->apply(
            [$this->buy('AMD', 5)],          // $530 each — even 1 share ($530) > $200 room
            $holdings,
            ['NVDA' => 380.0, 'AMD' => 530.0],
            ['NVDA' => 'Technology', 'AMD' => 'Technology'],
            6200.0
        );

        $this->assertEmpty($res['decisions']); // AMD dropped — sector full
    }
}
