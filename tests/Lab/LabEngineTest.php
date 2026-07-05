<?php

declare(strict_types=1);

namespace CVS\Tests\Lab;

use CVS\Lab\LabEngine;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for LabEngine (change: cvs-experimental-portfolios, Phase 1).
 * Pure offline tests — no DB, no network. Fixtures mirror the shapes documented
 * in LabEngine's own docblocks.
 */
class LabEngineTest extends TestCase
{
    // ------------------------------------------------------------------
    // selectTargets
    // ------------------------------------------------------------------

    private function candidate(string $ticker, float $swing, ?float $fund, float $price, ?string $sector): array
    {
        return ['ticker' => $ticker, 'cvs_swing' => $swing, 'cvs_fund' => $fund, 'price' => $price, 'sector' => $sector];
    }

    public function testSelectTargetsBenchmarkShortCircuitsIgnoringCandidates(): void
    {
        $rules     = ['benchmark_ticker' => 'SPY'];
        $selection = ['top_n' => 10, 'rank_by' => 'cvs_swing'];

        $targets = LabEngine::selectTargets(
            [$this->candidate('NVDA', 90.0, 90.0, 100.0, 'Technology')],
            $rules,
            $selection
        );

        $this->assertSame(['SPY' => 1.0], $targets);
    }

    public function testSelectTargetsReturnsEmptyWhenNoCandidates(): void
    {
        $rules     = ['benchmark_ticker' => null, 'weighting' => 'equal', 'sector_cap_pct' => null];
        $selection = ['top_n' => 10, 'rank_by' => 'cvs_swing'];

        $this->assertSame([], LabEngine::selectTargets([], $rules, $selection));
    }

    public function testSelectTargetsEqualWeightPicksTopNRankedBySwing(): void
    {
        $candidates = [
            $this->candidate('AAA', 60.0, 60.0, 10.0, 'Technology'),
            $this->candidate('BBB', 90.0, 90.0, 10.0, 'Healthcare'),
            $this->candidate('CCC', 75.0, 75.0, 10.0, 'Energy'),
        ];
        $rules     = ['benchmark_ticker' => null, 'weighting' => 'equal', 'sector_cap_pct' => null];
        $selection = ['top_n' => 2, 'rank_by' => 'cvs_swing'];

        $targets = LabEngine::selectTargets($candidates, $rules, $selection);

        $this->assertCount(2, $targets);
        $this->assertArrayHasKey('BBB', $targets);
        $this->assertArrayHasKey('CCC', $targets);
        $this->assertArrayNotHasKey('AAA', $targets);
        $this->assertEqualsWithDelta(0.5, $targets['BBB'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $targets['CCC'], 1e-9);
    }

    public function testSelectTargetsTieBreaksBySwingThenByCvsFundDescending(): void
    {
        $candidates = [
            $this->candidate('LOWFUND', 80.0, 40.0, 10.0, 'Technology'),
            $this->candidate('HIGHFUND', 80.0, 95.0, 10.0, 'Healthcare'),
        ];
        $rules     = ['benchmark_ticker' => null, 'weighting' => 'equal', 'sector_cap_pct' => null];
        $selection = ['top_n' => 1, 'rank_by' => 'cvs_swing'];

        $targets = LabEngine::selectTargets($candidates, $rules, $selection);

        $this->assertSame(['HIGHFUND' => 1.0], $targets);
    }

    public function testSelectTargetsScoreWeightingIsProportionalToSwing(): void
    {
        $candidates = [
            $this->candidate('AAA', 60.0, 60.0, 10.0, 'Technology'),
            $this->candidate('BBB', 90.0, 90.0, 10.0, 'Healthcare'),
        ];
        $rules     = ['benchmark_ticker' => null, 'weighting' => 'score', 'sector_cap_pct' => null];
        $selection = ['top_n' => 2, 'rank_by' => 'cvs_swing'];

        $targets = LabEngine::selectTargets($candidates, $rules, $selection);

        // 60/(60+90) = 0.4, 90/150 = 0.6
        $this->assertEqualsWithDelta(0.4, $targets['AAA'], 1e-9);
        $this->assertEqualsWithDelta(0.6, $targets['BBB'], 1e-9);
    }

    public function testSelectTargetsScoreWeightingFallsBackToEqualWhenAllScoresZero(): void
    {
        $candidates = [
            $this->candidate('AAA', 0.0, 0.0, 10.0, 'Technology'),
            $this->candidate('BBB', 0.0, 0.0, 10.0, 'Healthcare'),
        ];
        $rules     = ['benchmark_ticker' => null, 'weighting' => 'score', 'sector_cap_pct' => null];
        $selection = ['top_n' => 2, 'rank_by' => 'cvs_swing'];

        $targets = LabEngine::selectTargets($candidates, $rules, $selection);

        $this->assertEqualsWithDelta(0.5, $targets['AAA'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $targets['BBB'], 1e-9);
    }

    public function testSelectTargetsSectorCapRedistributesOverflowToNextRankedCandidate(): void
    {
        // top_n=4, cap=25% -> max 1 per sector. Three Technology names rank above
        // one Healthcare name; only the top Technology name should survive the cap,
        // and the freed slots go to the next-ranked candidates from other sectors —
        // here there's only one other candidate (Healthcare), so it fills one slot
        // and the remaining two slots go unfilled (no more eligible candidates).
        $candidates = [
            $this->candidate('TECH1', 95.0, 95.0, 10.0, 'Technology'),
            $this->candidate('TECH2', 90.0, 90.0, 10.0, 'Technology'),
            $this->candidate('TECH3', 85.0, 85.0, 10.0, 'Technology'),
            $this->candidate('HEALTH1', 80.0, 80.0, 10.0, 'Healthcare'),
        ];
        $rules     = ['benchmark_ticker' => null, 'weighting' => 'equal', 'sector_cap_pct' => 25.0];
        $selection = ['top_n' => 4, 'rank_by' => 'cvs_swing'];

        $targets = LabEngine::selectTargets($candidates, $rules, $selection);

        $this->assertCount(2, $targets);
        $this->assertArrayHasKey('TECH1', $targets);
        $this->assertArrayHasKey('HEALTH1', $targets);
        $this->assertArrayNotHasKey('TECH2', $targets);
        $this->assertArrayNotHasKey('TECH3', $targets);
    }

    // ------------------------------------------------------------------
    // planRebalance
    // ------------------------------------------------------------------

    public function testPlanRebalanceBuysNewPositionsAndChargesFeeOnBuySide(): void
    {
        $trades = LabEngine::planRebalance(
            positions: [],
            targets: ['AAA' => 0.5, 'BBB' => 0.5],
            prices: ['AAA' => 100.0, 'BBB' => 50.0],
            cash: 1001.0, // slight headroom above navTotal so fees don't clamp the quantities below their nominal target
            navTotal: 1000.0,
            costFrac: 0.001
        );

        $this->assertCount(2, $trades);
        $byTicker = array_column($trades, null, 'ticker');
        $this->assertSame('BUY', $byTicker['AAA']['action']);
        $this->assertEqualsWithDelta(5.0, $byTicker['AAA']['quantity'], 0.01); // 500 usd / 100
        $this->assertEqualsWithDelta(0.5, $byTicker['AAA']['fee'], 1e-9);      // 500 * 0.001
        $this->assertSame('BUY', $byTicker['BBB']['action']);
        $this->assertEqualsWithDelta(10.0, $byTicker['BBB']['quantity'], 0.01);
    }

    public function testPlanRebalanceSellsPositionsDroppedFromTargetsAndChargesFeeOnSellSide(): void
    {
        $trades = LabEngine::planRebalance(
            positions: ['ZZZ' => ['quantity' => 10.0, 'avg_entry_price' => 50.0]],
            targets: [], // ZZZ no longer selected
            prices: ['ZZZ' => 60.0],
            cash: 0.0,
            navTotal: 600.0,
            costFrac: 0.001
        );

        $this->assertCount(1, $trades);
        $this->assertSame('SELL', $trades[0]['action']);
        $this->assertEqualsWithDelta(10.0, $trades[0]['quantity'], 1e-9);
        $this->assertEqualsWithDelta(60.0, $trades[0]['price'], 1e-9);
        $this->assertEqualsWithDelta(0.6, $trades[0]['fee'], 1e-9); // 600 * 0.001
        $this->assertSame('rebalance', $trades[0]['reason']);
    }

    public function testPlanRebalanceSkipsTickerMissingAPrice(): void
    {
        $trades = LabEngine::planRebalance(
            positions: [],
            targets: ['NOPRICE' => 1.0],
            prices: [], // no price for NOPRICE — "brak ceny kandydata"
            cash: 1000.0,
            navTotal: 1000.0,
            costFrac: 0.001
        );

        $this->assertSame([], $trades);
    }

    public function testPlanRebalanceStampsCustomReasonForSeedTrades(): void
    {
        $trades = LabEngine::planRebalance(
            positions: [],
            targets: ['AAA' => 1.0],
            prices: ['AAA' => 100.0],
            cash: 1000.0,
            navTotal: 1000.0,
            costFrac: 0.0,
            reason: 'seed'
        );

        $this->assertSame('seed', $trades[0]['reason']);
    }

    public function testPlanRebalanceNeverOverspendsAvailableCashAfterFees(): void
    {
        // Deliberately understated cash relative to target (simulates fee drag from
        // an earlier SELL in the same cycle eating into what's left to invest).
        $trades = LabEngine::planRebalance(
            positions: [],
            targets: ['AAA' => 1.0],
            prices: ['AAA' => 100.0],
            cash: 99.90, // slightly less than the 100.0 nominal target
            navTotal: 100.0,
            costFrac: 0.001
        );

        $this->assertCount(1, $trades);
        $spent = $trades[0]['quantity'] * $trades[0]['price'] + $trades[0]['fee'];
        $this->assertLessThanOrEqual(99.90 + 1e-9, $spent);
    }

    // ------------------------------------------------------------------
    // applyStops
    // ------------------------------------------------------------------

    public function testApplyStopsTriggersSellAtStopPriceWhenLowBreachesWithoutGap(): void
    {
        $positions = ['AAA' => ['quantity' => 10.0, 'avg_entry_price' => 100.0]];
        $ohlc      = ['AAA' => ['open' => 96.0, 'high' => 97.0, 'low' => 94.0, 'close' => 95.0]];
        $stops     = ['AAA' => 95.0];

        $trades = LabEngine::applyStops($positions, $ohlc, $stops, 0.001);

        $this->assertCount(1, $trades);
        $this->assertSame('SELL', $trades[0]['action']);
        $this->assertSame('stop_loss', $trades[0]['reason']);
        $this->assertEqualsWithDelta(95.0, $trades[0]['price'], 1e-9); // open (96) is ABOVE stop -> fill at stop
        $this->assertEqualsWithDelta(10.0, $trades[0]['quantity'], 1e-9);
        $this->assertEqualsWithDelta(0.95, $trades[0]['fee'], 1e-9); // 10*95*0.001
    }

    public function testApplyStopsFillsAtOpenWhenGapBelowStop(): void
    {
        $positions = ['AAA' => ['quantity' => 10.0, 'avg_entry_price' => 100.0]];
        // Gap down: open (90) is already below the stop (95).
        $ohlc  = ['AAA' => ['open' => 90.0, 'high' => 91.0, 'low' => 88.0, 'close' => 89.0]];
        $stops = ['AAA' => 95.0];

        $trades = LabEngine::applyStops($positions, $ohlc, $stops);

        $this->assertCount(1, $trades);
        $this->assertEqualsWithDelta(90.0, $trades[0]['price'], 1e-9);
    }

    public function testApplyStopsDoesNothingWhenLowStaysAboveStop(): void
    {
        $positions = ['AAA' => ['quantity' => 10.0, 'avg_entry_price' => 100.0]];
        $ohlc      = ['AAA' => ['open' => 101.0, 'high' => 103.0, 'low' => 99.0, 'close' => 102.0]];
        $stops     = ['AAA' => 95.0];

        $this->assertSame([], LabEngine::applyStops($positions, $ohlc, $stops));
    }

    public function testApplyStopsSkipsTickerMissingTodaysOhlc(): void
    {
        $positions = ['AAA' => ['quantity' => 10.0, 'avg_entry_price' => 100.0]];

        $this->assertSame([], LabEngine::applyStops($positions, [], ['AAA' => 95.0]));
    }

    // ------------------------------------------------------------------
    // computeNav
    // ------------------------------------------------------------------

    public function testComputeNavSumsPositionsValueAndCash(): void
    {
        $positions = [
            'AAA' => ['quantity' => 10.0, 'avg_entry_price' => 90.0],
            'BBB' => ['quantity' => 5.0,  'avg_entry_price' => 40.0],
        ];
        $closes = ['AAA' => 100.0, 'BBB' => 50.0];

        $nav = LabEngine::computeNav($positions, $closes, 250.0);

        $this->assertEqualsWithDelta(1250.0, $nav['positions_value'], 1e-9); // 1000 + 250
        $this->assertEqualsWithDelta(1500.0, $nav['nav'], 1e-9);             // + 250 cash
    }

    public function testComputeNavSkipsTickerMissingAClose(): void
    {
        $positions = ['AAA' => ['quantity' => 10.0, 'avg_entry_price' => 90.0]];

        $nav = LabEngine::computeNav($positions, [], 100.0);

        $this->assertEqualsWithDelta(0.0, $nav['positions_value'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $nav['nav'], 1e-9);
    }
}
