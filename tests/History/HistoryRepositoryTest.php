<?php

declare(strict_types=1);

namespace CVS\Tests\History;

use CVS\History\HistoryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HistoryRepository using SQLite in-memory.
 *
 * Each test creates a fresh PDO connection + schema so there is
 * no state leakage between cases.
 */
class HistoryRepositoryTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeRepo(): HistoryRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQLite equivalent of the MySQL migration:
        // DECIMAL → REAL, TINYINT → INTEGER, DATETIME → TEXT.
        $pdo->exec('
            CREATE TABLE analysis_history (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                ticker        TEXT    NOT NULL,
                cvs_swing     REAL    NULL,
                cvs_fund      REAL    NULL,
                reco_swing    TEXT    NULL,
                reco_fund     TEXT    NULL,
                golden_signal TEXT    NULL,
                quality_gate  INTEGER NOT NULL DEFAULT 0,
                analysed_at   TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');

        return new HistoryRepository($pdo);
    }

    /** @return array<string, mixed> */
    private function passResult(string $ticker = 'AAPL'): array
    {
        return [
            'ticker'        => $ticker,
            'quality_gate'  => true,
            'swing'         => ['cvs' => 72.5, 'recommendation' => 'AKUMULUJ'],
            'fundamental'   => ['cvs' => 65.0, 'recommendation' => 'AKUMULUJ'],
            'golden_signal' => 'strong',
        ];
    }

    /** @return array<string, mixed> */
    private function failResult(string $ticker = 'XYZ'): array
    {
        return [
            'ticker'        => $ticker,
            'quality_gate'  => false,
            'swing'         => ['cvs' => null, 'recommendation' => null],
            'fundamental'   => ['cvs' => null, 'recommendation' => null],
            'golden_signal' => null,
        ];
    }

    // ------------------------------------------------------------------
    // save()
    // ------------------------------------------------------------------

    public function test_save_stores_gate_pass_result(): void
    {
        $repo = $this->makeRepo();
        $repo->save(1, 'AAPL', $this->passResult('AAPL'));

        $rows = $repo->findByUser(1, 20);
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('AAPL', $row['ticker']);
        $this->assertEquals(72.5, $row['cvs_swing']);
        $this->assertEquals(65.0, $row['cvs_fund']);
        $this->assertSame('AKUMULUJ', $row['reco_swing']);
        $this->assertSame('strong', $row['golden_signal']);
        $this->assertSame(1, (int) $row['quality_gate']);
    }

    public function test_save_stores_gate_fail_result(): void
    {
        $repo = $this->makeRepo();
        $repo->save(1, 'XYZ', $this->failResult('XYZ'));

        $rows = $repo->findByUser(1, 20);
        $this->assertCount(1, $rows);
        $this->assertSame('XYZ', $rows[0]['ticker']);
        $this->assertSame(0, (int) $rows[0]['quality_gate']);
    }

    public function test_save_stores_null_scores_for_fail(): void
    {
        $repo = $this->makeRepo();
        $repo->save(1, 'XYZ', $this->failResult('XYZ'));

        $row = $repo->findByUser(1, 20)[0];
        $this->assertNull($row['cvs_swing']);
        $this->assertNull($row['cvs_fund']);
        $this->assertNull($row['reco_swing']);
        $this->assertNull($row['reco_fund']);
        $this->assertNull($row['golden_signal']);
    }

    public function test_save_multiple_same_ticker_allowed(): void
    {
        $repo = $this->makeRepo();
        $repo->save(1, 'AAPL', $this->passResult('AAPL'));
        $repo->save(1, 'AAPL', $this->passResult('AAPL'));

        // No dedup — every analysis is its own row.
        $this->assertCount(2, $repo->findByUser(1, 20));
    }

    // ------------------------------------------------------------------
    // findByUser()
    // ------------------------------------------------------------------

    public function test_find_returns_empty_for_new_user(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->findByUser(42, 20));
    }

    public function test_find_returns_most_recent_first(): void
    {
        $repo = $this->makeRepo();
        $repo->save(1, 'AAPL', $this->passResult('AAPL'));
        $repo->save(1, 'MSFT', $this->passResult('MSFT'));
        $repo->save(1, 'NVDA', $this->passResult('NVDA'));

        $tickers = array_column($repo->findByUser(1, 20), 'ticker');
        $this->assertSame(['NVDA', 'MSFT', 'AAPL'], $tickers);
    }

    public function test_find_respects_limit(): void
    {
        $repo = $this->makeRepo();
        foreach (['A', 'B', 'C', 'D', 'E'] as $t) {
            $repo->save(1, $t, $this->passResult($t));
        }

        $this->assertCount(2, $repo->findByUser(1, 2));
    }

    public function test_find_is_scoped_to_user(): void
    {
        $repo = $this->makeRepo();
        $repo->save(1, 'AAPL', $this->passResult('AAPL'));
        $repo->save(2, 'MSFT', $this->passResult('MSFT'));

        $u1 = array_column($repo->findByUser(1, 20), 'ticker');
        $u2 = array_column($repo->findByUser(2, 20), 'ticker');
        $this->assertSame(['AAPL'], $u1);
        $this->assertSame(['MSFT'], $u2);
    }
}
