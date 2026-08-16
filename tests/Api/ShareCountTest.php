<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\ShareCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Figures below are the real ones returned by Yahoo on 2026-08-16, taken from a
 * sweep of all 587 tickers in the universe.
 */
class ShareCountTest extends TestCase
{
    // ------------------------------------------------------------------
    // Multiple share classes — the "looks cheap" error
    // ------------------------------------------------------------------

    public function test_dual_class_prefers_the_all_class_figure(): void
    {
        // GOOGL: the quoted class is under half the company.
        $r = ShareCount::resolve(
            reported: 5_867_155_790.0,
            impliedField: 12_229_934_831.0,
            marketCap: 4_230_334_382_080.0,
            priceMajor: 345.90,
            revenue: null,
            revenuePerShare: null
        );

        $this->assertSame(12_229_934_831.0, $r['count']);
        $this->assertSame(ShareCount::SOURCE_ALL_CLASS, $r['source']);
    }

    public function test_single_class_keeps_the_filed_figure(): void
    {
        // AAPL: both figures agree, so nothing should be substituted.
        $r = ShareCount::resolve(
            reported: 14_594_180_000.0,
            impliedField: 14_594_180_000.0,
            marketCap: 4_464_797_286_400.0,
            priceMajor: 305.93,
            revenue: null,
            revenuePerShare: null
        );

        $this->assertSame(14_594_180_000.0, $r['count']);
        $this->assertSame(ShareCount::SOURCE_REPORTED, $r['source']);
    }

    public function test_market_cap_stands_in_when_the_direct_field_is_absent(): void
    {
        // Yahoo omits impliedSharesOutstanding but publishes market cap; the
        // quotient reproduces the same 12.23B.
        $r = ShareCount::resolve(
            reported: 5_867_155_790.0,
            impliedField: null,
            marketCap: 4_230_334_382_080.0,
            priceMajor: 345.90,
            revenue: null,
            revenuePerShare: null
        );

        $this->assertSame(ShareCount::SOURCE_ALL_CLASS, $r['source']);
        $this->assertEqualsWithDelta(12_230_000_000.0, (float) $r['count'], 40_000_000.0);
    }

    /**
     * A GBp quote is in pence while market cap is in pounds. Dividing one by
     * the other unconverted inflates the count 100x — the caller must pass the
     * major-currency price, and this pins that contract.
     */
    public function test_minor_unit_price_must_already_be_converted(): void
    {
        $pence = ShareCount::resolve(null, null, 1_000_000_000.0, 250.0, null, null);
        $pounds = ShareCount::resolve(null, null, 1_000_000_000.0, 2.50, null, null);

        $this->assertSame(4_000_000.0, $pence['count']);
        $this->assertSame(400_000_000.0, $pounds['count']);
    }

    // ------------------------------------------------------------------
    // No count at all — 28 of 587 tickers
    // ------------------------------------------------------------------

    public function test_derives_the_count_when_yahoo_publishes_none(): void
    {
        // MU: sharesOutstanding, impliedSharesOutstanding and marketCap all
        // come back as empty arrays. Without this the pillar has no EV.
        $r = ShareCount::resolve(
            reported: null,
            impliedField: null,
            marketCap: null,
            priceMajor: 971.66,
            revenue: 90_273_996_800.0,
            revenuePerShare: 80.21
        );

        $this->assertSame(ShareCount::SOURCE_DERIVED, $r['source']);
        // ~1.125B, matching the float count Yahoo does return for MU.
        $this->assertEqualsWithDelta(1_125_000_000.0, (float) $r['count'], 15_000_000.0);
    }

    public function test_derivation_is_a_last_resort_not_a_preference(): void
    {
        $r = ShareCount::resolve(
            reported: 14_594_180_000.0,
            impliedField: null,
            marketCap: null,
            priceMajor: 305.93,
            revenue: 90_000_000_000.0,
            revenuePerShare: 6.17
        );

        $this->assertSame(ShareCount::SOURCE_REPORTED, $r['source']);
        $this->assertSame(14_594_180_000.0, $r['count']);
    }

    public function test_returns_null_rather_than_guessing(): void
    {
        $r = ShareCount::resolve(null, null, null, 100.0, null, null);

        $this->assertNull($r['count']);
        $this->assertNull($r['source']);
    }

    // ------------------------------------------------------------------
    // Nonsense in, nothing out — a bad count corrupts every EV verdict
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: float|null, 1: float|null, 2: float|null, 3: float|null, 4: float|null, 5: float|null}>
     */
    public static function unusableInputs(): array
    {
        return [
            'zero reported'        => [0.0, null, null, 100.0, null, null],
            'negative reported'    => [-5.0, null, null, 100.0, null, null],
            'zero implied'         => [null, 0.0, null, 100.0, null, null],
            'zero price'           => [null, null, 1_000_000.0, 0.0, null, null],
            'null price'           => [null, null, 1_000_000.0, null, null, null],
            'zero revenuePerShare' => [null, null, null, 100.0, 1_000_000.0, 0.0],
            'negative per share'   => [null, null, null, 100.0, 1_000_000.0, -2.0],
        ];
    }

    #[DataProvider('unusableInputs')]
    public function test_unusable_inputs_yield_no_count(
        ?float $reported,
        ?float $implied,
        ?float $marketCap,
        ?float $price,
        ?float $revenue,
        ?float $revenuePerShare
    ): void {
        $r = ShareCount::resolve($reported, $implied, $marketCap, $price, $revenue, $revenuePerShare);

        $this->assertNull($r['count']);
        $this->assertNull($r['source']);
    }

    public function test_resolution_is_deterministic(): void
    {
        $args = [5_867_155_790.0, 12_229_934_831.0, 4_230_334_382_080.0, 345.90, null, null];

        $this->assertSame(
            ShareCount::resolve(...$args),
            ShareCount::resolve(...$args)
        );
    }
}
