<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\Pillars\QualityPillar;
use CVS\CVS\Pillars\ValuationPillar;
use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerMedianRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Variant D — real estate scored on EV/EBITDA.
 *
 * Subtler than variant C. A bank's free cash flow is meaningless, so EV/FCF
 * produced nothing at all; a REIT's is merely DISTORTED. Free cash flow
 * subtracts every capital expenditure, and for a REIT that includes property
 * ACQUISITIONS — growth spending, not upkeep — so a REIT that bought buildings
 * reads as expensive purely for having invested. That is the distortion FFO
 * exists to remove, and it is measurable: on 2026-08-16 Realty Income sat at
 * 1.64x the peer median on EV/FCF and 0.86x on EV/EBITDA.
 *
 * EV/EBITDA stands in for FFO because Yahoo returns no depreciation figure for
 * any of the 18 REITs in this universe, and depreciation is what FFO is built
 * from. Figures below are real, measured the same day.
 */
class RealEstateScoringTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;
    private PeerMedianRepository $repo;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE peer_medians (
            id INTEGER PRIMARY KEY, level TEXT NOT NULL, bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL, model_version TEXT NOT NULL, metric_type TEXT NOT NULL,
            median_value REAL NULL, sample_count INTEGER NOT NULL DEFAULT 0,
            computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(level, bucket_key, model_version, metric_type)
        )');
        $pdo->exec('CREATE TABLE peer_medians_history (
            id INTEGER PRIMARY KEY, level TEXT NOT NULL, bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL, model_version TEXT NOT NULL, metric_type TEXT NOT NULL,
            median_value REAL NULL, sample_count INTEGER NOT NULL DEFAULT 0,
            snapshotted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->repo = new PeerMedianRepository($pdo);
    }

    private function pillar(): ValuationPillar
    {
        return new ValuationPillar(
            benchmarks:       $this->config['benchmarks'] ?? [],
            resolver:         MedianResolver::fromConfig($this->config, $this->repo),
            valuationConfig:  $this->config['valuation'] ?? [],
            financialsConfig: $this->config['financials'] ?? [],
            realEstateConfig: $this->config['real_estate'] ?? [],
        );
    }

    /**
     * A REIT with the given EV/EBITDA, built from real Realty Income figures.
     *
     * @return array<string, mixed>
     */
    private function reit(float $ebitda, float $price = 55.0, float $shares = 950_000_000.0): array
    {
        return [
            'sector'             => 'Real Estate',
            'industry'           => 'REIT - Retail',
            'current_price'      => $price,
            'shares_outstanding' => $shares,
            'total_debt'         => 28_000_000_000.0,
            'cash'               => 500_000_000.0,
            'ebitda'             => $ebitda,
            // Present and misleading, as they are for a real REIT: acquisitions
            // sit in capex, so free cash flow understates the earning power.
            'free_cash_flow'     => 1_580_000_000.0,
            'revenue'            => 5_600_000_000.0,
            'trailing_eps'       => 1.30,
            'forward_eps'        => 1.42,
        ];
    }

    public function testReitIsScoredOnEvEbitdaNotEvFcf(): void
    {
        $pillar = $this->pillar();
        $pillar->score($this->reit(5_360_000_000.0));

        $this->assertSame('D', $pillar->steps()['variant']);
        $this->assertArrayHasKey('ev_ebitda', $pillar->steps());
        $this->assertArrayNotHasKey('ev_fcf', $pillar->steps());
    }

    public function testCheaperEarningsMultipleScoresHigher(): void
    {
        // Same enterprise value, more EBITDA behind it = cheaper.
        $cheap = $this->pillar()->score($this->reit(6_500_000_000.0));
        $rich  = $this->pillar()->score($this->reit(2_000_000_000.0));

        $this->assertGreaterThan($rich, $cheap);
    }

    public function testScoringIsDeterministic(): void
    {
        $a = $this->pillar()->score($this->reit(5_360_000_000.0));
        $b = $this->pillar()->score($this->reit(5_360_000_000.0));

        $this->assertSame($a, $b);
    }

    public function testMissingEbitdaYieldsNeutralNotZero(): void
    {
        $reit = $this->reit(5_360_000_000.0);
        unset($reit['ebitda']);

        $pillar = $this->pillar();

        $this->assertSame(50.0, $pillar->score($reit));
        $this->assertSame('missing_ebitda', $pillar->lastSource());
    }

    public function testOtherSectorsAreUnaffected(): void
    {
        $pillar = $this->pillar();
        $pillar->score([
            'sector'             => 'Technology',
            'industry'           => 'Semiconductors',
            'ebitda'             => 5_000_000_000.0,
            'free_cash_flow'     => 1_000_000_000.0,
            'current_price'      => 100.0,
            'shares_outstanding' => 1_000_000_000.0,
            'trailing_eps'       => 5.0,
            'forward_eps'        => 6.0,
        ]);

        $this->assertNotSame('D', $pillar->steps()['variant'] ?? null);
    }

    public function testUsesEmpiricalPeerMedianWhenBucketIsDeepEnough(): void
    {
        $this->repo->upsertMedian(
            'industry', 'REIT - Retail', 'Real Estate',
            (string) $this->config['model_version'], 'ev_ebitda', 19.5, 8
        );

        $pillar = $this->pillar();
        $pillar->score($this->reit(5_360_000_000.0));

        $this->assertSame('subsector', $pillar->lastSource());
        $this->assertSame(19.5, $pillar->steps()['sub_median']);
    }

    // -----------------------------------------------------------------------
    // Quality: leverage bands that fit an asset-backed sector
    // -----------------------------------------------------------------------

    private function quality(): QualityPillar
    {
        return new QualityPillar(
            $this->config['benchmarks']['Real Estate'] ?? [],
            $this->config['financials'] ?? [],
            $this->config['real_estate'] ?? []
        );
    }

    /**
     * The point of the separate bands. At 5.1x net debt to EBITDA a REIT is
     * ordinarily financed, but the general scale calls anything above 4x
     * distressed — so every REIT scored zero leverage points regardless of its
     * balance sheet, and a term that never varies says nothing about anyone.
     */
    public function testOrdinaryReitLeverageIsNotScoredAsDistress(): void
    {
        $reit = $this->reit(5_360_000_000.0);
        $reit['total_debt'] = 27_800_000_000.0;   // ~5.1x net debt / EBITDA
        $reit['cash']       = 500_000_000.0;

        $pillar = $this->quality();
        $pillar->score($reit);

        $this->assertEqualsWithDelta(5.09, (float) ($pillar->steps()['net_debt_ebitda'] ?? 0.0), 0.05);
        $this->assertGreaterThan(0.0, (float) ($pillar->steps()['pts_leverage'] ?? 0.0));
    }

    public function testReitLeverageBandsAreLooserThanTheGeneralOnes(): void
    {
        $reit = $this->reit(5_360_000_000.0);
        $reit['total_debt'] = 27_800_000_000.0;
        $reit['cash']       = 500_000_000.0;

        $withBands = $this->quality();
        $withBands->score($reit);

        // Same company, no real-estate config: the general scale applies.
        $general = new QualityPillar($this->config['benchmarks']['Real Estate'] ?? [], []);
        $general->score($reit);

        $this->assertGreaterThan(
            (float) ($general->steps()['pts_leverage'] ?? 0.0),
            (float) ($withBands->steps()['pts_leverage'] ?? 0.0)
        );
    }

    public function testGenuinelyOverleveragedReitStillScoresZero(): void
    {
        $reit = $this->reit(2_000_000_000.0);
        $reit['total_debt'] = 20_000_000_000.0;   // 9.75x — past even the REIT band
        $reit['cash']       = 500_000_000.0;

        $pillar = $this->quality();
        $pillar->score($reit);

        $this->assertSame(0.0, (float) ($pillar->steps()['pts_leverage'] ?? -1.0));
    }

    public function testNonRealEstateKeepsTheGeneralBands(): void
    {
        $tech = [
            'sector'        => 'Technology',
            'ebitda'        => 1_000_000_000.0,
            'total_debt'    => 5_000_000_000.0,   // 4.5x — fine for a REIT, not here
            'cash'          => 0.0,
            'gross_margins' => 0.50,
        ];

        $pillar = $this->quality();
        $pillar->score($tech);

        $this->assertSame(0.0, (float) ($pillar->steps()['pts_leverage'] ?? -1.0));
    }
}
