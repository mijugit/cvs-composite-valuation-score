<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\QualityGate;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for the revenue check's null semantics.
 *
 * Before 2026-08-15 the check read `($financials['revenue'] ?? 0) <= 0`, so an
 * absent field was coerced to zero and rejected as "no revenue". Yahoo returned
 * exactly that shape for MU on 2026-08-13/14 (empty incomeStatementHistory on an
 * otherwise healthy payload), which cascaded into the ticker vanishing from the
 * LLM wallet's universe. Missing must mean "unknown", matching how the margin,
 * leverage and liquidity checks have always treated null.
 */
class QualityGateRevenueTest extends TestCase
{
    /** @return array<string, mixed> */
    private function thresholds(): array
    {
        return [
            'min_gross_margin'         => 0.04,
            'max_debt_to_equity'       => 5.0,
            'min_current_ratio'        => 0.5,
            'require_positive_revenue' => true,
        ];
    }

    public function testMissingRevenueDoesNotFailTheGate(): void
    {
        $result = (new QualityGate($this->thresholds()))->evaluate([]);

        $this->assertTrue($result->passed);
        $this->assertNotContains('Brak przychodów (revenue ≤ 0)', $result->failures);
    }

    public function testExplicitNullRevenueDoesNotFailTheGate(): void
    {
        $result = (new QualityGate($this->thresholds()))->evaluate(['revenue' => null]);

        $this->assertTrue($result->passed);
    }

    public function testZeroRevenueStillFailsTheGate(): void
    {
        $result = (new QualityGate($this->thresholds()))->evaluate(['revenue' => 0]);

        $this->assertFalse($result->passed);
        $this->assertContains('Brak przychodów (revenue ≤ 0)', $result->failures);
    }

    public function testNegativeRevenueStillFailsTheGate(): void
    {
        $result = (new QualityGate($this->thresholds()))->evaluate(['revenue' => -1_000_000]);

        $this->assertFalse($result->passed);
        $this->assertContains('Brak przychodów (revenue ≤ 0)', $result->failures);
    }

    public function testPositiveRevenuePasses(): void
    {
        $result = (new QualityGate($this->thresholds()))->evaluate(['revenue' => 113_538_000_000]);

        $this->assertTrue($result->passed);
    }

    public function testCheckStaysDisabledWhenConfigSaysSo(): void
    {
        $thresholds = $this->thresholds();
        $thresholds['require_positive_revenue'] = false;

        $result = (new QualityGate($thresholds))->evaluate(['revenue' => 0]);

        $this->assertTrue($result->passed);
    }

    // -----------------------------------------------------------------------
    // Gross-margin exemption for sectors that do not report gross profit
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function thresholdsWithBankExemption(): array
    {
        return $this->thresholds() + ['skip_gross_margin_sectors' => ['Financial Services']];
    }

    /**
     * Banks report no gross profit, so Yahoo hands back 0 and the margin check
     * rejected every constituent on a metric that never applied — ING (26.7bn
     * revenue) and ING.WA (3.0bn PLN) were failing daily on "Marża brutto 0.0%".
     */
    public function testBankIsExemptFromTheGrossMarginCheck(): void
    {
        $bank = ['revenue' => 26_658_000_000, 'gross_margins' => 0.0, 'sector' => 'Financial Services'];

        $result = (new QualityGate($this->thresholdsWithBankExemption()))->evaluate($bank);

        $this->assertTrue($result->passed);
    }

    public function testNonExemptSectorStillFailsOnThinMargin(): void
    {
        $industrial = ['revenue' => 1_000_000, 'gross_margins' => 0.0, 'sector' => 'Technology'];

        $result = (new QualityGate($this->thresholdsWithBankExemption()))->evaluate($industrial);

        $this->assertFalse($result->passed);
        $this->assertContains('Marża brutto 0.0% < minimalnej 4.0%', $result->failures);
    }

    public function testExemptionDoesNotWaiveTheOtherChecks(): void
    {
        $bank = [
            'revenue'       => 0, // genuine zero — still rejected
            'gross_margins' => 0.0,
            'sector'        => 'Financial Services',
        ];

        $result = (new QualityGate($this->thresholdsWithBankExemption()))->evaluate($bank);

        $this->assertFalse($result->passed);
        $this->assertContains('Brak przychodów (revenue ≤ 0)', $result->failures);
    }

    public function testMissingSectorIsTreatedAsNonExempt(): void
    {
        $unknown = ['revenue' => 1_000_000, 'gross_margins' => 0.0];

        $result = (new QualityGate($this->thresholdsWithBankExemption()))->evaluate($unknown);

        $this->assertFalse($result->passed);
    }
}
