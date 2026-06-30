<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\PortfolioRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PortfolioHistoryRepositoryTest extends TestCase
{
    private PDO                 $db;
    private PortfolioRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE rebalance_cycle (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date           TEXT    NOT NULL,
                status               TEXT    NOT NULL DEFAULT "started",
                attempt_count        INTEGER NOT NULL DEFAULT 1,
                executed_count       INTEGER NOT NULL DEFAULT 0,
                skipped_count        INTEGER NOT NULL DEFAULT 0,
                portfolio_value_usd  REAL,
                notes                TEXT,
                llm_failure_kind     TEXT,
                started_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at          TEXT
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
                status      TEXT    NOT NULL DEFAULT "executed",
                reason      TEXT,
                executed_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->repo = new PortfolioRepository($this->db);
    }

    private function insertCycle(string $date, string $status, float $value = 10000.0): int
    {
        $this->db->prepare(
            "INSERT INTO rebalance_cycle (cycle_date, status, portfolio_value_usd) VALUES (?, ?, ?)"
        )->execute([$date, $status, $value]);

        return (int) $this->db->lastInsertId();
    }

    private function insertTransaction(int $cycleId, string $ticker, string $action, ?string $reason = null): void
    {
        $this->db->prepare(
            "INSERT INTO portfolio_transactions (cycle_id, ticker, action, reason) VALUES (?, ?, ?, ?)"
        )->execute([$cycleId, $ticker, $action, $reason]);
    }

    // ── getCompletedCyclesPage ───────────────────────────────────────────────

    public function testCompletedPageReturnsOnlyCompletedRows(): void
    {
        $this->insertCycle('2026-06-01', 'completed');
        $this->insertCycle('2026-06-02', 'failed');
        $this->insertCycle('2026-06-03', 'completed');

        $rows = $this->repo->getCompletedCyclesPage(10);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('completed', $row['status']);
        }
    }

    public function testCompletedPageIsNewestFirst(): void
    {
        $this->insertCycle('2026-06-01', 'completed');
        $this->insertCycle('2026-06-03', 'completed');
        $this->insertCycle('2026-06-02', 'completed');

        $rows = $this->repo->getCompletedCyclesPage(10);

        $this->assertSame('2026-06-03', $rows[0]['cycle_date']);
        $this->assertSame('2026-06-02', $rows[1]['cycle_date']);
        $this->assertSame('2026-06-01', $rows[2]['cycle_date']);
    }

    public function testCompletedPageLimitAndOffset(): void
    {
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04'] as $d) {
            $this->insertCycle($d, 'completed');
        }

        $page1 = $this->repo->getCompletedCyclesPage(2, 0);
        $page2 = $this->repo->getCompletedCyclesPage(2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame('2026-06-04', $page1[0]['cycle_date']);
        $this->assertSame('2026-06-03', $page1[1]['cycle_date']);
        $this->assertSame('2026-06-02', $page2[0]['cycle_date']);
        $this->assertSame('2026-06-01', $page2[1]['cycle_date']);
    }

    // ── countCompletedCycles ────────────────────────────────────────────────

    public function testCountCompletedExcludesNonCompleted(): void
    {
        $this->insertCycle('2026-06-01', 'completed');
        $this->insertCycle('2026-06-02', 'completed');
        $this->insertCycle('2026-06-03', 'failed');
        $this->insertCycle('2026-06-04', 'llm_failed');

        $this->assertSame(2, $this->repo->countCompletedCycles());
    }

    public function testCountCompletedReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->repo->countCompletedCycles());
    }

    // ── getOperationalCycles ────────────────────────────────────────────────

    public function testOperationalCyclesExcludesCompleted(): void
    {
        $this->insertCycle('2026-06-01', 'completed');
        $this->insertCycle('2026-06-02', 'failed');
        $this->insertCycle('2026-06-03', 'llm_failed');
        $this->insertCycle('2026-06-04', 'started');

        $rows = $this->repo->getOperationalCycles();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertNotSame('completed', $row['status']);
        }
    }

    public function testOperationalCyclesAreNewestFirst(): void
    {
        $this->insertCycle('2026-06-01', 'failed');
        $this->insertCycle('2026-06-03', 'llm_failed');
        $this->insertCycle('2026-06-02', 'failed');

        $rows = $this->repo->getOperationalCycles();

        $this->assertSame('2026-06-03', $rows[0]['cycle_date']);
        $this->assertSame('2026-06-02', $rows[1]['cycle_date']);
        $this->assertSame('2026-06-01', $rows[2]['cycle_date']);
    }

    // ── getTransactionsForCycles ────────────────────────────────────────────

    public function testTransactionsBatchedAndGroupedByCycleId(): void
    {
        $c1 = $this->insertCycle('2026-06-01', 'completed');
        $c2 = $this->insertCycle('2026-06-02', 'completed');
        $this->insertTransaction($c1, 'AAPL', 'BUY', 'strong signal');
        $this->insertTransaction($c1, 'MSFT', 'HOLD', 'still in range');
        $this->insertTransaction($c2, 'NVDA', 'BUY', 'emerging');

        $result = $this->repo->getTransactionsForCycles([$c1, $c2]);

        $this->assertArrayHasKey($c1, $result);
        $this->assertArrayHasKey($c2, $result);
        $this->assertCount(2, $result[$c1]);
        $this->assertCount(1, $result[$c2]);
        $this->assertSame('AAPL', $result[$c1][0]['ticker']);
        $this->assertSame('MSFT', $result[$c1][1]['ticker']);
        $this->assertSame('NVDA', $result[$c2][0]['ticker']);
    }

    public function testTransactionsPreserveInsertionOrder(): void
    {
        $c = $this->insertCycle('2026-06-01', 'completed');
        foreach (['TSLA', 'AMZN', 'GOOG'] as $ticker) {
            $this->insertTransaction($c, $ticker, 'BUY');
        }

        $result = $this->repo->getTransactionsForCycles([$c]);

        $tickers = array_column($result[$c], 'ticker');
        $this->assertSame(['TSLA', 'AMZN', 'GOOG'], $tickers);
    }

    public function testEmptyCycleIdsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->repo->getTransactionsForCycles([]));
    }
}
