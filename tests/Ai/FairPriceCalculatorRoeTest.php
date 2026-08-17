<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\FairPriceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * FairPriceCalculator must agree with ValuationPillar's variant C about
 * whether it has an opinion at all, not just about the axis once it does —
 * see the class docblock and the two guards mirrored from
 * ValuationPillar::scoreVariantC(). A fair price computed from inputs the
 * pillar just declined to score would contradict it in the adjacent
 * screener column, the exact failure this class exists to prevent.
 */
class FairPriceCalculatorRoeTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
    }

    /** @return array<string, mixed> */
    private function bank(float $pb, float $bvps, ?float $roe, ?string $roeSource, ?float $trailingPe = null): array
    {
        $f = [
            'sector'                => 'Financial Services',
            'industry'              => 'Banks - Regional',
            'price_to_book'         => $pb,
            'book_value_per_share'  => $bvps,
            'current_price'         => $pb * $bvps,
        ];
        if ($roe !== null) {
            $f['return_on_equity'] = $roe;
        }
        if ($roeSource !== null) {
            $f['return_on_equity_source'] = $roeSource;
        }
        if ($trailingPe !== null) {
            $f['trailing_pe'] = $trailingPe;
        }
        return $f;
    }

    public function testDeclinesWhenRoeIsATrueGap(): void
    {
        $bank = $this->bank(7.87, 5.82, null, null); // XTB.WA-shaped, no P/E either

        $this->assertNull(FairPriceCalculator::compute($bank, $this->config));
    }

    public function testDeclinesOnCrossCurrencyRoeDivergence(): void
    {
        // SAN.WA-shaped: reported ROE 13.1%, P/B ÷ P/E implies 49.9% — 3.8x gap.
        $bank = $this->bank(7.29535, 2.0552, 0.13109, 'yahoo', 14.619423);

        $this->assertNull(FairPriceCalculator::compute($bank, $this->config));
    }

    public function testStillComputesForAnOrdinaryBank(): void
    {
        // PKO.WA-shaped: P/B 2.31, ROE 20.1%, P/E 12.77 (derived ROE ≈ 18.1%,
        // well inside the divergence gate) — a normal, scoreable case.
        $bank = $this->bank(2.31, 12.89, 0.201, 'yahoo', 12.77);

        $this->assertNotNull(FairPriceCalculator::compute($bank, $this->config));
    }

    public function testStillComputesWhenRoeWasAlreadyDerivedUpstream(): void
    {
        // FinancialDataFetcher already fell back to P/B ÷ P/E — nothing left
        // to cross-check, so this must not be treated as a gap.
        $bank = $this->bank(7.87, 5.82, 0.3876, 'derived_pb_pe', 20.31);

        $this->assertNotNull(FairPriceCalculator::compute($bank, $this->config));
    }
}
