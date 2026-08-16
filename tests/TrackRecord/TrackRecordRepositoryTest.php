<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\TrackRecord\TrackRecordRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Self-join isolation tests for TrackRecordRepository (Phase 7 slice 1, FR-003).
 *
 * Corpus rows share live model_version values, so the version filter alone does
 * not keep them out of the evaluation pairs — these tests prove the origin
 * filter does. SQLite in-memory, post-migration-016 schema.
 */
class TrackRecordRepositoryTest extends TestCase
{
    /** @return array{0: TrackRecordRepository, 1: PDO} */
    private function makeRepo(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL,
                company_name TEXT NULL,
                sector TEXT NULL,
                model_version TEXT NULL,
                origin TEXT NOT NULL DEFAULT \'rescore\',
                score_date TEXT NOT NULL,
                scored_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                price_at_snapshot REAL NULL,
                cvs_swing REAL NULL, cvs_fund REAL NULL,
                reco_swing TEXT NULL, reco_fund TEXT NULL,
                golden_signal TEXT NULL,
                quality_gate INTEGER NOT NULL DEFAULT 0,
                signals TEXT NULL,
                fx_rate_to_usd REAL NULL, native_currency TEXT NULL, native_price REAL NULL,
                fair_value_price REAL NULL,
                valuation_source  TEXT NULL,
                valuation_bucket  TEXT NULL,
                valuation_variant TEXT NULL,
                UNIQUE (ticker, score_date, model_version, origin)
            )
        ');

        return [new TrackRecordRepository($pdo), $pdo];
    }

    private function insert(
        PDO     $pdo,
        string  $ticker,
        string  $scoreDate,
        ?float  $price,
        string  $origin = 'rescore',
        ?string $version = '3.0',
        float   $swing = 70.0
    ): void {
        $pdo->prepare('
            INSERT INTO cvs_snapshots
                (ticker, model_version, origin, score_date, price_at_snapshot,
                 cvs_swing, cvs_fund, reco_swing, quality_gate)
            VALUES (?, ?, ?, ?, ?, ?, 65.0, \'⬆ AKUMULUJ\', 1)
        ')->execute([$ticker, $version, $origin, $scoreDate, $price, $swing]);
    }

    private function daysAgo(int $days): string
    {
        return (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
    }

    public function test_evaluations_pair_rescore_rows(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  110.0);

        $pairs = $repo->getEvaluations(30);
        $this->assertCount(1, $pairs);
        $this->assertSame('AAPL', $pairs[0]['ticker']);
        $this->assertEquals(10.0, (float) $pairs[0]['price_change_pct']);
    }

    public function test_corpus_only_ticker_creates_no_pairs(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        // Full-universe corpus ticker, both legs present — would form a pair
        // without the origin filter.
        $this->insert($pdo, 'CORP', $this->daysAgo(35), 50.0, 'corpus');
        $this->insert($pdo, 'CORP', $this->daysAgo(0),  60.0, 'corpus');

        $this->assertSame([], $repo->getEvaluations(30), 'corpus rows must not create evaluation pairs');
        $this->assertSame([], $repo->getForTicker('CORP', 30), 'per-ticker view must be empty for corpus-only tickers');
    }

    public function test_corpus_twin_does_not_poison_price_now(): void
    {
        // The "latest" leg uses MAX(price_at_snapshot) — an unfiltered corpus twin
        // with a higher price would silently inflate price_now and the hit-rate.
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  110.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  999.0, 'corpus');

        $pairs = $repo->getEvaluations(30);
        $this->assertCount(1, $pairs);
        $this->assertEquals(110.0, (float) $pairs[0]['price_now'], 'price_now must come from the rescore row, not the corpus twin');
    }

    public function test_corpus_old_leg_does_not_create_extra_pairs(): void
    {
        // Corpus row on the OLD side of the join: a corpus snapshot older than the
        // horizon must not pair with the rescore "now" row.
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(40), 95.0, 'corpus');
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  110.0);

        $pairs = $repo->getEvaluations(30);
        $this->assertCount(1, $pairs, 'only the rescore old-leg may pair; the corpus old-leg must be ignored');
        $this->assertEquals(100.0, (float) $pairs[0]['price_then']);
    }

    public function test_get_all_for_ticker_excludes_corpus_rows(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(7), 100.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0), 110.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(3), 105.0, 'corpus');

        $rows = $repo->getAllForTicker('AAPL');
        $this->assertCount(2, $rows, 'weekly corpus points must not pollute the daily history chart');
    }

    public function test_version_filter_still_works_alongside_origin_filter(): void
    {
        // Guards the positional-parameter ordering: the interpolated origin guard
        // must not shift the dynamic version-filter placeholders.
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0, 'rescore', '3.0');
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  110.0, 'rescore', '3.0');
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0, 'rescore', '3.1', 62.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  108.0, 'rescore', '3.1', 60.0);

        $pairs30 = $repo->getEvaluations(30, '3.0');
        $this->assertCount(1, $pairs30);
        $this->assertSame('3.0', $pairs30[0]['model_version']);

        $pairsTicker = $repo->getForTicker('AAPL', 30, '3.1');
        $this->assertCount(1, $pairsTicker);
        $this->assertSame('3.1', $pairsTicker[0]['model_version']);
    }

    // ------------------------------------------------------------------
    // 'now' = latest snapshot (not MAX price in window)
    // ------------------------------------------------------------------

    public function test_price_now_uses_latest_not_max(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0); // old leg
        $this->insert($pdo, 'AAPL', $this->daysAgo(5),  130.0); // higher, but NOT latest
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  110.0); // latest

        $pairs = $repo->getEvaluations(30);
        $this->assertCount(1, $pairs);
        $this->assertEquals(110.0, (float) $pairs[0]['price_now'], 'price_now must be the latest snapshot, not the max in the window');
        $this->assertEquals(10.0, (float) $pairs[0]['price_change_pct']);
    }

    public function test_get_for_ticker_price_now_uses_latest_not_max(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(35), 100.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(5),  130.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  110.0);

        $pairs = $repo->getForTicker('AAPL', 30);
        $this->assertNotEmpty($pairs);
        $this->assertEquals(110.0, (float) $pairs[0]['price_now']);
    }

    public function test_short_horizon_pairs_when_old_leg_recent(): void
    {
        // 15-day horizon: an 18-day-old snapshot becomes a valid 'old' leg.
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(18), 100.0);
        $this->insert($pdo, 'AAPL', $this->daysAgo(0),  112.0);

        $this->assertSame([], $repo->getEvaluations(30), '30d horizon: 18-day-old leg is too recent');
        $pairs15 = $repo->getEvaluations(15);
        $this->assertCount(1, $pairs15, '15d horizon: 18-day-old leg qualifies');
        $this->assertEquals(12.0, (float) $pairs15[0]['price_change_pct']);
    }

    public function test_get_earliest_live_snapshot_date(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, 'AAPL', $this->daysAgo(20), 100.0, 'rescore', '4.0');
        $this->insert($pdo, 'AAPL', $this->daysAgo(10), 105.0, 'rescore', '4.0');
        $this->insert($pdo, 'OLD',  $this->daysAgo(40), 50.0,  'rescore', '3.0'); // older version — excluded
        $this->insert($pdo, 'CORP', $this->daysAgo(50), 10.0,  'corpus',  '4.0'); // corpus — excluded

        $this->assertSame($this->daysAgo(20), $repo->getEarliestLiveSnapshotDate('4.0'));
    }
}
