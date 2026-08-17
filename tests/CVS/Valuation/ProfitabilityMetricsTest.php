<?php

declare(strict_types=1);

namespace CVS\Tests\CVS\Valuation;

use CVS\CVS\Valuation\ProfitabilityMetrics;
use PHPUnit\Framework\TestCase;

/**
 * ROE = P/B ÷ P/E identically for any company (both divide by the same
 * market cap) — verified here against the values actually observed on a
 * live Yahoo pull, 2026-08-17, so the formula stays anchored to reality
 * and not just to itself.
 */
class ProfitabilityMetricsTest extends TestCase
{
    public function testDerivesRoeFromPriceToBookAndTrailingPe(): void
    {
        // XTB.WA: Yahoo returns no returnOnEquity for this ticker at all.
        $roe = ProfitabilityMetrics::deriveRoe([
            'price_to_book' => 7.87,
            'trailing_pe'   => 20.31,
        ]);

        $this->assertEqualsWithDelta(0.388, $roe, 0.001);
    }

    public function testAgreesWithYahooWithinTheMeasuredErrorBand(): void
    {
        // PKO.WA: Yahoo reports ROE 20.1%. Derived should land within a few
        // points — the gap is Yahoo using average equity vs P/B and P/E both
        // using end-of-period figures.
        $roe = ProfitabilityMetrics::deriveRoe([
            'price_to_book' => 2.31,
            'trailing_pe'   => 12.77,
        ]);

        $this->assertEqualsWithDelta(0.201, $roe, 0.03);
    }

    public function testReturnsNullWhenPriceToBookIsMissing(): void
    {
        $this->assertNull(ProfitabilityMetrics::deriveRoe(['trailing_pe' => 12.77]));
    }

    public function testReturnsNullWhenTrailingPeIsMissing(): void
    {
        $this->assertNull(ProfitabilityMetrics::deriveRoe(['price_to_book' => 2.31]));
    }

    public function testReturnsNullWhenTrailingPeIsZeroOrNegative(): void
    {
        // A loss-making company reports a negative or undefined P/E — the
        // quotient would flip sign and imply a nonsensical ROE.
        $this->assertNull(ProfitabilityMetrics::deriveRoe(['price_to_book' => 2.31, 'trailing_pe' => -5.0]));
        $this->assertNull(ProfitabilityMetrics::deriveRoe(['price_to_book' => 2.31, 'trailing_pe' => 0.0]));
    }

    public function testReturnsNullWhenPriceToBookIsZeroOrNegative(): void
    {
        $this->assertNull(ProfitabilityMetrics::deriveRoe(['price_to_book' => -1.0, 'trailing_pe' => 12.77]));
        $this->assertNull(ProfitabilityMetrics::deriveRoe(['price_to_book' => 0.0, 'trailing_pe' => 12.77]));
    }

    public function testIsPureAndDeterministic(): void
    {
        $input = ['price_to_book' => 7.87, 'trailing_pe' => 20.31];

        $this->assertSame(
            ProfitabilityMetrics::deriveRoe($input),
            ProfitabilityMetrics::deriveRoe($input)
        );
    }
}
