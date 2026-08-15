<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\PayloadCompleteness;
use PHPUnit\Framework\TestCase;

class PayloadCompletenessTest extends TestCase
{
    public function testCompletePayloadIsScorable(): void
    {
        $this->assertTrue(PayloadCompleteness::isScorable(['revenue' => 113538000000.0]));
        $this->assertSame([], PayloadCompleteness::missingEssentialFields(['revenue' => 113538000000.0]));
    }

    public function testMissingRevenueIsNotScorable(): void
    {
        $this->assertFalse(PayloadCompleteness::isScorable([]));
        $this->assertSame(['revenue'], PayloadCompleteness::missingEssentialFields([]));
    }

    public function testExplicitNullRevenueIsNotScorable(): void
    {
        $this->assertFalse(PayloadCompleteness::isScorable(['revenue' => null]));
        $this->assertSame(['revenue'], PayloadCompleteness::missingEssentialFields(['revenue' => null]));
    }

    /**
     * The real-world shape this guard exists for: Yahoo returned every module
     * with a 200 and a live price, but an empty incomeStatementHistory, so the
     * payload looks healthy everywhere except where it counts (MU, 2026-08-13).
     */
    public function testYahooEmptyIncomeStatementShapeIsRejected(): void
    {
        $muLike = [
            'current_price'  => 971.66,
            'sector'         => 'Technology',
            'industry'       => 'Semiconductors',
            'long_name'      => 'Micron Technology, Inc.',
            'free_cash_flow' => 25716000768.0,
            'ebitda'         => 68222001152.0,
            'trailing_eps'   => 45.28,
            'revenue'        => null, // empty incomeStatementHistory
        ];

        $this->assertFalse(PayloadCompleteness::isScorable($muLike));
        $this->assertSame(['revenue'], PayloadCompleteness::missingEssentialFields($muLike));
    }

    /**
     * A genuine zero is a business fact, not a data gap — it must pass this
     * guard and be judged by QualityGate instead.
     */
    public function testZeroRevenueIsScorableAndLeftToTheQualityGate(): void
    {
        $this->assertTrue(PayloadCompleteness::isScorable(['revenue' => 0.0]));
    }
}
