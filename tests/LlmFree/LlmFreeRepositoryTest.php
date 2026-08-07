<?php

declare(strict_types=1);

namespace CVS\Tests\LlmFree;

use CVS\LlmFree\LlmFreeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Uses SQLite in-memory to avoid MySQL dependency in CI / local dev.
 * Schema mirrors the MySQL migration with SQLite-compatible syntax.
 */
class LlmFreeRepositoryTest extends TestCase
{
    private PDO              $db;
    private LlmFreeRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE llm_free_cycle (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date          TEXT    NOT NULL UNIQUE,
                status              TEXT    NOT NULL DEFAULT "started",
                started_at          TEXT    NOT NULL,
                finished_at         TEXT,
                cash_before         REAL,
                cash_after          REAL,
                portfolio_value_usd REAL,
                executed_count      INTEGER NOT NULL DEFAULT 0,
                skipped_count       INTEGER NOT NULL DEFAULT 0,
                notes               TEXT,
                legend              TEXT,
                tokens_input        INTEGER NOT NULL DEFAULT 0,
                tokens_output       INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->db->exec('
            CREATE TABLE llm_free_state (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                cash            REAL    NOT NULL,
                initial_capital REAL    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE llm_free_holdings (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker          TEXT    NOT NULL UNIQUE,
                quantity        INTEGER NOT NULL DEFAULT 0,
                avg_entry_price REAL    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE cvs_snapshots (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker              TEXT    NOT NULL,
                company_name        TEXT,
                model_version       TEXT    NOT NULL,
                origin              TEXT    NOT NULL DEFAULT "RESCORE",
                reco_swing          TEXT,
                reco_fund           TEXT,
                cvs_swing           REAL,
                cvs_fund            REAL,
                price_at_snapshot   REAL,
                score_date          TEXT    NOT NULL,
                scored_at           TEXT    NOT NULL
            )
        ');

        $this->repo = new LlmFreeRepository($this->db);
    }

    // --- getCurrentState ---

    public function testGetCurrentStateReturnsSeededRow(): void
    {
        $this->db->exec("INSERT INTO llm_free_state (cash, initial_capital, updated_at)
                         VALUES (10000.00, 10000.00, '2026-08-07 12:00:00')");

        $state = $this->repo->getCurrentState();

        $this->assertEqualsWithDelta(10000.0, (float) $state['cash'], 0.001);
        $this->assertEqualsWithDelta(10000.0, (float) $state['initial_capital'], 0.001);
        $this->assertArrayHasKey('updated_at', $state);
    }

    public function testGetCurrentStateThrowsWhenEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not initialized/i');

        $this->repo->getCurrentState();
    }

    // --- getCurrentHoldings ---

    public function testGetCurrentHoldingsReturnsEmptyWhenNoHoldings(): void
    {
        $this->assertSame([], $this->repo->getCurrentHoldings());
    }

    public function testGetCurrentHoldingsExcludesZeroQuantity(): void
    {
        $this->db->exec("INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0000, '2026-08-07 12:00:00')");
        $this->db->exec("INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('MSFT', 0, 300.0000, '2026-08-07 12:00:00')");

        $holdings = $this->repo->getCurrentHoldings();

        $this->assertCount(1, $holdings);
        $this->assertSame('AAPL', $holdings[0]['ticker']);
    }

    public function testGetCurrentHoldingsOrderedByTicker(): void
    {
        $this->db->exec("INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('NVDA', 5, 800.0, '2026-08-07 12:00:00')");
        $this->db->exec("INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0, '2026-08-07 12:00:00')");

        $holdings = $this->repo->getCurrentHoldings();

        $this->assertSame('AAPL', $holdings[0]['ticker']);
        $this->assertSame('NVDA', $holdings[1]['ticker']);
    }

    // --- getCurrentHoldingsWithPrice ---

    public function testGetCurrentHoldingsWithPriceIncludesCompanyNameAndScores(): void
    {
        $this->db->exec("INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AVGO', 10, 180.00, '2026-08-01 12:00:00')");
        $this->db->exec("INSERT INTO cvs_snapshots
                         (ticker, company_name, model_version, origin, reco_swing, reco_fund,
                          cvs_swing, cvs_fund, price_at_snapshot, score_date, scored_at)
                         VALUES ('AVGO', 'Broadcom Inc.', '4.0', 'RESCORE', '⬆ AKUMULUJ', '⬆ AKUMULUJ',
                          57.9, 54.3, 208.50, '2026-08-02', '2026-08-02 16:00:00')");

        $holdings = $this->repo->getCurrentHoldingsWithPrice('4.0');

        $this->assertCount(1, $holdings);
        $this->assertSame('Broadcom Inc.', $holdings[0]['company_name']);
        $this->assertEqualsWithDelta(57.9, $holdings[0]['cvs_swing'], 0.001);
        $this->assertEqualsWithDelta(54.3, $holdings[0]['cvs_fund'], 0.001);
    }

    public function testGetCurrentHoldingsWithPriceHandlesMissingSnapshotGracefully(): void
    {
        $this->db->exec("INSERT INTO llm_free_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('NEWCO', 5, 50.00, '2026-08-01 12:00:00')");

        $holdings = $this->repo->getCurrentHoldingsWithPrice('4.0');

        $this->assertCount(1, $holdings);
        $this->assertNull($holdings[0]['company_name']);
        $this->assertNull($holdings[0]['cvs_swing']);
        $this->assertNull($holdings[0]['cvs_fund']);
        // Falls back to avg_entry_price when no snapshot exists.
        $this->assertEqualsWithDelta(50.0, $holdings[0]['live_price'], 0.001);
        $this->assertFalse($holdings[0]['price_is_snapshot']);
    }

    // --- getLatestCycle ---

    public function testGetLatestCycleReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->repo->getLatestCycle());
    }

    public function testGetLatestCycleReturnsMostRecent(): void
    {
        $this->db->exec("INSERT INTO llm_free_cycle (cycle_date, status, started_at)
                         VALUES ('2026-08-05', 'completed', '2026-08-05 21:30:00')");
        $this->db->exec("INSERT INTO llm_free_cycle (cycle_date, status, started_at)
                         VALUES ('2026-08-07', 'started', '2026-08-07 21:30:00')");

        $latest = $this->repo->getLatestCycle();

        $this->assertNotNull($latest);
        $this->assertSame('2026-08-07', $latest['cycle_date']);
    }

    // --- getLegendHistory ---

    public function testGetLegendHistoryReturnsEmptyWhenNoCycles(): void
    {
        $this->assertSame([], $this->repo->getLegendHistory(10));
    }

    public function testGetLegendHistorySkipsCyclesWithoutLegend(): void
    {
        $this->db->exec("INSERT INTO llm_free_cycle (cycle_date, status, started_at, legend)
                         VALUES ('2026-08-05', 'failed', '2026-08-05 21:30:00', NULL)");
        $this->db->exec("INSERT INTO llm_free_cycle (cycle_date, status, started_at, legend)
                         VALUES ('2026-08-06', 'completed', '2026-08-06 21:30:00', 'Kupiłem AVGO — marże rosną.')");

        $history = $this->repo->getLegendHistory(10);

        $this->assertCount(1, $history);
        $this->assertSame('2026-08-06', $history[0]['cycle_date']);
        $this->assertSame('Kupiłem AVGO — marże rosną.', $history[0]['legend']);
    }

    public function testGetLegendHistoryReturnsNewestFirstLimitedToN(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $date = sprintf('2026-08-%02d', $i);
            $this->db->exec("INSERT INTO llm_free_cycle (cycle_date, status, started_at, legend)
                             VALUES ('$date', 'completed', '$date 21:30:00', 'Wpis $i')");
        }

        $history = $this->repo->getLegendHistory(10);

        $this->assertCount(10, $history);
        $this->assertSame('2026-08-15', $history[0]['cycle_date']);
        $this->assertSame('2026-08-06', $history[9]['cycle_date']);
    }
}
