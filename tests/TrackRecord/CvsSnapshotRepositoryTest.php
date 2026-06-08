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
                industry           TEXT    NULL,
                model_version      TEXT    NULL,
                days_since_earnings   INTEGER NULL,
                days_to_earnings      INTEGER NULL,
                earnings_state        TEXT    NULL,
                earnings_guard_active INTEGER NULL,
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

    // ------------------------------------------------------------------
    // Phase 3: industry + model_version
    // ------------------------------------------------------------------

    public function test_save_stores_industry_and_model_version(): void
    {
        $repo = $this->makeRepo();
        $repo->save('TTWO', $this->passResult('TTWO'), 200.0, 'Communication Services', 'Electronic Gaming & Multimedia', '3.0');

        $row = $repo->findLatestByTicker('TTWO');
        $this->assertNotNull($row);
        $this->assertSame('Electronic Gaming & Multimedia', $row['industry']);
        $this->assertSame('3.0', $row['model_version']);
    }

    public function test_save_null_industry_and_version_backward_compat(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL')); // old callers don't pass industry/version

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertNull($row['industry']);
        $this->assertNull($row['model_version']);
    }

    public function test_upsert_updates_industry_when_model_version_unchanged(): void
    {
        // Phase 5 (slice 1) adaptation: model_version is now part of a snapshot's
        // *identity* (it differentiates the base 3.0 row from the 3.1 shadow row —
        // see the widened-key tests below). A same-day rerun that keeps the version
        // constant but resolves richer industry data must still update in place —
        // that's the realistic "second run of the day" scenario this test guards.
        // (Changing model_version itself is no longer an in-place metadata edit —
        // it now identifies a distinct snapshot row; see the NULL-safe WHERE match
        // in CvsSnapshotRepository::save()'s UPDATE fallback.)
        $repo = $this->makeRepo();
        $repo->save('AAPL', $this->passResult('AAPL'), 150.0, 'Technology', null, '3.0');
        $repo->save('AAPL', $this->passResult('AAPL'), 152.0, 'Technology', 'Consumer Electronics', '3.0');

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('Consumer Electronics', $row['industry']);
        $this->assertSame('3.0', $row['model_version']);
    }

    // ------------------------------------------------------------------
    // Phase 5 (slice 2): earnings-timing markers (FR-008, migration 015)
    // ------------------------------------------------------------------

    public function test_save_persists_earnings_timing_round_trip(): void
    {
        $repo   = $this->makeRepo();
        $result = $this->passResult('AAPL');
        $result['earnings_timing'] = [
            'days_since'   => 1,
            'days_to'      => null,
            'state'        => 'after',
            'guard_active' => true,
        ];
        $repo->save('AAPL', $result);

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['days_since_earnings']);
        $this->assertNull($row['days_to_earnings']);
        $this->assertSame('after', $row['earnings_state']);
        $this->assertSame(1, (int) $row['earnings_guard_active']);
    }

    public function test_save_persists_negative_days_to_earnings(): void
    {
        // days_to may be NEGATIVE (Yahoo calendar/data-lag signal — 'in_transit').
        $repo   = $this->makeRepo();
        $result = $this->passResult('MU');
        $result['earnings_timing'] = [
            'days_since'   => 1,
            'days_to'      => -2,
            'state'        => 'in_transit',
            'guard_active' => true,
        ];
        $repo->save('MU', $result);

        $row = $repo->findLatestByTicker('MU');
        $this->assertNotNull($row);
        $this->assertSame(-2, (int) $row['days_to_earnings']);
        $this->assertSame('in_transit', $row['earnings_state']);
    }

    public function test_save_updates_earnings_timing_idempotently(): void
    {
        $repo = $this->makeRepo();

        $first = $this->passResult('AAPL');
        $first['earnings_timing'] = [
            'days_since'   => null,
            'days_to'      => 4,
            'state'        => 'before',
            'guard_active' => true,
        ];
        $repo->save('AAPL', $first);

        // Same-day rerun, one session later — the badge has moved on.
        $second = $this->passResult('AAPL');
        $second['earnings_timing'] = [
            'days_since'   => null,
            'days_to'      => 3,
            'state'        => 'before',
            'guard_active' => true,
        ];
        $repo->save('AAPL', $second);

        $row = $repo->findLatestByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['days_to_earnings']);
        $this->assertNull($row['days_since_earnings']);
        $this->assertSame('before', $row['earnings_state']);
    }

    public function test_save_persists_null_earnings_timing_when_absent(): void
    {
        // Quality-gate failures (and tickers with no `calendarEvents` coverage)
        // carry no `earnings_timing` block at all — must persist as NULL, not error.
        $repo = $this->makeRepo();
        $repo->save('XYZ', $this->failResult('XYZ'));

        $row = $repo->findLatestByTicker('XYZ');
        $this->assertNotNull($row);
        $this->assertNull($row['days_since_earnings']);
        $this->assertNull($row['days_to_earnings']);
        $this->assertNull($row['earnings_state']);
        $this->assertNull($row['earnings_guard_active']);
    }

    public function test_save_persists_null_guard_active_when_no_calendar_coverage(): void
    {
        // CVSModel::computeEarningsTiming() returns a block with state === null
        // (and guard_active === false) when both day-counts are missing — but
        // CVSResult::$earningsTiming itself is null only absent calendar coverage
        // entirely. Guard against accidental int-cast surprises on `false`.
        $repo   = $this->makeRepo();
        $result = $this->passResult('NFLX');
        $result['earnings_timing'] = [
            'days_since'   => null,
            'days_to'      => null,
            'state'        => null,
            'guard_active' => false,
        ];
        $repo->save('NFLX', $result);

        $row = $repo->findLatestByTicker('NFLX');
        $this->assertNotNull($row);
        $this->assertNull($row['days_since_earnings']);
        $this->assertNull($row['days_to_earnings']);
        $this->assertNull($row['earnings_state']);
        $this->assertSame(0, (int) $row['earnings_guard_active']);
    }

    // ------------------------------------------------------------------
    // Phase 5 (slice 1): shadow persistence — widened key
    // (ticker, score_date, model_version), per migration 014
    // ------------------------------------------------------------------

    /**
     * Builds a repo against the *post-migration-014* schema — UNIQUE widened to
     * (ticker, score_date, model_version) — so a 3.0 row and a 3.1 shadow row
     * can coexist for the same (ticker, score_date). Returns the PDO alongside
     * the repo so tests can inspect raw row counts (the repo's read API only
     * surfaces the latest row per ticker/day, not per version).
     *
     * @return array{0: CvsSnapshotRepository, 1: PDO}
     */
    private function makeVersionedRepo(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker             TEXT    NOT NULL,
                sector             TEXT    NULL,
                industry           TEXT    NULL,
                model_version      TEXT    NULL,
                days_since_earnings   INTEGER NULL,
                days_to_earnings      INTEGER NULL,
                earnings_state        TEXT    NULL,
                earnings_guard_active INTEGER NULL,
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
                UNIQUE (ticker, score_date, model_version)
            )
        ');

        return [new CvsSnapshotRepository($pdo), $pdo];
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsForTicker(PDO $pdo, string $ticker): array
    {
        $stmt = $pdo->prepare(
            'SELECT model_version, cvs_swing, cvs_fund, reco_swing
             FROM cvs_snapshots WHERE ticker = ? ORDER BY model_version ASC'
        );
        $stmt->execute([$ticker]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function test_save_3_0_and_3_1_coexist_for_same_ticker_and_day(): void
    {
        [$repo, $pdo] = $this->makeVersionedRepo();

        $base = $this->passResult('AAPL');
        $base['swing']['cvs'] = 74.0;

        $shadow = $this->passResult('AAPL');
        $shadow['swing']['cvs']       = 62.0; // shadow carries overlay-penalised scores
        $shadow['fundamental']['cvs'] = 58.0;
        $shadow['swing']['recommendation'] = '→ NEUTRALNIE';

        $repo->save('AAPL', $base,   185.50, 'Technology', null, '3.0');
        $repo->save('AAPL', $shadow, 185.50, 'Technology', null, '3.1');

        $rows = $this->rowsForTicker($pdo, 'AAPL');
        $this->assertCount(2, $rows, 'base (3.0) and shadow (3.1) rows must coexist — no collision');

        $byVersion = array_column($rows, null, 'model_version');
        $this->assertArrayHasKey('3.0', $byVersion);
        $this->assertArrayHasKey('3.1', $byVersion);
        $this->assertEquals(74.0, $byVersion['3.0']['cvs_swing']);
        $this->assertEquals(62.0, $byVersion['3.1']['cvs_swing']);
        $this->assertEquals(58.0, $byVersion['3.1']['cvs_fund']);
        $this->assertSame('→ NEUTRALNIE', $byVersion['3.1']['reco_swing']);
    }

    public function test_save_pair_is_idempotent_no_duplicate_rows_on_double_run(): void
    {
        [$repo, $pdo] = $this->makeVersionedRepo();

        $base   = $this->passResult('MSFT');
        $shadow = $this->passResult('MSFT');
        $shadow['swing']['cvs'] = 60.0;

        // First rescore run — writes the 3.0/3.1 pair.
        $repo->save('MSFT', $base,   400.0, 'Technology', null, '3.0');
        $repo->save('MSFT', $shadow, 400.0, 'Technology', null, '3.1');

        // Second run, same day, fresher numbers — must update in place, not duplicate.
        $base2   = $this->passResult('MSFT');
        $base2['swing']['cvs'] = 76.0;
        $shadow2 = $this->passResult('MSFT');
        $shadow2['swing']['cvs'] = 64.0;

        $repo->save('MSFT', $base2,   402.0, 'Technology', null, '3.0');
        $repo->save('MSFT', $shadow2, 402.0, 'Technology', null, '3.1');

        $rows = $this->rowsForTicker($pdo, 'MSFT');
        $this->assertCount(2, $rows, 'double-run must not create duplicate (ticker, score_date, model_version) rows');

        $byVersion = array_column($rows, null, 'model_version');
        $this->assertEquals(76.0, $byVersion['3.0']['cvs_swing'], 'base row must reflect the second run, not a duplicate of the first');
        $this->assertEquals(64.0, $byVersion['3.1']['cvs_swing'], 'shadow row must reflect the second run, not a duplicate of the first');
    }

    public function test_save_versioned_update_does_not_cross_contaminate_rows(): void
    {
        // Guards the NULL-safe model_version match in the UPDATE fallback: updating
        // the 3.1 row must not also overwrite (or be confused with) the 3.0 row.
        [$repo, $pdo] = $this->makeVersionedRepo();

        $repo->save('GOOG', $this->passResult('GOOG'), 170.0, 'Technology', null, '3.0');
        $repo->save('GOOG', $this->passResult('GOOG'), 170.0, 'Technology', null, '3.1');

        $shadowUpdate = $this->passResult('GOOG');
        $shadowUpdate['swing']['cvs'] = 55.0;
        $repo->save('GOOG', $shadowUpdate, 171.0, 'Technology', null, '3.1');

        $rows      = $this->rowsForTicker($pdo, 'GOOG');
        $byVersion = array_column($rows, null, 'model_version');

        $this->assertCount(2, $rows);
        $this->assertEquals(74.0, $byVersion['3.0']['cvs_swing'], 'base (3.0) row must remain untouched by the shadow-row update');
        $this->assertEquals(55.0, $byVersion['3.1']['cvs_swing'], 'shadow (3.1) row must reflect its own update');
    }
}
