<?php

declare(strict_types=1);

namespace CVS\Tests\LlmGptLuna;

use CVS\LlmGptLuna\LlmGptLunaCycleRepository;
use CVS\LlmGptLuna\LlmGptLunaService;
use PDO;
use PHPUnit\Framework\TestCase;

class LlmGptLunaServiceTest extends TestCase
{
    private PDO                       $db;
    private LlmGptLunaCycleRepository $cycleRepo;
    private LlmGptLunaService         $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE llm_gpt_luna_cycle (
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
            CREATE TABLE llm_gpt_luna_state (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                cash            REAL    NOT NULL,
                initial_capital REAL    NOT NULL,
                updated_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE TABLE llm_gpt_luna_holdings (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker          TEXT    NOT NULL UNIQUE,
                quantity        INTEGER NOT NULL DEFAULT 0,
                avg_entry_price REAL    NOT NULL,
                updated_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE TABLE llm_gpt_luna_transactions (
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

        // Seed wallet state with 10 000 USD — same starting capital as the other three wallets.
        $this->db->exec("INSERT INTO llm_gpt_luna_state (cash, initial_capital, updated_at)
                         VALUES (10000.00, 10000.00, '2026-08-19 21:40:00')");

        $this->cycleRepo = new LlmGptLunaCycleRepository($this->db);
        $this->service   = new LlmGptLunaService($this->db, $this->cycleRepo);
    }

    // --- Helpers ---

    private function insertCycle(): int
    {
        $this->db->exec("INSERT INTO llm_gpt_luna_cycle (cycle_date, status, started_at)
                         VALUES ('2026-08-19', 'started', '2026-08-19 21:40:00')");
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function fetchState(): array
    {
        $row = $this->db->query('SELECT * FROM llm_gpt_luna_state LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchHoldings(): array
    {
        return $this->db->query('SELECT * FROM llm_gpt_luna_holdings ORDER BY ticker')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchTransactions(int $cycleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM llm_gpt_luna_transactions WHERE cycle_id = ? ORDER BY id');
        $stmt->execute([$cycleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function fetchCycle(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM llm_gpt_luna_cycle WHERE id = ?');
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
        $this->db->exec('DELETE FROM llm_gpt_luna_state');
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
        $this->db->exec("UPDATE llm_gpt_luna_state SET cash = 100.00");

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

    /**
     * Proves no enforcement layer exists: a BUY far beyond any baseline-wallet
     * cap (max_weight_pct 15%, max_sector_pct 40%) executes in full here — the
     * only cap is available cash.
     */
    public function testBuyFarBeyondBaselineWalletCapsExecutesInFull(): void
    {
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 60, 'price_usd' => 100.0, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(4000.0, (float) $state['cash'], 0.01);

        $holdings = $this->fetchHoldings();
        $this->assertCount(1, $holdings);
        $this->assertSame(60, (int) $holdings[0]['quantity']); // full requested quantity, no trim

        $txs = $this->fetchTransactions($id);
        $this->assertSame('executed', $txs[0]['status']);
        $this->assertSame(60, (int) $txs[0]['quantity']);
    }

    // --- SELL ---

    public function testSellIncreasesCashAndRemovesHolding(): void
    {
        $this->db->exec("INSERT INTO llm_gpt_luna_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0000, '2026-08-19 21:40:00')");

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
        $this->db->exec("INSERT INTO llm_gpt_luna_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 10, 150.0000, '2026-08-19 21:40:00')");

        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'SELL', 'quantity' => 4, 'price_usd' => 160.0, 'reason' => null,
        ]], []);

        $holdings = $this->fetchHoldings();
        $this->assertCount(1, $holdings);
        $this->assertSame(6, (int) $holdings[0]['quantity']);
    }

    public function testSellSkipsWhenNothingHeld(): void
    {
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'SELL', 'quantity' => 10, 'price_usd' => 160.0, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        $this->assertEqualsWithDelta(10000.0, (float) $state['cash'], 0.01); // unchanged

        $txs = $this->fetchTransactions($id);
        $this->assertCount(1, $txs);
        $this->assertSame('skipped_insufficient_quantity', $txs[0]['status']);

        $cycle = $this->fetchCycle($id);
        $this->assertSame(0, (int) $cycle['executed_count']);
        $this->assertSame(1, (int) $cycle['skipped_count']);
    }

    public function testSellCapsAtQuantityActuallyHeld(): void
    {
        $this->db->exec("INSERT INTO llm_gpt_luna_holdings (ticker, quantity, avg_entry_price, updated_at)
                         VALUES ('AAPL', 5, 150.0000, '2026-08-19 21:40:00')");

        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'SELL', 'quantity' => 20, 'price_usd' => 160.0, 'reason' => null,
        ]], []);

        $state = $this->fetchState();
        // 10000 + 5 * 160 = 10800 (proceeds capped at 5 shares, not 20)
        $this->assertEqualsWithDelta(10800.0, (float) $state['cash'], 0.01);

        $holdings = $this->fetchHoldings();
        $this->assertCount(0, $holdings); // fully sold out

        $txs = $this->fetchTransactions($id);
        $this->assertSame('executed', $txs[0]['status']);
        $this->assertSame(5, (int) $txs[0]['quantity']);

        $cycle = $this->fetchCycle($id);
        $this->assertSame(1, (int) $cycle['executed_count']);
        $this->assertSame(0, (int) $cycle['skipped_count']);
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
            'ticker' => null, 'action' => 'NO_ACTION', 'quantity' => null, 'price_usd' => null, 'reason' => 'Rynek wygląda przewartościowany',
        ]], []);

        $txs = $this->fetchTransactions($id);
        $this->assertCount(1, $txs);
        $this->assertSame('*', $txs[0]['ticker']);
        $this->assertSame('no_action', $txs[0]['status']);
        $this->assertSame('Rynek wygląda przewartościowany', $txs[0]['reason']);
    }

    // --- Weighted avg_entry_price ---

    public function testTwoBuySameTickerWeightedAverage(): void
    {
        $id = $this->insertCycle();
        $this->service->executeCycle($id, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 100.0, 'reason' => null,
        ]], []);

        $this->db->exec("INSERT INTO llm_gpt_luna_cycle (cycle_date, status, started_at)
                         VALUES ('2026-08-20', 'started', '2026-08-20 21:40:00')");
        $id2 = (int) $this->db->lastInsertId();

        $this->service->executeCycle($id2, [[
            'ticker' => 'AAPL', 'action' => 'BUY', 'quantity' => 10, 'price_usd' => 200.0, 'reason' => null,
        ]], []);

        $holdings = $this->fetchHoldings();
        $this->assertCount(1, $holdings);
        $this->assertSame(20, (int) $holdings[0]['quantity']);
        $this->assertEqualsWithDelta(150.0, (float) $holdings[0]['avg_entry_price'], 0.001);
    }

    // --- portfolio_value_usd mark-to-market ---

    public function testPortfolioValueUsesLivePriceMapNotCostBasis(): void
    {
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

        $this->db->exec('DROP TABLE llm_gpt_luna_transactions');

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
