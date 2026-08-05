<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\CycleRepository;
use CVS\Portfolio\PortfolioService;
use PDO;
use PHPUnit\Framework\TestCase;

class PortfolioServiceTest extends TestCase
{
    private PDO             $db;
    private CycleRepository $cycleRepo;
    private PortfolioService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // SQLite does not support INSERT IGNORE — use INSERT OR IGNORE syntax instead.
        // Schema mirrors MySQL migrations without FK enforcement (SQLite is lax by default).
        $this->db->exec('
            CREATE TABLE rebalance_cycle (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date          TEXT    NOT NULL UNIQUE,
                status              TEXT    NOT NULL DEFAULT "started",
                started_at          TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at         TEXT,
                cash_before         REAL,
                cash_after          REAL,
                portfolio_value_usd REAL,
                executed_count      INTEGER NOT NULL DEFAULT 0,
                skipped_count       INTEGER NOT NULL DEFAULT 0,
                notes               TEXT
            )
        ');

        $this->db->exec('
            CREATE TABLE portfolio_state (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                cash            REAL    NOT NULL,
                initial_capital REAL    NOT NULL,
                updated_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE TABLE portfolio_holdings (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker          TEXT    NOT NULL UNIQUE,
                quantity        INTEGER NOT NULL DEFAULT 0,
                avg_entry_price REAL    NOT NULL,
                updated_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
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
                executed_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Seed portfolio state with 10 000 USD.
        $this->db->exec("INSERT INTO portfolio_state (cash, initial_capital, updated_at)
                         VALUES (10000.00, 10000.00, '2026-06-26 21:30:00')");

        $this->cycleRepo = new CycleRepository($this->db);
        $this->service   = new PortfolioService($this->db, $this->cycleRepo);
    }

    // --- Helpers ---

    private function insertCycle(): int
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-26', 'started', '2026-06-26 21:30:00')");
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function fetchState(): array
    {
        $row = $this->db->query('SELECT * FROM portfolio_state LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchHoldings(): array
    {
        return $this->db->query('SELECT * FROM portfolio_holdings ORDER BY ticker')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchTransactions(int $cycleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_transactions WHERE cycle_id = ? ORDER BY id');
        $stmt->execute([$cycleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function fetchCycle(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM rebalance_cycle WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    // --- ensureInitialized ---

    public function testEnsureInitializedPassesWithOneRow(): void
    {
        $this->service->ensureInitialized(); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testEnsureInitializedThrowsWhenEmpty(): void
    {
        $this->db->exec('DELETE FROM portfolio_state');
        $this->expectException(\RuntimeException::class);
        $this->service->ensureInitialized();
    }

    // --- BUY ---

    public function testBuyReducesCashAndCreatesHolding(): void
    {
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 150.0, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(8500.0, (float) $state['cash'], 0.01);

        $holdings = $this->fetchHoldings();
        $this->assertCount(1, $holdings);
        $this->assertSame('AAPL', $holdings[0]['ticker']);
        $this->assertSame(10, (int) $holdings[0]['quantity']);

        $txs = $this->fetchTransactions($id);
        $this->assertCount(1, $txs);
        $this->assertSame('executed', $txs[0]['status']);
        $this->assertSame('BUY', $txs[0]['action']);

        $cycle = $this->fetchCycle($id);
        $this->assertSame('completed', $cycle['status']);
        $this->assertEqualsWithDelta(10000.0, (float) $cycle['cash_before'], 0.01);
        $this->assertEqualsWithDelta(8500.0, (float) $cycle['cash_after'], 0.01);
        $this->assertSame(1, (int) $cycle['executed_count']);
    }

    public function testBuySkipsWhenInsufficientCash(): void
    {
        // Set cash to $100 — not enough for 10 shares at $150
        $this->db->exec("UPDATE portfolio_state SET cash = 100.00");

        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 150.0, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(100.0, (float) $state['cash'], 0.01); // unchanged

        $txs = $this->fetchTransactions($id);
        $this->assertCount(1, $txs);
        $this->assertSame('skipped_insufficient_cash', $txs[0]['status']);

        $cycle = $this->fetchCycle($id);
        $this->assertSame(0, (int) $cycle['executed_count']);
        $this->assertSame(1, (int) $cycle['skipped_count']);
    }

    // --- SELL ---

    public function testSellIncreasesCashAndRemovesHolding(): void
    {
        // Seed a holding
        $this->db->exec("INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0000, '2026-06-26 21:30:00')");

        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'SELL', 'quantity' => 10, 'price_usd' => 160.0, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(11600.0, (float) $state['cash'], 0.01);

        $holdings = $this->fetchHoldings();
        $this->assertCount(0, $holdings); // row deleted
    }

    public function testPartialSellLeavesRemainingHolding(): void
    {
        $this->db->exec("INSERT INTO portfolio_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0000, '2026-06-26 21:30:00')");

        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'SELL', 'quantity' => 4, 'price_usd' => 160.0, 'reason' => null,
        ]], []);

        $holdings = $this->fetchHoldings();
        $this->assertCount(1, $holdings);
        $this->assertSame(6, (int) $holdings[0]['quantity']);
    }

    // --- HOLD ---

    public function testHoldLogsTransactionWithoutChangingCash(): void
    {
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'HOLD', 'quantity' => null, 'price_usd' => null, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(10000.0, (float) $state['cash'], 0.01);

        $txs = $this->fetchTransactions($id);
        $this->assertCount(1, $txs);
        $this->assertSame('hold', $txs[0]['status']);
    }

    // --- NO_ACTION ---

    public function testNoActionLogsWithWildcardTicker(): void
    {
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => null, 'action' => 'NO_ACTION', 'quantity' => null, 'price_usd' => null, 'reason' => 'Market looks overvalued',
        ]], []);

        $txs = $this->fetchTransactions($id);
        $this->assertCount(1, $txs);
        $this->assertSame('*', $txs[0]['ticker']);
        $this->assertSame('no_action', $txs[0]['status']);
        $this->assertSame('Market looks overvalued', $txs[0]['reason']);
    }

    // --- Weighted avg_entry_price ---

    public function testTwoBuySameTickerWeightedAverage(): void
    {
        $id = $this->insertCycle();
        // First BUY: 10 shares at $100 → avg = 100
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 100.0, 'reason' => null,
        ]], []);

        // Second BUY on a different date: 10 shares at $200 → avg = (10*100 + 10*200) / 20 = 150
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-27', 'started', '2026-06-27 21:30:00')");
        $id2 = (int) $this->db->lastInsertId();

        $this->service->executeCycle($id2, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 200.0, 'reason' => null,
        ]], []);

        $holdings = $this->fetchHoldings();
        $this->assertCount(1, $holdings);
        $this->assertSame(20, (int) $holdings[0]['quantity']);
        $this->assertEqualsWithDelta(150.0, (float) $holdings[0]['avg_entry_price'], 0.001);
    }

    // --- portfolio_value_usd mark-to-market (fix: computeHoldingsValue live pricing) ---

    public function testPortfolioValueUsesLivePriceMapNotCostBasis(): void
    {
        // Bought at 100, but the price map says today's snapshot price is 150 —
        // portfolio_value_usd must reflect the mark-to-market 150, not the 100 cost basis.
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 100.0, 'reason' => null,
        ]], ['AAPL' => 150.0]);

        $cycle = $this->fetchCycle($id);
        // cash_after (10000 - 1000 = 9000) + 10 * 150.0 (live) = 10500.0
        $this->assertEqualsWithDelta(10500.0, (float) $cycle['portfolio_value_usd'], 0.01);
    }

    public function testPortfolioValueFallsBackToAvgEntryPriceWhenTickerMissingFromPriceMap(): void
    {
        // AAPL is bought but the price map (e.g. it fell out of the watchlist that day)
        // has no entry for it — must fall back to avg_entry_price, not silently drop it.
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 100.0, 'reason' => null,
        ]], ['MSFT' => 300.0]); // price map covers a different ticker only

        $cycle = $this->fetchCycle($id);
        // cash_after (9000) + 10 * 100.0 (avg_entry_price fallback) = 10000.0
        $this->assertEqualsWithDelta(10000.0, (float) $cycle['portfolio_value_usd'], 0.01);
    }

    // --- ROLLBACK on exception ---

    public function testExceptionMidCycleRollsBackAllChanges(): void
    {
        $id = $this->insertCycle();

        // Drop portfolio_transactions to force a PDO error after portfolio_state has already
        // been mutated (BUY path: UPDATE state → INSERT/UPDATE holdings → INSERT transactions).
        // executeCycle() must catch the error, call rollBack(), and restore state.
        $this->db->exec('DROP TABLE portfolio_transactions');

        try {
            $this->service->executeCycle($id, [[
                'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 150.0, 'reason' => null,
            ]], []);
            $this->fail('Expected PDOException was not thrown');
        } catch (\PDOException $e) {
            // Expected — table no longer exists
        }

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(10000.0, (float) $state['cash'], 0.01, 'Cash must be unchanged after rollback');
    }
}
