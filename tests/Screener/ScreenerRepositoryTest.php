<?php

declare(strict_types=1);

namespace CVS\Tests\Screener;

use CVS\Screener\ScreenerRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ScreenerRepositoryTest extends TestCase
{
    private function makeRepo(): ScreenerRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL, sector TEXT NULL,
                model_version TEXT NULL,
                origin TEXT NOT NULL DEFAULT \'rescore\',
                score_date TEXT NOT NULL, scored_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                price_at_snapshot REAL NULL,
                cvs_swing REAL NULL, cvs_fund REAL NULL,
                reco_swing TEXT NULL, reco_fund TEXT NULL,
                golden_signal TEXT NULL, quality_gate INTEGER NOT NULL DEFAULT 0,
                gate_failures TEXT NULL, pillar_scores TEXT NULL,
                UNIQUE (ticker, score_date, model_version, origin)
            )
        ');
        return new ScreenerRepository($pdo);
    }

    private function insertSnapshot(PDO $pdo, string $ticker, float $swing, float $fund,
                                    string $reco, ?string $signal, ?string $sector = null,
                                    int $gate = 1, string $origin = 'rescore'): void
    {
        $pdo->prepare('
            INSERT INTO cvs_snapshots (ticker, sector, score_date, cvs_swing, cvs_fund,
                reco_swing, golden_signal, quality_gate, origin)
            VALUES (?, ?, date(\'now\'), ?, ?, ?, ?, ?, ?)
        ')->execute([$ticker, $sector, $swing, $fund, $reco, $signal, $gate, $origin]);
    }

    // ------------------------------------------------------------------

    public function test_get_filtered_empty(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->getFiltered());
    }

    public function test_get_filtered_excludes_gate_fails(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);
        $this->insertSnapshot($db, 'FAIL', 80.0, 70.0, 'SILNE KUPUJ', null, null, 0);

        $this->assertSame([], $repo->getFiltered());
    }

    public function test_get_filtered_by_reco(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);
        $this->insertSnapshot($db, 'AAPL', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'MSFT', 50.0, 45.0, '→ NEUTRALNIE', null);

        $result = $repo->getFiltered('⬆⬆ SILNE KUPUJ');
        $this->assertCount(1, $result);
        $this->assertSame('AAPL', $result[0]['ticker']);
    }

    public function test_get_filtered_by_min_swing(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);
        $this->insertSnapshot($db, 'HIGH', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'LOW',  40.0, 35.0, '→ NEUTRALNIE', null);

        $result = $repo->getFiltered(null, null, 70);
        $this->assertCount(1, $result);
        $this->assertSame('HIGH', $result[0]['ticker']);
    }

    public function test_get_filtered_by_signal(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);
        $this->insertSnapshot($db, 'GOLD', 75.0, 65.0, '⬆⬆ SILNE KUPUJ', 'strong');
        $this->insertSnapshot($db, 'NONE', 55.0, 50.0, '⬆ AKUMULUJ', null);

        $result = $repo->getFiltered(null, 'strong');
        $this->assertCount(1, $result);
        $this->assertSame('GOLD', $result[0]['ticker']);
    }

    public function test_sort_by_fund(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);
        $this->insertSnapshot($db, 'A', 80.0, 60.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'B', 70.0, 90.0, '⬆⬆ SILNE KUPUJ', null);

        $result = $repo->getFiltered(null, null, 0, null, 'fund');
        $this->assertSame('B', $result[0]['ticker']); // B has higher fund
    }

    // ------------------------------------------------------------------
    // Phase 7 (slice 1, FR-003): corpus isolation — calibration rows must
    // never surface in the screener (listing, sector dropdown, freshness)
    // ------------------------------------------------------------------

    public function test_get_filtered_excludes_corpus_rows(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);

        $this->insertSnapshot($db, 'AAPL', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        // Full-universe corpus ticker no user ever watched — must not leak.
        $this->insertSnapshot($db, 'CORP', 95.0, 90.0, '⬆⬆ SILNE KUPUJ', 'strong', 'Energy', 1, 'corpus');

        $result  = $repo->getFiltered();
        $tickers = array_column($result, 'ticker');
        $this->assertSame(['AAPL'], $tickers, 'corpus rows must never surface in the screener listing');
    }

    public function test_get_filtered_excludes_corpus_twin_of_watchlist_ticker(): void
    {
        // A watchlist ticker that also sits in the crawled sector has BOTH rows
        // for the same day — the listing must show exactly one (the rescore row).
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);

        $this->insertSnapshot($db, 'AAPL', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'AAPL', 79.5, 69.0, '⬆⬆ SILNE KUPUJ', null, null, 1, 'corpus');

        $result = $repo->getFiltered();
        $this->assertCount(1, $result, 'corpus twin must not double the listing (2026-06-08 bug class)');
        $this->assertEquals(80.0, (float) $result[0]['cvs_swing'], 'the surviving row must be the rescore one');
    }

    public function test_get_distinct_sectors_excludes_corpus_sectors(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);

        $this->insertSnapshot($db, 'AAPL', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null, 'Technology');
        $this->insertSnapshot($db, 'XOM',  60.0, 55.0, '⬆ AKUMULUJ', null, 'Energy', 1, 'corpus');

        $this->assertSame(['Technology'], $repo->getDistinctSectors(), 'corpus-only sectors must not flood the dropdown');
    }
}
