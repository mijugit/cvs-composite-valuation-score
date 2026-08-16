<?php

declare(strict_types=1);

namespace CVS\Tests\Api\Sec;

use CVS\Api\Sec\SecFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Period ends and share counts below are real values from data.sec.gov,
 * fetched 2026-08-16.
 */
class SecFactsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Picking the right period
    // ------------------------------------------------------------------

    public function test_picks_the_most_recent_quarter(): void
    {
        $r = SecFacts::latestQuarterly([
            ['start' => '2025-11-28', 'end' => '2026-02-26', 'val' => 1_138_000_000],
            ['start' => '2026-02-27', 'end' => '2026-05-28', 'val' => 1_145_000_000],
            ['start' => '2025-08-29', 'end' => '2025-11-27', 'val' => 1_131_000_000],
        ]);

        $this->assertNotNull($r);
        $this->assertSame(1_145_000_000.0, $r['count']);
        $this->assertSame('2026-05-28', $r['period_end']);
    }

    /**
     * Annual periods run up to a year behind — Micron's latest annual ended
     * 2025-08-28 while its latest quarter ended 2026-05-28 — and that gap is
     * exactly where buybacks hide.
     */
    public function test_ignores_annual_periods(): void
    {
        $r = SecFacts::latestQuarterly([
            ['start' => '2025-08-29', 'end' => '2026-08-27', 'val' => 1_125_000_000], // ~12m
            ['start' => '2026-02-27', 'end' => '2026-05-28', 'val' => 1_145_000_000], // ~3m
        ]);

        $this->assertSame('2026-05-28', $r['period_end'] ?? null);
    }

    public function test_array_order_does_not_decide_recency(): void
    {
        // Amended filings restate earlier periods, so a later row can describe
        // an older quarter.
        $r = SecFacts::latestQuarterly([
            ['start' => '2026-02-27', 'end' => '2026-05-28', 'val' => 1_145_000_000],
            ['start' => '2025-11-28', 'end' => '2026-02-26', 'val' => 1_138_000_000],
        ]);

        $this->assertSame('2026-05-28', $r['period_end'] ?? null);
    }

    /**
     * @return array<string, array{0: array<int|string, mixed>}>
     */
    public static function unusableUnits(): array
    {
        return [
            'empty'            => [[]],
            'no quarters'      => [[['start' => '2025-01-01', 'end' => '2025-12-31', 'val' => 1_000]]],
            'missing end'      => [[['start' => '2026-02-27', 'val' => 1_000]]],
            'missing value'    => [[['start' => '2026-02-27', 'end' => '2026-05-28']]],
            'zero value'       => [[['start' => '2026-02-27', 'end' => '2026-05-28', 'val' => 0]]],
            'negative value'   => [[['start' => '2026-02-27', 'end' => '2026-05-28', 'val' => -5]]],
            'end before start' => [[['start' => '2026-05-28', 'end' => '2026-02-27', 'val' => 1_000]]],
            'not a row'        => [['nonsense']],
        ];
    }

    /**
     * A wrong share count corrupts every EV verdict downstream, so anything
     * malformed must yield nothing rather than a plausible-looking number.
     */
    #[DataProvider('unusableUnits')]
    public function test_unusable_units_yield_null(array $units): void
    {
        $this->assertNull(SecFacts::latestQuarterly($units));
    }

    // ------------------------------------------------------------------
    // Who may be looked up
    // ------------------------------------------------------------------

    public function test_us_domestic_primary_listing_is_eligible(): void
    {
        $this->assertTrue(SecFacts::isUsDomesticPrimary('MU', 'USD', 'United States'));
        $this->assertTrue(SecFacts::isUsDomesticPrimary('EL', 'usd', 'united states'));
    }

    /**
     * The unit trap, and the reason this gate exists at all: the SEC counts
     * ordinary shares while we price the depositary receipt. JD files 2.978B
     * ordinary against roughly 1.489B receipts, so using the filed figure would
     * double its enterprise value.
     */
    public function test_adrs_are_excluded_even_though_the_sec_has_their_data(): void
    {
        $this->assertFalse(SecFacts::isUsDomesticPrimary('JD', 'CNY', 'China'));
        $this->assertFalse(SecFacts::isUsDomesticPrimary('NIO', 'CNY', 'China'));
        $this->assertFalse(SecFacts::isUsDomesticPrimary('TRP', 'CAD', 'Canada'));
        // Singapore-domiciled but reporting in USD — currency alone is not enough.
        $this->assertFalse(SecFacts::isUsDomesticPrimary('SE', 'USD', 'Singapore'));
    }

    public function test_foreign_listings_are_excluded(): void
    {
        $this->assertFalse(SecFacts::isUsDomesticPrimary('SIE.DE', 'EUR', 'Germany'));
        $this->assertFalse(SecFacts::isUsDomesticPrimary('ITX.MC', 'EUR', 'Spain'));
        $this->assertFalse(SecFacts::isUsDomesticPrimary('PKO.WA', 'PLN', 'Poland'));
    }

    public function test_missing_metadata_excludes_rather_than_assumes(): void
    {
        $this->assertFalse(SecFacts::isUsDomesticPrimary('MU', null, 'United States'));
        $this->assertFalse(SecFacts::isUsDomesticPrimary('MU', 'USD', null));
    }

    // ------------------------------------------------------------------
    // CIK handling
    // ------------------------------------------------------------------

    public function test_cik_is_padded_to_ten_digits(): void
    {
        $this->assertSame('0000723125', SecFacts::padCik(723125));
        $this->assertSame('0000723125', SecFacts::padCik('723125'));
        $this->assertSame('0001108524', SecFacts::padCik(1108524));
    }

    public function test_parses_the_cik_map(): void
    {
        $map = SecFacts::parseCikMap([
            '0' => ['cik_str' => 723125, 'ticker' => 'MU',  'title' => 'MICRON TECHNOLOGY INC'],
            '1' => ['cik_str' => 1108524, 'ticker' => 'crm', 'title' => 'Salesforce, Inc.'],
            '2' => ['nonsense' => true],
        ]);

        $this->assertSame('0000723125', $map['MU'] ?? null);
        $this->assertSame('0001108524', $map['CRM'] ?? null, 'tickers must be upper-cased for lookup');
        $this->assertCount(2, $map);
    }

    public function test_unparseable_cik_map_yields_empty(): void
    {
        $this->assertSame([], SecFacts::parseCikMap(null));
        $this->assertSame([], SecFacts::parseCikMap('not json'));
    }
}
