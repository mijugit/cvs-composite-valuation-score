<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\TrackRecord\CvsSnapshotRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CvsSnapshotRepository using SQLite in-memory.
 */
class CvsSnapshotRepositoryTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeRepo(): CvsSnapshotRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker             TEXT    NOT NULL,
                sector             TEXT    NULL,
                score_date         TEXT    NOT NULL,
                scored_at          TEXT    NOT NULL,
                price_at_snapshot  REAL    NULL,
                cvs_swing          REAL    NULL,
                cvs_fund           REAL    NULL,
                reco_swing         TEXT    NULL,
                reco_fund          TEXT    NULL,
                golden_signal      TEXT    NULL,
                quality_gate       INTEGER NOT NULL DEFAULT 0,
                gate_failures      TEXT    NULL,
                pillar_scores      TEXT    NULL,
                UNIQUE (ticker, score_date)
            )
        ');

        return new CvsSnapshotRepository($pdo);
    }

    /** @return array<string, mixed> */
    private function passResult(string $ticker = 'AAPL'): array
    {
        return [
            'ticker'        => $ticker,
            'quality_gate'  => true,
            'swing'         => ['cvs' => 74.0, 'recommendation' => 'SILNE KUPUJ'],
            'fundamental'   => ['cvs' => 68.0, 'recommendation' => 'KUPUJ'],
            'golden_signal' => 'strong',
            'gate_failures' => [],
            'pillar_scores' => ['valuation' => 70.0, 'momentum_swing' => 80.0, 'quality' => 60.0],
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
            'gate_failures' => ['Brak danych FCF'],
            'pillar_scores' => null,
        ];
    }

    // ------------------------------------------------------------------
    // save()
    // ------------------------------------------------------------------

    public function test_save_inserts_pass_result(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'));

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('AAPL', $row['ticker']);
        $this->assertEquals(74.0, $row['cvs_swing']);
        $this->assertEquals(68.0, $row['cvs_fund']);
        $this->assertSame('SILNE KUPUJ', $row['reco_swing']);
        $this->assertSame('strong', $row['golden_signal']);
        $this->assertSame(1, (int) $row['quality_gate']);
    }

    public function test_save_inserts_fail_result(): void
    {
        $repo = $this->makeRepo();
        $repo->save('XYZ', $this->failResult('XYZ'));

        $row = $repo->findLatestByTicker('XYZ');
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['quality_gate']);
        $this->assertNull($row['cvs_swing']);
        $this->assertNull($row['golden_signal']);
    }

    public function test_save_stores_sector(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'), 185.50, 'Technology');

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('Technology', $row['sector']);
    }

    public function test_save_null_sector_ok(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL')); // no sector

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertNull($row['sector']);
    }

    public function test_save_stores_price_at_snapshot(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'), 185.50);

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertEquals(185.50, (float) $row['price_at_snapshot']);
    }

    public function test_save_null_price_ok_backward_compat(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL')); // no price — null by default

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertNull($row['price_at_snapshot']);
    }

    public function test_save_is_idempotent_same_day(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'));

        // Second save with updated score — should overwrite, not duplicate.
        $updated           = $this->passResult('AAPL');
        $updated['swing']['cvs'] = 80.0;
        $repo->save('AAPL', $updated);

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertEquals(80.0, $row['cvs_swing']);
    }

    // ------------------------------------------------------------------
    // findLatestByTicker()
    // ------------------------------------------------------------------

    public function test_find_latest_returns_null_for_unknown_ticker(): void
    {
        $repo = $this->makeRepo();
        $this->assertNull($repo->findLatestByTicker('AAPL'));
    }

    // ------------------------------------------------------------------
    // findAllLatest()
    // ------------------------------------------------------------------

    public function test_find_all_latest_returns_empty_when_no_snapshots(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->findAllLatest());
    }

    public function test_find_all_latest_returns_one_row_per_ticker(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'));
        $repo->save('MSFT', $this->passResult('MSFT'));

        $rows = $repo->findAllLatest();
        $this->assertCount(2, $rows);
        $tickers = array_column($rows, 'ticker');
        $this->assertContains('AAPL', $tickers);
        $this->assertContains('MSFT', $tickers);
    }

    // ------------------------------------------------------------------
    // findByTickerSince()
    // ------------------------------------------------------------------

    public function test_find_by_ticker_since_returns_empty_when_no_history(): void
    {
        $repo = $this->makeRepo();
        $result = $repo->findByTickerSince('AAPL', new DateTimeImmutable('2020-01-01'));
        $this->assertSame([], $result);
    }

    public function test_find_by_ticker_since_filters_by_date(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'));

        // Today's snapshot should appear for a "since" date of yesterday.
        $yesterday = new DateTimeImmutable('yesterday');
        $rows = $repo->findByTickerSince('AAPL', $yesterday);
        $this->assertCount(1, $rows);
        $this->assertSame('AAPL', $rows[0]['ticker']);
    }

    public function test_find_by_ticker_since_excludes_future_date(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'));

        $tomorrow = new DateTimeImmutable('tomorrow');
        $rows = $repo->findByTickerSince('AAPL', $tomorrow);
        $this->assertSame([], $rows);
    }
}
