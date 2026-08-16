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
 * Variant C — financials scored on price/book and returns.
 *
 * A bank's deposits and debt are its raw material, not a claim on its assets,
 * so enterprise value is not a figure anyone prices and "free cash flow" is not
 * a measure of anything. Yahoo reports bank gross profit as 0. Running them
 * through the ordinary EV/FCF and gross-margin paths scored them on noise, and
 * left "Banks - Regional" at n=0 in peer_medians while holding six large US
 * banks.
 *
 * Figures below are the real ones observed on 2026-08-16.
 */
class FinancialSectorScoringTest extends TestCase
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
        // upsertMedian() also appends to the history table; without it the
        // repository logs a harmless failure that clutters test output.
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
        );
    }

    /** @return array<string, mixed> */
    private function bank(float $pb, float $roe = 0.20, float $roa = 0.0188): array
    {
        return [
            'sector'           => 'Financial Services',
            'industry'         => 'Banks - Regional',
            'price_to_book'    => $pb,
            'return_on_equity' => $roe,
            'return_on_assets' => $roa,
            'payout_ratio'     => 0.63,
            // Deliberately present and useless, as they are for a real bank:
            // the ordinary variants would score on these and produce noise.
            'free_cash_flow'   => null,
            'gross_margins'    => 0.0,
        ];
    }

    public function testBankIsScoredOnPriceToBookNotEvFcf(): void
    {
        $pillar = $this->pillar();
        $pillar->score($this->bank(2.32)); // PKO.WA

        $this->assertSame('C', $pillar->steps()['variant']);
        $this->assertSame(2.32, $pillar->steps()['pb']);
    }

    public function testCheaperBookMultipleScoresHigherAtEqualReturns(): void
    {
        // Same ROE on both sides: the book multiple is then the only thing that
        // differs, and the cheaper one must win.
        $cheap = $this->pillar()->score($this->bank(1.38, 0.20)); // ALR.WA
        $rich  = $this->pillar()->score($this->bank(2.96, 0.20)); // ING.WA

        $this->assertGreaterThan($rich, $cheap);
    }

    /**
     * The inversion this exists to remove.
     *
     * A bank trades above book BECAUSE it earns above its cost of equity, so
     * comparing raw P/B to a peer median scores profitability as expense.
     * Measured across 20 banks on 2026-08-16 the correlation between ROE and the
     * Valuation score was -0.54: ING.WA (ROE 24.1%, Quality 100/100) scored 10.8
     * while Shinhan (ROE 8.9%, Quality 40/100) scored 79.5. Valuation and
     * quality were mechanically opposed inside the variant.
     */
    public function testHigherReturnEarnsItsHigherMultiple(): void
    {
        // Both pay the same price per unit of ROE (P/B ÷ ROE ≈ 12.3): the model
        // must see them as equally priced, not rank the profitable one worse.
        $profitable = $this->pillar()->score($this->bank(2.96, 0.241)); // ING.WA
        $weak       = $this->pillar()->score($this->bank(1.10, 0.089)); // ~Shinhan-like

        $this->assertEqualsWithDelta($profitable, $weak, 6.0);
    }

    public function testLossMakingBankFallsBackToPlainBookMultiple(): void
    {
        // ROE at or below zero cannot condition anything — the quotient flips.
        $pillar = $this->pillar();
        $pillar->score($this->bank(0.60, -0.05));

        $this->assertNull($pillar->steps()['pb_roe'], 'no ROE conditioning without a positive ROE');
        $this->assertSame(0.60, $pillar->steps()['pb']);
    }

    /**
     * HSBC reads P/B 8.24 because Yahoo divides an ADR price by an ORDINARY-share
     * book value — the depositary ratio, inside price_to_book this time, and not
     * recoverable (Yahoo returns no balance sheet for banks). Scoring it 0/100
     * asserts something false about a bank that is actually cheap.
     */
    public function testImplausibleBookMultipleDeclinesToScore(): void
    {
        $this->repo->upsertMedian(
            'industry', 'Banks - Diversified', 'Financial Services',
            (string) $this->config['model_version'], 'pb_roe', 14.0, 18
        );

        $bank = $this->bank(8.24, 0.131);           // P/B ÷ ROE ≈ 62.9, ~4.5x the median
        $bank['industry'] = 'Banks - Diversified';

        $pillar = $this->pillar();

        $this->assertSame(50.0, $pillar->score($bank));
        $this->assertSame('implausible_pb', $pillar->lastSource());
    }

    public function testAnExpensiveButPlausibleBankIsStillScored(): void
    {
        $this->repo->upsertMedian(
            'industry', 'Banks - Diversified', 'Financial Services',
            (string) $this->config['model_version'], 'pb_roe', 14.0, 18
        );

        // RY: P/B 3.26 at ROE 16.2% → 20.1, about 1.4x the median. Expensive,
        // not broken — the guard must not swallow it.
        $bank = $this->bank(3.26, 0.162);
        $bank['industry'] = 'Banks - Diversified';

        $pillar = $this->pillar();
        $score  = $pillar->score($bank);

        $this->assertNotSame('implausible_pb', $pillar->lastSource());
        $this->assertLessThan(50.0, $score, 'still reads as expensive');
    }

    public function testScoringIsDeterministic(): void
    {
        $a = $this->pillar()->score($this->bank(2.32));
        $b = $this->pillar()->score($this->bank(2.32));

        $this->assertSame($a, $b);
    }

    public function testMissingBookMultipleYieldsNeutralNotZero(): void
    {
        $bank = $this->bank(2.32);
        unset($bank['price_to_book']);

        $pillar = $this->pillar();

        $this->assertSame(50.0, $pillar->score($bank));
        $this->assertSame('missing_pb', $pillar->lastSource());
    }

    public function testNonFinancialSectorIsUnaffected(): void
    {
        $pillar = $this->pillar();
        $pillar->score([
            'sector'         => 'Technology',
            'industry'       => 'Semiconductors',
            'price_to_book'  => 2.32,
            'free_cash_flow' => 1_000_000.0,
            'current_price'  => 100.0,
            'shares_outstanding' => 1_000_000.0,
            'trailing_eps'   => 5.0,
            'forward_eps'    => 6.0,
        ]);

        $this->assertNotSame('C', $pillar->steps()['variant'] ?? null);
    }

    /**
     * Empirical peer median wins over the static cold-start benchmark.
     *
     * The bucket that matters is pb_roe, not pb: the pillar conditions the book
     * multiple on ROE, so that is the median it goes looking for.
     */
    public function testUsesEmpiricalPeerMedianWhenBucketIsDeepEnough(): void
    {
        $this->repo->upsertMedian('industry', 'Banks - Regional', 'Financial Services', (string) $this->config['model_version'], 'pb_roe', 11.5, 8);

        $pillar = $this->pillar();
        $pillar->score($this->bank(2.32, 0.201)); // PKO.WA — P/B ÷ ROE ≈ 11.5

        $this->assertSame('subsector', $pillar->lastSource());
        $this->assertSame(11.5, $pillar->steps()['sub_median']);
    }

    // -----------------------------------------------------------------------
    // Quality: returns, not margins
    // -----------------------------------------------------------------------

    private function quality(): QualityPillar
    {
        return new QualityPillar(
            $this->config['benchmarks']['Financial Services'] ?? [],
            $this->config['financials'] ?? []
        );
    }

    public function testStrongBankScoresHighOnReturns(): void
    {
        // ING.WA: ROE 24.1%, ROA 1.51%
        $score = $this->quality()->score($this->bank(2.96, 0.241, 0.0151));

        $this->assertGreaterThanOrEqual(80.0, $score);
    }

    public function testWeakBankScoresLowerThanStrongOne(): void
    {
        $strong = $this->quality()->score($this->bank(2.0, 0.241, 0.0151)); // ING.WA
        $weak   = $this->quality()->score($this->bank(2.0, 0.142, 0.0088)); // MIL.WA

        $this->assertGreaterThan($weak, $strong);
    }

    public function testGrossMarginOfZeroDoesNotSinkABank(): void
    {
        // The whole point: Yahoo reports 0 gross profit for banks, and the
        // ordinary path would score that as the worst possible margin.
        $score = $this->quality()->score($this->bank(2.32));

        $this->assertGreaterThan(50.0, $score);
    }

    public function testQualityUsesTheFinancialVariant(): void
    {
        $pillar = $this->quality();
        $pillar->score($this->bank(2.32));

        $this->assertSame('financial', $pillar->steps()['variant']);
    }
}
