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

    /**
     * XTB.WA: Yahoo publishes no returnOnEquity for this ticker at all.
     * Before this fix the pillar silently fell back to plain P/B (0.1/100
     * on a P/B of 7.9x) — exactly the -0.54 correlation this variant exists
     * to remove. A true data gap now gets no opinion, like missing_pb.
     */
    public function testTrueRoeGapYieldsNeutralNotPlainPriceToBook(): void
    {
        $bank = $this->bank(7.87);
        unset($bank['return_on_equity']);

        $pillar = $this->pillar();

        $this->assertSame(50.0, $pillar->score($bank));
        $this->assertSame('missing_roe', $pillar->lastSource());
    }

    /**
     * When FinancialDataFetcher has ALREADY derived ROE from P/B ÷ P/E
     * (return_on_equity_source = 'derived_pb_pe'), that value scores
     * normally — a true gap is only when neither Yahoo nor derivation had
     * anything, which return_on_equity === null represents either way.
     */
    public function testDerivedRoeSourceScoresNormally(): void
    {
        $bank = $this->bank(7.87, 0.388);
        $bank['return_on_equity_source'] = 'derived_pb_pe';

        $pillar = $this->pillar();
        $pillar->score($bank);

        $this->assertNotNull($pillar->steps()['pb_roe'], 'ROE conditioning must apply to a derived ROE too');
        $this->assertNotSame('missing_roe', $pillar->lastSource());
    }

    /**
     * SAN.WA: financial_currency (EUR) differs from the quote currency
     * (PLN), so Yahoo's price_to_book divides a PLN price by a EUR book
     * value — measured 2026-08-17 at ~4.3x too high. Reported ROE (13.1%,
     * unaffected — no price involved) disagrees with the P/B-÷-P/E-derived
     * figure (49.9%) by 3.8x, well past every legitimate gap observed
     * across 16 controls (max 1.3x). The gate declines to score rather
     * than trust the broken ratio.
     */
    public function testCrossCurrencyPriceToBookIsCaughtByRoeDivergence(): void
    {
        $bank = $this->bank(7.29535, 0.13109);
        $bank['return_on_equity_source'] = 'yahoo';
        $bank['trailing_pe']             = 14.619423;

        $pillar = $this->pillar();

        $this->assertSame(50.0, $pillar->score($bank));
        $this->assertSame('roe_divergence', $pillar->lastSource());
    }

    /**
     * The gate must not swallow ordinary banks whose derived and reported
     * ROE merely differ by the measured average-vs-end-of-period gap.
     */
    public function testOrdinaryRoeGapDoesNotTriggerDivergenceGate(): void
    {
        // PKO.WA: P/B 2.31, trailing P/E 12.77 → derived ROE ≈ 18.1%, vs
        // reported 20.1% — about 1.1x, comfortably inside the 2.0x gate.
        $bank = $this->bank(2.31, 0.201);
        $bank['return_on_equity_source'] = 'yahoo';
        $bank['trailing_pe']             = 12.77;

        $pillar = $this->pillar();
        $pillar->score($bank);

        $this->assertNotSame('roe_divergence', $pillar->lastSource());
    }

    /**
     * The gate is a no-op when Yahoo never published its own ROE — checking
     * a derived figure against itself is meaningless.
     */
    public function testDivergenceGateSkipsWhenRoeIsAlreadyDerived(): void
    {
        $bank = $this->bank(7.29535, 0.499); // hypothetically "confirms" the bad P/B
        $bank['return_on_equity_source'] = 'derived_pb_pe';
        $bank['trailing_pe']             = 14.619423;

        $pillar = $this->pillar();
        $pillar->score($bank);

        $this->assertNotSame('roe_divergence', $pillar->lastSource());
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

    /**
     * XTB.WA: Yahoo publishes neither returnOnEquity nor returnOnAssets.
     * Before this fix both scored 0/4 — the same treatment as a measured
     * loss — dragging the whole pillar down for a data gap rather than a
     * weak bank (score_raw 2/10 → 20.0, matching the value this test guards
     * against regressing to). Absence should score the neutral middle, the
     * same convention already applied to payout_ratio below.
     */
    public function testMissingRoeAndRoaScoreNeutralNotZero(): void
    {
        $bank = $this->bank(7.87);
        unset($bank['return_on_equity'], $bank['return_on_assets']);

        $pillar = $this->quality();
        $pillar->score($bank);

        $this->assertSame(2.0, $pillar->steps()['pts_roe']);
        $this->assertSame(2.0, $pillar->steps()['pts_roa']);
        $this->assertGreaterThan(20.0, $pillar->rawScore() / 10.0 * 100.0);
    }

    /**
     * A MEASURED loss is real information, not a gap — it must still score
     * 0, unlike the missing-data case above.
     */
    public function testMeasuredLossStillScoresZero(): void
    {
        $bank = $this->bank(0.60, -0.05, -0.01);

        $pillar = $this->quality();
        $pillar->score($bank);

        $this->assertSame(0.0, $pillar->steps()['pts_roe']);
        $this->assertSame(0.0, $pillar->steps()['pts_roa']);
    }
}
