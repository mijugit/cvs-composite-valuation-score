<?php

declare(strict_types=1);

namespace CVS\Tests\Lab;

use CVS\Alerts\PriceAlertRepository;
use CVS\Api\FinancialDataFetcher;
use CVS\Lab\LabRepository;
use CVS\Lab\LabTickService;
use CVS\Portfolio\MarketCalendar;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fake FinancialDataFetcher — canned OHLC/latest-price data, no network.
 * Overrides exactly the three public methods LabTickService calls on it
 * (mirrors the FakeTransport pattern in DecisionServiceTest).
 */
final class FakeLabFetcher extends FinancialDataFetcher
{
    /**
     * @param array<string, array{open: float[], high: float[], low: float[], close: float[], date: string[]}> $ohlcByTicker
     * @param array<string, float> $latestPrices
     */
    public function __construct(private readonly array $ohlcByTicker, private readonly array $latestPrices = [])
    {
        parent::__construct([]);
    }

    public function fetchDailyOhlc(string $ticker, string $range): array
    {
        return $this->ohlcByTicker[strtoupper($ticker)]
            ?? ['open' => [], 'high' => [], 'low' => [], 'close' => [], 'date' => []];
    }

    public function fetchSpyDailyCloses(): ?array
    {
        return null; // not exercised — this golden scenario has no SPY (P0) portfolio
    }

    public function fetchLatestPrice(string $ticker, string $range = '1d'): ?float
    {
        return $this->latestPrices[strtoupper($ticker)] ?? null;
    }
}

/**
 * Golden 4-day scenario (change: cvs-experimental-portfolios, Phase 2) covering
 * two portfolio variants side by side: P1 (close-execution) and P2 (open-execution,
 * i.e. the P2 "egzekucja na otwarciu" shape) — both equal-weight, top_n=1, no stops,
 * zero cost fraction (fee mechanics are already covered by LabEngineTest/LabRepositoryTest;
 * this test isolates sequencing, pending fills, and idempotency).
 *
 * Calendar: D0=2026-06-29 (Mon, seed) -> D1=2026-06-30 (Tue, plain NAV day,
 * fills P2's D0 pending seed) -> D2=2026-07-01 (Wed, first NYSE session of
 * July -> rebalance AAA->BBB for both) -> D3=2026-07-02 (Thu, fills P2's D2
 * pending rebalance). D2's tick is re-run to verify no duplicate trades/NAV rows.
 */
class LabTickServiceTest extends TestCase
{
    private PDO $db;
    private LabRepository $repo;
    private LabTickService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('CREATE TABLE lab_portfolio (
            code TEXT PRIMARY KEY, name TEXT NOT NULL, experiment_version TEXT NOT NULL,
            started_at TEXT, cash REAL NOT NULL
        )');
        $this->db->exec('CREATE TABLE lab_position (
            portfolio_code TEXT NOT NULL, ticker TEXT NOT NULL, quantity REAL NOT NULL,
            avg_entry_price REAL NOT NULL, entry_date TEXT NOT NULL,
            PRIMARY KEY (portfolio_code, ticker)
        )');
        $this->db->exec('CREATE TABLE lab_trade (
            id INTEGER PRIMARY KEY AUTOINCREMENT, portfolio_code TEXT NOT NULL, trade_date TEXT NOT NULL,
            ticker TEXT NOT NULL, action TEXT NOT NULL, quantity REAL NOT NULL, price REAL, fee REAL,
            reason TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE lab_nav (
            portfolio_code TEXT NOT NULL, nav_date TEXT NOT NULL, nav REAL NOT NULL,
            cash REAL NOT NULL, positions_value REAL NOT NULL,
            PRIMARY KEY (portfolio_code, nav_date)
        )');
        $this->db->exec('CREATE TABLE cvs_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL, sector TEXT,
            model_version TEXT NOT NULL, origin TEXT NOT NULL DEFAULT "rescore",
            cvs_swing REAL, cvs_fund REAL, price_at_snapshot REAL,
            quality_gate INTEGER NOT NULL DEFAULT 1, score_date TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE ticker_zone (
            ticker TEXT PRIMARY KEY, zone_low REAL, zone_high REAL, stop_swing REAL, stop_fund REAL,
            fx_rate_to_usd REAL, source TEXT, computed_at TEXT NOT NULL
        )');

        $this->seedSnapshot('AAA', '2026-06-29', 100.0);
        $this->seedSnapshot('AAA', '2026-06-30', 105.0);
        $this->seedSnapshot('BBB', '2026-07-01', 50.0);
        $this->seedSnapshot('BBB', '2026-07-02', 52.0);
        // AAA has no snapshot from 2026-07-01 onward — it fell out of the watchlist,
        // forcing the D2 rebalance to exit it via the fallbackPrice() path.

        $this->repo = new LabRepository($this->db);

        $fetcher = new FakeLabFetcher(
            ohlcByTicker: [
                'AAA' => [
                    'open' => [99.0, 103.0], 'high' => [101.0, 104.0], 'low' => [98.0, 101.0],
                    'close' => [100.0, 103.5], 'date' => ['2026-06-30', '2026-07-02'],
                ],
                'BBB' => [
                    'open' => [49.0, 49.5], 'high' => [51.0, 53.0], 'low' => [48.0, 49.0],
                    'close' => [50.0, 52.0], 'date' => ['2026-07-01', '2026-07-02'],
                ],
            ],
            latestPrices: ['AAA' => 103.0], // fallback exit price once AAA leaves the watchlist
        );

        $calendar = new MarketCalendar(['market' => ['timezone' => 'America/New_York'], 'holidays' => []]);

        $labConfig = [
            'experiment_version'  => '1',
            'initial_capital_usd' => 1000.0,
            'cost_per_side_frac'  => 0.0,
            'selection'           => ['top_n' => 1, 'rank_by' => 'cvs_swing'],
            'rebalance'           => ['frequency' => 'monthly'],
            'portfolios' => [
                'P1' => ['name' => 'Bazowy CVS',         'rules' => ['execution' => 'close', 'weighting' => 'equal', 'stops' => null, 'sector_cap_pct' => null, 'benchmark_ticker' => null]],
                'P2' => ['name' => 'Egzekucja na otwarciu', 'rules' => ['execution' => 'open',  'weighting' => 'equal', 'stops' => null, 'sector_cap_pct' => null, 'benchmark_ticker' => null]],
            ],
        ];

        $this->service = new LabTickService($this->repo, $fetcher, new PriceAlertRepository($this->db), $calendar, $labConfig, '4.0');
    }

    private function seedSnapshot(string $ticker, string $date, float $price, float $swing = 90.0): void
    {
        $this->db->prepare(
            'INSERT INTO cvs_snapshots (ticker, sector, model_version, origin, cvs_swing, cvs_fund, price_at_snapshot, quality_gate, score_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$ticker, 'Technology', '4.0', 'rescore', $swing, 88.0, $price, $date]);
    }

    private function et(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime, new DateTimeZone('America/New_York'));
    }

    public function testFourDayScenarioProducesTheExpectedTradesAndNav(): void
    {
        // --- D0: seed both portfolios ---
        $d0 = $this->service->run($this->et('2026-06-29 20:00:00'));
        $this->assertSame(2, $d0['seeded']);
        $this->assertSame([], $d0['errors']);

        $p1 = $this->repo->getPositions('P1');
        $this->assertEqualsWithDelta(10.0, $p1['AAA']['quantity'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $p1['AAA']['avg_entry_price'], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $this->repo->getPortfolio('P1')['cash'], 1e-9);

        // P2 is open-execution: the seed BUY is queued pending, not yet applied.
        $this->assertSame([], $this->repo->getPositions('P2'));
        $this->assertEqualsWithDelta(1000.0, (float) $this->repo->getPortfolio('P2')['cash'], 1e-9);
        $pendingAfterD0 = $this->repo->findPendingTrades('P2');
        $this->assertCount(1, $pendingAfterD0);
        $this->assertSame('BUY', $pendingAfterD0[0]['action']);
        $this->assertNull($pendingAfterD0[0]['price']);

        $navD0 = $this->repo->getNavSeries();
        $this->assertEqualsWithDelta(1000.0, $navD0['P1'][0]['nav'], 1e-9);
        $this->assertEqualsWithDelta(1000.0, $navD0['P2'][0]['nav'], 1e-9); // all cash, position not yet filled

        // --- D1: plain NAV day; fills P2's D0 seed at D1's open (99.0) ---
        $d1 = $this->service->run($this->et('2026-06-30 20:00:00'));
        $this->assertSame(0, $d1['seeded']);
        $this->assertSame(0, $d1['rebalanced']);

        $p2 = $this->repo->getPositions('P2');
        $this->assertEqualsWithDelta(10.0, $p2['AAA']['quantity'], 1e-9);
        $this->assertEqualsWithDelta(99.0, $p2['AAA']['avg_entry_price'], 1e-9);
        $this->assertEqualsWithDelta(10.0, (float) $this->repo->getPortfolio('P2')['cash'], 1e-9); // 1000 - 10*99
        $this->assertSame([], $this->repo->findPendingTrades('P2'));

        $navD1 = $this->repo->getNavSeries();
        $this->assertEqualsWithDelta(1050.0, $navD1['P1'][1]['nav'], 1e-9); // 10 * 105 close
        $this->assertEqualsWithDelta(1060.0, $navD1['P2'][1]['nav'], 1e-9); // 10*105 + 10 cash

        // --- D2: first NYSE session of July -> rebalance AAA -> BBB for both ---
        $d2 = $this->service->run($this->et('2026-07-01 20:00:00'));
        $this->assertSame(2, $d2['rebalanced']);

        // P1 (close-execution) rebalances immediately.
        $p1AfterD2 = $this->repo->getPositions('P1');
        $this->assertArrayNotHasKey('AAA', $p1AfterD2);
        $this->assertEqualsWithDelta(20.6, $p1AfterD2['BBB']['quantity'], 1e-9); // (10*103 proceeds) / 50
        $this->assertEqualsWithDelta(0.0, (float) $this->repo->getPortfolio('P1')['cash'], 1e-9);

        // P2 (open-execution) queues both legs pending — position/cash untouched today.
        $p2AfterD2 = $this->repo->getPositions('P2');
        $this->assertArrayHasKey('AAA', $p2AfterD2); // still held — fill happens D3
        $this->assertEqualsWithDelta(10.0, (float) $this->repo->getPortfolio('P2')['cash'], 1e-9); // unchanged
        $pendingAfterD2 = $this->repo->findPendingTrades('P2');
        $this->assertCount(2, $pendingAfterD2);
        foreach ($pendingAfterD2 as $row) {
            $this->assertSame('rebalance', $row['reason']);
            $this->assertNull($row['price']);
        }

        // --- D2 RE-RUN: must not duplicate any trade or NAV row ---
        $d2Again = $this->service->run($this->et('2026-07-01 20:05:00'));
        $this->assertSame(0, $d2Again['rebalanced'], 'hasTradeToday guard must block a same-day re-decision');

        $this->assertCount(2, $this->repo->findPendingTrades('P2'), 'no duplicate pending trades from the re-run');
        $tradeCountP1 = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM lab_trade WHERE portfolio_code='P1' AND trade_date='2026-07-01'"
        )->fetch()['c'];
        $this->assertSame(2, $tradeCountP1, 'P1 rebalanced with exactly SELL AAA + BUY BBB, not duplicated by the re-run');
        $navRowsD2 = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM lab_nav WHERE portfolio_code='P1' AND nav_date='2026-07-01'"
        )->fetch()['c'];
        $this->assertSame(1, $navRowsD2, 'NAV upsert stays a single row per (portfolio, date)');

        // --- D3: fills P2's D2-decided pending trades at D3's open ---
        $d3 = $this->service->run($this->et('2026-07-02 20:00:00'));

        $p2AfterD3 = $this->repo->getPositions('P2');
        $this->assertArrayNotHasKey('AAA', $p2AfterD3);
        $this->assertEqualsWithDelta(20.8, $p2AfterD3['BBB']['quantity'], 1e-9); // 1040 target / 50 estimate
        $this->assertEqualsWithDelta(49.5, $p2AfterD3['BBB']['avg_entry_price'], 1e-9); // filled at D3's real open
        $this->assertSame([], $this->repo->findPendingTrades('P2'));
        $this->assertEqualsWithDelta(10.4, (float) $this->repo->getPortfolio('P2')['cash'], 1e-9);

        $navD3 = $this->repo->getNavSeries();
        $this->assertEqualsWithDelta(1071.2, $navD3['P1'][array_key_last($navD3['P1'])]['nav'], 1e-9);
        $this->assertEqualsWithDelta(1092.0, $navD3['P2'][array_key_last($navD3['P2'])]['nav'], 1e-9);

        $this->assertSame([], $d3['errors']);
    }

    /**
     * Per-portfolio rebalance cadence (change: cvs-experimental-portfolios P7/P8,
     * rules.rebalance_frequency) — two portfolios, identical rules except cadence
     * ('daily' vs 'weekly'), fed a top-ranked ticker that flips every session.
     * A daily portfolio must chase every flip; a weekly one must only re-decide
     * on the first NYSE session of the ISO week (Monday), holding its position
     * on every other day even though the ranking underneath it keeps changing.
     *
     * Calendar: D0=2026-07-06 (Mon, seed, CCC tops) -> D1=2026-07-07 (Tue, DDD
     * tops) -> D2=2026-07-08 (Wed, CCC tops again) -> D3=2026-07-13 (Mon, next
     * week, DDD tops). top_n=1, equal weight, no stops, no sector cap, zero cost
     * -> every rebalance-that-fires is a full SELL+BUY switch (2 trades).
     */
    public function testDailyAndWeeklyRebalanceCadenceDivergeOnTheSameRankingFlips(): void
    {
        foreach ([
            ['CCC', '2026-07-06', 90.0, 10.0], ['DDD', '2026-07-06', 50.0, 20.0],
            ['CCC', '2026-07-07', 50.0, 10.0], ['DDD', '2026-07-07', 90.0, 20.0],
            ['CCC', '2026-07-08', 90.0, 10.0], ['DDD', '2026-07-08', 50.0, 20.0],
            ['CCC', '2026-07-13', 50.0, 10.0], ['DDD', '2026-07-13', 90.0, 20.0],
        ] as [$ticker, $date, $swing, $price]) {
            $this->seedSnapshot($ticker, $date, $price, $swing);
        }

        $fetcher = new FakeLabFetcher(ohlcByTicker: []); // not exercised: close-execution, no stops
        $calendar = new MarketCalendar(['market' => ['timezone' => 'America/New_York'], 'holidays' => []]);
        $labConfig = [
            'experiment_version'  => '2',
            'initial_capital_usd' => 1000.0,
            'cost_per_side_frac'  => 0.0,
            'selection'           => ['top_n' => 1, 'rank_by' => 'cvs_swing'],
            'rebalance'           => ['frequency' => 'monthly'],
            'portfolios' => [
                'PD' => ['name' => 'Daily cadence',  'rules' => ['execution' => 'close', 'weighting' => 'equal', 'stops' => null, 'sector_cap_pct' => null, 'benchmark_ticker' => null, 'rebalance_frequency' => 'daily']],
                'PW' => ['name' => 'Weekly cadence', 'rules' => ['execution' => 'close', 'weighting' => 'equal', 'stops' => null, 'sector_cap_pct' => null, 'benchmark_ticker' => null, 'rebalance_frequency' => 'weekly']],
            ],
        ];
        $service = new LabTickService($this->repo, $fetcher, new PriceAlertRepository($this->db), $calendar, $labConfig, '4.0');

        $service->run($this->et('2026-07-06 20:00:00')); // seed both on CCC
        $service->run($this->et('2026-07-07 20:00:00')); // DDD tops — PD chases, PW holds
        $service->run($this->et('2026-07-08 20:00:00')); // CCC tops — PD chases back, PW still holds
        $service->run($this->et('2026-07-13 20:00:00')); // next Monday, DDD tops — PW finally re-decides

        $pd = $this->repo->getPositions('PD');
        $pw = $this->repo->getPositions('PW');
        $this->assertArrayHasKey('DDD', $pd, 'daily portfolio ends on the last flip (DDD)');
        $this->assertArrayNotHasKey('CCC', $pd);
        $this->assertArrayHasKey('DDD', $pw, 'weekly portfolio catches up only on the next Monday');
        $this->assertArrayNotHasKey('CCC', $pw);

        $pdRebalanceTrades = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM lab_trade WHERE portfolio_code='PD' AND reason='rebalance'"
        )->fetch()['c'];
        $pwRebalanceTrades = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM lab_trade WHERE portfolio_code='PW' AND reason='rebalance'"
        )->fetch()['c'];
        $this->assertSame(6, $pdRebalanceTrades, 'daily portfolio switches on D1, D2, D3 — 3 x (SELL+BUY)');
        $this->assertSame(2, $pwRebalanceTrades, 'weekly portfolio switches only on D3 (next Monday) — 1 x (SELL+BUY)');
    }
}
