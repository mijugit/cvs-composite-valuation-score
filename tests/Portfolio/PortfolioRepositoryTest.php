<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\PortfolioRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Uses SQLite in-memory to avoid MySQL dependency in CI / local dev.
 * Schema mirrors the MySQL migrations with SQLite-compatible syntax.
 */
class PortfolioRepositoryTest extends TestCase
{
    private PDO $db;
    private PortfolioRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE rebalance_cycle (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date    TEXT    NOT NULL,
                status        TEXT    NOT NULL DEFAULT "started",
                started_at    TEXT    NOT NULL,
                finished_at   TEXT,
                cash_before   REAL,
                cash_after    REAL,
                portfolio_value_usd REAL,
                executed_count INTEGER NOT NULL DEFAULT 0,
                skipped_count  INTEGER NOT NULL DEFAULT 0,
                notes          TEXT
            )
        ');

        $this->db->exec('
            CREATE TABLE portfolio_state (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                cash            REAL    NOT NULL,
                initial_capital REAL    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE portfolio_holdings (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker          TEXT    NOT NULL UNIQUE,
                quantity        INTEGER NOT NULL DEFAULT 0,
                avg_entry_price REAL    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE portfolio_transactions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_id    INTEGER NOT NULL,
                ticker      TEXT    NOT NULL,
                action      TEXT    NOT NULL,
                quantity    INTEGER,
                price_usd   REAL,
                cash_before REAL,
                cash_after  REAL,
                status      TEXT    NOT NULL,
                reason      TEXT,
                executed_at TEXT    NOT NULL
            )
        ');

        $this->repo = new PortfolioRepository($this->db);
    }

    // --- getCurrentState ---

    public function testGetCurrentStateReturnsSeededRow(): void
    {
        $this->db->exec("INSERT INTO portfolio_state (cash, initial_capital, updated_at)
                         VALUES (10000.00, 10000.00, '2026-06-26 12:00:00')");

        $state = $this->repo->getCurrentState();

        $this->assertEqualsWithDelta(10000.0, (float)$state['cash'], 0.001);
        $this->assertEqualsWithDelta(10000.0, (float)$state['initial_capital'], 0.001);
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
        $this->db->exec("INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0000, '2026-06-26 12:00:00')");
        $this->db->exec("INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('MSFT', 0, 300.0000, '2026-06-26 12:00:00')");

        $holdings = $this->repo->getCurrentHoldings();

        $this->assertCount(1, $holdings);
        $this->assertSame('AAPL', $holdings[0]['ticker']);
    }

    public function testGetCurrentHoldingsOrderedByTicker(): void
    {
        $this->db->exec("INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('NVDA', 5, 800.0, '2026-06-26 12:00:00')");
        $this->db->exec("INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0, '2026-06-26 12:00:00')");

        $holdings = $this->repo->getCurrentHoldings();

        $this->assertSame('AAPL', $holdings[0]['ticker']);
        $this->assertSame('NVDA', $holdings[1]['ticker']);
    }

    // --- getTransactionsByCycle ---

    public function testGetTransactionsByCycleReturnsRowsInOrder(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-26', 'completed', '2026-06-26 21:30:00')");
        $cycleId = (int) $this->db->lastInsertId();

        $this->db->exec("INSERT INTO portfolio_transactions
                         (cycle_id, ticker, action, status, executed_at)
                         VALUES ($cycleId, 'AAPL', 'BUY', 'executed', '2026-06-26 21:31:00')");
        $this->db->exec("INSERT INTO portfolio_transactions
                         (cycle_id, ticker, action, status, executed_at)
                         VALUES ($cycleId, 'MSFT', 'HOLD', 'hold', '2026-06-26 21:31:01')");

        $txs = $this->repo->getTransactionsByCycle($cycleId);

        $this->assertCount(2, $txs);
        $this->assertSame('AAPL', $txs[0]['ticker']);
        $this->assertSame('MSFT', $txs[1]['ticker']);
    }

    // --- getCycleHistory ---

    public function testGetCycleHistoryReturnsNewestFirst(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-24', 'completed', '2026-06-24 21:30:00')");
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-25', 'completed', '2026-06-25 21:30:00')");
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-26', 'started', '2026-06-26 21:30:00')");

        $history = $this->repo->getCycleHistory();

        $this->assertCount(3, $history);
        $this->assertSame('2026-06-26', $history[0]['cycle_date']);
        $this->assertSame('2026-06-24', $history[2]['cycle_date']);
    }

    public function testGetCycleHistoryHasNoLimit(): void
    {
        // Insert 25 rows — if there were a LIMIT 10, count would be 10
        for ($i = 1; $i <= 25; $i++) {
            $date = sprintf('2026-%02d-01', $i <= 12 ? $i : $i - 12);
            $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                             VALUES ('$date', 'completed', '2026-06-01 21:30:00')");
        }

        $history = $this->repo->getCycleHistory();
        $this->assertCount(25, $history);
    }

    // --- getLatestCycle ---

    public function testGetLatestCycleReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->repo->getLatestCycle());
    }

    public function testGetLatestCycleReturnsMostRecent(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-24', 'completed', '2026-06-24 21:30:00')");
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-26', 'started', '2026-06-26 21:30:00')");

        $latest = $this->repo->getLatestCycle();

        $this->assertNotNull($latest);
        $this->assertSame('2026-06-26', $latest['cycle_date']);
    }

    // --- getCycleById ---

    public function testGetCycleByIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->getCycleById(999));
    }

    public function testGetCycleByIdReturnsCorrectRow(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-26', 'completed', '2026-06-26 21:30:00')");
        $id = (int) $this->db->lastInsertId();

        $row = $this->repo->getCycleById($id);

        $this->assertNotNull($row);
        $this->assertSame('2026-06-26', $row['cycle_date']);
        $this->assertSame('completed', $row['status']);
    }
}
