<?php

declare(strict_types=1);

namespace CVS\Tests\Lab;

use CVS\Lab\LabRepository;
use CVS\TrackRecord\CvsSnapshotRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Uses SQLite in-memory to avoid a MySQL dependency in CI / local dev.
 * Schema mirrors database/migrations/029_create_lab_tables.sql with
 * SQLite-compatible syntax (same convention as PortfolioRepositoryTest).
 */
class LabRepositoryTest extends TestCase
{
    private PDO $db;
    private LabRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE lab_portfolio (
                code               TEXT    PRIMARY KEY,
                name               TEXT    NOT NULL,
                experiment_version TEXT    NOT NULL,
                started_at         TEXT,
                cash               REAL    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE lab_position (
                portfolio_code   TEXT NOT NULL,
                ticker           TEXT NOT NULL,
                quantity         REAL NOT NULL,
                avg_entry_price  REAL NOT NULL,
                entry_date       TEXT NOT NULL,
                PRIMARY KEY (portfolio_code, ticker)
            )
        ');

        $this->db->exec('
            CREATE TABLE lab_trade (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                portfolio_code TEXT    NOT NULL,
                trade_date     TEXT    NOT NULL,
                ticker         TEXT    NOT NULL,
                action         TEXT    NOT NULL,
                quantity       REAL    NOT NULL,
                price          REAL,
                fee            REAL,
                reason         TEXT    NOT NULL,
                status         TEXT    NOT NULL,
                created_at     TEXT    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE lab_nav (
                portfolio_code   TEXT NOT NULL,
                nav_date         TEXT NOT NULL,
                nav              REAL NOT NULL,
                cash             REAL NOT NULL,
                positions_value  REAL NOT NULL,
                PRIMARY KEY (portfolio_code, nav_date)
            )
        ');

        $this->db->exec('
            CREATE TABLE cvs_snapshots (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker              TEXT    NOT NULL,
                sector              TEXT,
                model_version       TEXT    NOT NULL,
                origin              TEXT    NOT NULL DEFAULT "rescore",
                cvs_swing           REAL,
                cvs_fund            REAL,
                price_at_snapshot   REAL,
                quality_gate        INTEGER NOT NULL DEFAULT 1,
                score_date          TEXT    NOT NULL
            )
        ');

        $this->repo = new LabRepository($this->db);
    }

    private function insertSnapshot(
        string $ticker,
        string $date,
        float $swing,
        ?float $fund,
        float $price,
        ?string $sector,
        string $modelVersion = '4.0',
        string $origin = 'rescore',
        int $qualityGate = 1
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO cvs_snapshots (ticker, sector, model_version, origin, cvs_swing, cvs_fund, price_at_snapshot, quality_gate, score_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ticker, $sector, $modelVersion, $origin, $swing, $fund, $price, $qualityGate, $date]);
    }

    // ------------------------------------------------------------------
    // findCandidatesForDate
    // ------------------------------------------------------------------

    public function testFindCandidatesForDateFiltersLiveModelVersionAndRescoreOrigin(): void
    {
        $this->insertSnapshot('NVDA', '2026-07-01', 90.0, 88.0, 120.0, 'Technology', '4.0', 'rescore');
        $this->insertSnapshot('NVDA', '2026-07-01', 70.0, 65.0, 120.0, 'Technology', '3.1', 'rescore'); // shadow row — must be excluded
        $this->insertSnapshot('MU', '2026-07-01', 85.0, 80.0, 95.0, 'Technology', '4.0', 'corpus');     // corpus row — must be excluded

        $candidates = $this->repo->findCandidatesForDate('2026-07-01', '4.0');

        $this->assertCount(1, $candidates);
        $this->assertSame('NVDA', $candidates[0]['ticker']);
        $this->assertEqualsWithDelta(90.0, $candidates[0]['cvs_swing'], 1e-9);
    }

    public function testFindCandidatesForDateExcludesNonUsTickers(): void
    {
        $this->insertSnapshot('KGH.WA', '2026-07-01', 95.0, 90.0, 40.0, 'Basic Materials');
        $this->insertSnapshot('AAPL', '2026-07-01', 80.0, 78.0, 200.0, 'Technology');

        $candidates = $this->repo->findCandidatesForDate('2026-07-01', '4.0');

        $this->assertCount(1, $candidates);
        $this->assertSame('AAPL', $candidates[0]['ticker']);
    }

    public function testFindCandidatesForDateExcludesFailedQualityGate(): void
    {
        $this->insertSnapshot('BADCO', '2026-07-01', 10.0, 5.0, 5.0, 'Energy', '4.0', 'rescore', 0);

        $this->assertSame([], $this->repo->findCandidatesForDate('2026-07-01', '4.0'));
    }

    public function testFindCandidatesForDateUsesLiveOriginConstant(): void
    {
        // Guards against a casing mismatch between the constant used here and in
        // fixtures — CvsSnapshotRepository::ORIGIN_RESCORE is 'rescore' (lowercase);
        // SQLite string comparison is case-sensitive, unlike MySQL's default collation.
        $this->assertSame('rescore', CvsSnapshotRepository::ORIGIN_RESCORE);
    }

    // ------------------------------------------------------------------
    // Portfolio registry
    // ------------------------------------------------------------------

    public function testInitPortfolioIsIdempotent(): void
    {
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 100000.0);
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 100000.0); // second call — no-op, no exception

        $portfolio = $this->repo->getPortfolio('P1');
        $this->assertNotNull($portfolio);
        $this->assertEqualsWithDelta(100000.0, (float) $portfolio['cash'], 1e-9);
        $this->assertNull($portfolio['started_at']);
    }

    public function testGetPortfolioReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->getPortfolio('NOPE'));
    }

    public function testMarkStartedOnlySetsDateOnce(): void
    {
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 100000.0);
        $this->repo->markStarted('P1', '2026-07-01');
        $this->repo->markStarted('P1', '2026-08-01'); // must NOT overwrite

        $portfolio = $this->repo->getPortfolio('P1');
        $this->assertSame('2026-07-01', $portfolio['started_at']);
    }

    // ------------------------------------------------------------------
    // applyTrade — atomicity + position/cash mutation
    // ------------------------------------------------------------------

    public function testApplyTradeFilledBuyReducesCashAndCreatesPosition(): void
    {
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 1000.0);

        $this->repo->applyTrade('P1', '2026-07-01', [
            'ticker' => 'AAA', 'action' => 'BUY', 'quantity' => 5.0, 'price' => 100.0, 'fee' => 0.5, 'reason' => 'seed',
        ], 'filled');

        $portfolio = $this->repo->getPortfolio('P1');
        $this->assertEqualsWithDelta(1000.0 - 500.5, (float) $portfolio['cash'], 1e-9);

        $positions = $this->repo->getPositions('P1');
        $this->assertArrayHasKey('AAA', $positions);
        $this->assertEqualsWithDelta(5.0, $positions['AAA']['quantity'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $positions['AAA']['avg_entry_price'], 1e-9);
    }

    public function testApplyTradeFilledBuyRecomputesWeightedAverageEntryPrice(): void
    {
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 10000.0);

        $this->repo->applyTrade('P1', '2026-07-01', [
            'ticker' => 'AAA', 'action' => 'BUY', 'quantity' => 10.0, 'price' => 100.0, 'fee' => 0.0, 'reason' => 'seed',
        ], 'filled');
        $this->repo->applyTrade('P1', '2026-08-01', [
            'ticker' => 'AAA', 'action' => 'BUY', 'quantity' => 10.0, 'price' => 120.0, 'fee' => 0.0, 'reason' => 'rebalance',
        ], 'filled');

        $positions = $this->repo->getPositions('P1');
        $this->assertEqualsWithDelta(20.0, $positions['AAA']['quantity'], 1e-9);
        $this->assertEqualsWithDelta(110.0, $positions['AAA']['avg_entry_price'], 1e-9); // (10*100 + 10*120)/20
    }

    public function testApplyTradeFilledSellIncreasesCashAndDeletesFullyClosedPosition(): void
    {
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 0.0);
        $this->repo->applyTrade('P1', '2026-07-01', [
            'ticker' => 'AAA', 'action' => 'BUY', 'quantity' => 10.0, 'price' => 100.0, 'fee' => 0.0, 'reason' => 'seed',
        ], 'filled');

        $this->repo->applyTrade('P1', '2026-08-01', [
            'ticker' => 'AAA', 'action' => 'SELL', 'quantity' => 10.0, 'price' => 110.0, 'fee' => 1.0, 'reason' => 'rebalance',
        ], 'filled');

        $portfolio = $this->repo->getPortfolio('P1');
        $this->assertEqualsWithDelta(-1000.0 + (1100.0 - 1.0), (float) $portfolio['cash'], 1e-9);
        $this->assertArrayNotHasKey('AAA', $this->repo->getPositions('P1'));
    }

    public function testApplyTradePendingStoresNullPriceAndDoesNotMutatePositionOrCash(): void
    {
        $this->repo->initPortfolio('P2', 'Egzekucja na otwarciu', '1', 1000.0);

        $this->repo->applyTrade('P2', '2026-07-01', [
            'ticker' => 'AAA', 'action' => 'BUY', 'quantity' => 5.0, 'price' => 100.0, 'fee' => 0.5, 'reason' => 'rebalance',
        ], 'pending');

        // Cash and positions untouched — the fill happens the next day.
        $portfolio = $this->repo->getPortfolio('P2');
        $this->assertEqualsWithDelta(1000.0, (float) $portfolio['cash'], 1e-9);
        $this->assertSame([], $this->repo->getPositions('P2'));

        $pending = $this->repo->findPendingTrades('P2');
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]['price']);
        $this->assertEqualsWithDelta(5.0, (float) $pending[0]['quantity'], 1e-9);
    }

    public function testFindPendingTradesExcludesFilledTrades(): void
    {
        $this->repo->initPortfolio('P1', 'Bazowy CVS', '1', 1000.0);
        $this->repo->applyTrade('P1', '2026-07-01', [
            'ticker' => 'AAA', 'action' => 'BUY', 'quantity' => 1.0, 'price' => 100.0, 'fee' => 0.0, 'reason' => 'seed',
        ], 'filled');

        $this->assertSame([], $this->repo->findPendingTrades('P1'));
    }

    // ------------------------------------------------------------------
    // upsertNav — idempotency
    // ------------------------------------------------------------------

    public function testUpsertNavIsIdempotentForSamePortfolioAndDate(): void
    {
        $this->repo->upsertNav('P1', '2026-07-01', 1000.0, 200.0, 800.0);
        $this->repo->upsertNav('P1', '2026-07-01', 1050.0, 250.0, 800.0); // re-run same day — updates in place

        $series = $this->repo->getNavSeries();
        $this->assertCount(1, $series['P1']);
        $this->assertEqualsWithDelta(1050.0, $series['P1'][0]['nav'], 1e-9);
    }

    public function testGetNavSeriesReturnsOldestFirstPerPortfolio(): void
    {
        $this->repo->upsertNav('P1', '2026-07-03', 1030.0, 0.0, 1030.0);
        $this->repo->upsertNav('P1', '2026-07-01', 1000.0, 0.0, 1000.0);
        $this->repo->upsertNav('P1', '2026-07-02', 1010.0, 0.0, 1010.0);
        $this->repo->upsertNav('P0', '2026-07-01', 500.0, 0.0, 500.0);

        $series = $this->repo->getNavSeries();

        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], array_column($series['P1'], 'date'));
        $this->assertArrayHasKey('P0', $series);
    }
}
