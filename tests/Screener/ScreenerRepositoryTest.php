<?php

declare(strict_types=1);

namespace CVS\Tests\Screener;

use CVS\Screener\ScreenerRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ScreenerRepositoryTest extends TestCase
{
    /**
     * @param array{window_days?: int, min_points?: int, boundary_margin?: float} $trajectoryConfig
     * @param array<string, float> $thresholds
     * @param array{default_label?: string, labels?: array<string, string>} $marketsConfig
     */
    private function makeRepo(array $trajectoryConfig = [], array $thresholds = [], array $marketsConfig = []): ScreenerRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL, company_name TEXT NULL, sector TEXT NULL,
                model_version TEXT NULL,
                origin TEXT NOT NULL DEFAULT \'rescore\',
                score_date TEXT NOT NULL, scored_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                price_at_snapshot REAL NULL,
                cvs_swing REAL NULL, cvs_fund REAL NULL,
                reco_swing TEXT NULL, reco_fund TEXT NULL,
                golden_signal TEXT NULL, quality_gate INTEGER NOT NULL DEFAULT 0,
                gate_failures TEXT NULL, pillar_scores TEXT NULL, signals TEXT NULL,
                fx_rate_to_usd REAL NULL, native_currency TEXT NULL, native_price REAL NULL,
                fair_value_price REAL NULL,
                valuation_source  TEXT NULL,
                valuation_bucket  TEXT NULL,
                valuation_variant TEXT NULL,
                UNIQUE (ticker, score_date, model_version, origin)
            )
        ');
        return new ScreenerRepository($pdo, null, $trajectoryConfig, $thresholds, $marketsConfig);
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

    /** Insert a snapshot on an explicit date, for multi-day trend history. */
    private function insertSnapshotOn(PDO $pdo, string $ticker, string $scoreDate, float $swing): void
    {
        $pdo->prepare('
            INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, cvs_fund, reco_swing, quality_gate, origin)
            VALUES (?, ?, ?, ?, \'SILNE KUPUJ\', 1, \'rescore\')
        ')->execute([$ticker, $scoreDate, $swing, $swing]);
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

    // ------------------------------------------------------------------
    // Market filter (ticker suffix)
    // ------------------------------------------------------------------

    public function test_get_filtered_by_market_suffix(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertSnapshot($db, 'AAPL',  80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'PKN.WA', 60.0, 55.0, '⬆ AKUMULUJ', null);

        $result = $repo->getFiltered(null, null, 0, null, 'swing', null, false, false, '.WA');
        $this->assertCount(1, $result);
        $this->assertSame('PKN.WA', $result[0]['ticker']);
    }

    public function test_get_filtered_by_market_us_sentinel_matches_no_suffix_tickers(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertSnapshot($db, 'AAPL',  80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'PKN.WA', 60.0, 55.0, '⬆ AKUMULUJ', null);

        $result = $repo->getFiltered(null, null, 0, null, 'swing', null, false, false, 'US');
        $this->assertCount(1, $result);
        $this->assertSame('AAPL', $result[0]['ticker']);
    }

    public function test_get_distinct_markets_labels_and_sorts_us_first(): void
    {
        $repo = $this->makeRepo(marketsConfig: [
            'default_label' => 'USA (NYSE/NASDAQ)',
            'labels'        => ['.WA' => 'GPW (Warszawa)', '.KS' => 'Giełda Korei (KOSPI)'],
        ]);
        $db = $this->dbOf($repo);
        $this->insertSnapshot($db, 'PKN.WA', 60.0, 55.0, '⬆ AKUMULUJ', null);
        $this->insertSnapshot($db, 'AAPL',   80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, '005930.KS', 65.0, 60.0, '⬆ AKUMULUJ', null);

        $this->assertSame(
            [
                ['value' => 'US',  'label' => 'USA (NYSE/NASDAQ)'],
                ['value' => '.WA', 'label' => 'GPW (Warszawa)'],
                ['value' => '.KS', 'label' => 'Giełda Korei (KOSPI)'],
            ],
            $repo->getDistinctMarkets()
        );
    }

    public function test_get_distinct_markets_excludes_corpus_rows(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertSnapshot($db, 'AAPL', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);
        $this->insertSnapshot($db, 'PKN.WA', 60.0, 55.0, '⬆ AKUMULUJ', null, null, 1, 'corpus');

        $this->assertSame([['value' => 'US', 'label' => 'USA']], $repo->getDistinctMarkets());
    }

    // ------------------------------------------------------------------
    // Ticker links enrichment (change: cvs-screener-ticker-links)
    // ------------------------------------------------------------------

    public function test_get_filtered_attaches_ticker_links_bulk_loaded(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $db->exec('
            CREATE TABLE ticker_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL,
                label TEXT NOT NULL, url TEXT NOT NULL, created_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        $db->exec("INSERT INTO ticker_links (ticker, label, url, created_by) VALUES ('PKN.WA', 'TradingView', 'https://example.com/tv', 3)");

        $this->insertSnapshot($db, 'PKN.WA', 60.0, 55.0, '⬆ AKUMULUJ', null);
        $this->insertSnapshot($db, 'AAPL',   80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);

        $result = $repo->getFiltered();
        $byTicker = array_column($result, 'ticker_links', 'ticker');
        $this->assertCount(1, $byTicker['PKN.WA']);
        $this->assertSame('TradingView', $byTicker['PKN.WA'][0]['label']);
        $this->assertSame(3, $byTicker['PKN.WA'][0]['created_by']);
        $this->assertSame([], $byTicker['AAPL'], 'ticker with no curated links gets an empty array, not missing key');
    }

    public function test_get_filtered_degrades_gracefully_without_ticker_links_table(): void
    {
        // Baseline schema (makeRepo()) never creates ticker_links — this is the
        // pre-migration-033 shape. Must not throw.
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertSnapshot($db, 'AAPL', 80.0, 70.0, '⬆⬆ SILNE KUPUJ', null);

        $result = $repo->getFiltered();
        $this->assertSame([], $result[0]['ticker_links']);
    }

    // ------------------------------------------------------------------
    // ATR zone state enrichment (Phase 8 follow-up)
    // ------------------------------------------------------------------

    private function dbOf(ScreenerRepository $repo): PDO
    {
        $ref = new \ReflectionProperty(ScreenerRepository::class, 'db');
        $ref->setAccessible(true);
        return $ref->getValue($repo);
    }

    private function insertPriced(PDO $db, string $ticker, float $price): void
    {
        $db->prepare('
            INSERT INTO cvs_snapshots (ticker, score_date, price_at_snapshot, cvs_swing, cvs_fund, reco_swing, quality_gate, origin)
            VALUES (?, date(\'now\'), ?, 80.0, 70.0, \'SILNE KUPUJ\', 1, \'rescore\')
        ')->execute([$ticker, $price]);
    }

    public function test_get_filtered_enriches_atr_state(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $db->exec('CREATE TABLE ticker_zone (ticker TEXT PRIMARY KEY, zone_low REAL, zone_high REAL, stop_swing REAL, stop_fund REAL, fx_rate_to_usd REAL, source TEXT, computed_at TEXT NOT NULL)');

        $this->insertPriced($db, 'INZ', 100.0); // in [99,101]
        $this->insertPriced($db, 'ABV', 120.0); // above
        $this->insertPriced($db, 'BLW', 90.0);  // below
        $this->insertPriced($db, 'NOZ', 50.0);  // no zone row → null

        $z = $db->prepare('INSERT INTO ticker_zone (ticker, zone_low, zone_high, computed_at) VALUES (?, ?, ?, date(\'now\'))');
        foreach (['INZ', 'ABV', 'BLW'] as $t) {
            $z->execute([$t, 99.0, 101.0]);
        }

        $byTicker = array_column($repo->getFiltered(), 'atr_state', 'ticker');
        $this->assertSame('in_zone', $byTicker['INZ']);
        $this->assertSame('above',   $byTicker['ABV']);
        $this->assertSame('below',   $byTicker['BLW']);
        $this->assertNull($byTicker['NOZ']);
    }

    public function test_atr_state_null_when_zone_table_absent(): void
    {
        // makeRepo() builds no ticker_zone table — findZoneMap must degrade gracefully.
        $repo = $this->makeRepo();
        $this->insertPriced($this->dbOf($repo), 'AAPL', 100.0);

        $rows = $repo->getFiltered();
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('atr_state', $rows[0]);
        $this->assertNull($rows[0]['atr_state']);
    }

    private function seedZones(PDO $db): void
    {
        $db->exec('CREATE TABLE ticker_zone (ticker TEXT PRIMARY KEY, zone_low REAL, zone_high REAL, stop_swing REAL, stop_fund REAL, fx_rate_to_usd REAL, source TEXT, computed_at TEXT NOT NULL)');
        $this->insertPriced($db, 'INZ', 100.0); // in zone
        $this->insertPriced($db, 'ABV', 120.0); // above
        $this->insertPriced($db, 'BLW', 90.0);  // below
        $z = $db->prepare('INSERT INTO ticker_zone (ticker, zone_low, zone_high, computed_at) VALUES (?, 99.0, 101.0, date(\'now\'))');
        foreach (['INZ', 'ABV', 'BLW'] as $t) {
            $z->execute([$t]);
        }
    }

    public function test_get_filtered_by_atr_state(): void
    {
        $repo = $this->makeRepo();
        $this->seedZones($this->dbOf($repo));

        $res = $repo->getFiltered(null, null, 0, null, 'swing', 'in_zone');
        $this->assertCount(1, $res);
        $this->assertSame('INZ', $res[0]['ticker']);
    }

    public function test_sort_by_atr_ranks_in_zone_then_below_then_above(): void
    {
        $repo = $this->makeRepo();
        $this->seedZones($this->dbOf($repo));

        $res = $repo->getFiltered(null, null, 0, null, 'atr');
        $this->assertSame(['INZ', 'BLW', 'ABV'], array_column($res, 'ticker'));
    }

    // ------------------------------------------------------------------
    // Trend column (change: cvs-screener-trend, Phase 1)
    // ------------------------------------------------------------------

    public function test_trend_delta_weekly_computed_from_week_old_history(): void
    {
        $repo = $this->makeRepo(['window_days' => 90, 'min_points' => 2]);
        $db   = $this->dbOf($repo);

        $this->insertSnapshotOn($db, 'TREND', date('Y-m-d', strtotime('-8 days')), 70.0);
        $this->insertSnapshotOn($db, 'TREND', date('Y-m-d'), 75.0);

        $byTicker = array_column($repo->getFiltered(), 'trend_delta_weekly', 'ticker');
        $this->assertSame(5.0, $byTicker['TREND']);
    }

    public function test_trend_delta_weekly_null_when_insufficient_history(): void
    {
        $repo = $this->makeRepo(['window_days' => 90, 'min_points' => 2]);
        $db   = $this->dbOf($repo);

        $this->insertSnapshotOn($db, 'NEWTICKER', date('Y-m-d'), 60.0);

        $byTicker = array_column($repo->getFiltered(), 'trend_delta_weekly', 'ticker');
        $this->assertNull($byTicker['NEWTICKER']);
    }

    public function test_trend_delta_weekly_ignores_history_outside_window(): void
    {
        // Only history is 200 days old — well outside the 90-day window, so the
        // ticker's only point in-window is today's: not enough for a delta.
        $repo = $this->makeRepo(['window_days' => 90, 'min_points' => 2]);
        $db   = $this->dbOf($repo);

        $this->insertSnapshotOn($db, 'OLDHIST', date('Y-m-d', strtotime('-200 days')), 40.0);
        $this->insertSnapshotOn($db, 'OLDHIST', date('Y-m-d'), 65.0);

        $byTicker = array_column($repo->getFiltered(), 'trend_delta_weekly', 'ticker');
        $this->assertNull($byTicker['OLDHIST']);
    }

    public function test_trend_delta_daily_computed_from_previous_snapshot(): void
    {
        $repo = $this->makeRepo(['window_days' => 90, 'min_points' => 2]);
        $db   = $this->dbOf($repo);

        $this->insertSnapshotOn($db, 'TREND', date('Y-m-d', strtotime('-1 day')), 70.0);
        $this->insertSnapshotOn($db, 'TREND', date('Y-m-d'), 68.5);

        $byTicker = array_column($repo->getFiltered(), 'trend_delta_daily', 'ticker');
        $this->assertSame(-1.5, $byTicker['TREND']);
    }

    public function test_trend_delta_daily_null_when_insufficient_history(): void
    {
        $repo = $this->makeRepo(['window_days' => 90, 'min_points' => 2]);
        $db   = $this->dbOf($repo);

        $this->insertSnapshotOn($db, 'NEWTICKER', date('Y-m-d'), 60.0);

        $byTicker = array_column($repo->getFiltered(), 'trend_delta_daily', 'ticker');
        $this->assertNull($byTicker['NEWTICKER']);
    }

    public function test_is_near_boundary_true_exactly_on_threshold(): void
    {
        $repo = $this->makeRepo([], ['strong_buy' => 72.0, 'accumulate' => 58.0, 'neutral' => 42.0, 'reduce' => 28.0]);
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'ONTHRESH', date('Y-m-d'), 72.0);

        $byTicker = array_column($repo->getFiltered(), 'trend_near_boundary', 'ticker');
        $this->assertTrue($byTicker['ONTHRESH']);
    }

    public function test_is_near_boundary_true_within_margin(): void
    {
        $repo = $this->makeRepo([], ['strong_buy' => 72.0, 'accumulate' => 58.0, 'neutral' => 42.0, 'reduce' => 28.0]);
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'NEARBY', date('Y-m-d'), 76.0); // 4 pts from 72

        $byTicker = array_column($repo->getFiltered(), 'trend_near_boundary', 'ticker');
        $this->assertTrue($byTicker['NEARBY']);
    }

    public function test_is_near_boundary_false_outside_margin(): void
    {
        $repo = $this->makeRepo([], ['strong_buy' => 72.0, 'accumulate' => 58.0, 'neutral' => 42.0, 'reduce' => 28.0]);
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'FARAWAY', date('Y-m-d'), 78.0); // 6 pts from 72, 20 from 58

        $byTicker = array_column($repo->getFiltered(), 'trend_near_boundary', 'ticker');
        $this->assertFalse($byTicker['FARAWAY']);
    }

    public function test_is_near_boundary_false_when_thresholds_not_provided(): void
    {
        // Backward-compat: default empty thresholds must not crash and simply
        // never flag anything as near-boundary.
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'NOCFG', date('Y-m-d'), 72.0);

        $byTicker = array_column($repo->getFiltered(), 'trend_near_boundary', 'ticker');
        $this->assertFalse($byTicker['NOCFG']);
    }

    // ------------------------------------------------------------------
    // "Near boundary" filter (change: cvs-screener-trend, Phase 2)
    // ------------------------------------------------------------------

    private function thresholds(): array
    {
        return ['strong_buy' => 72.0, 'accumulate' => 58.0, 'neutral' => 42.0, 'reduce' => 28.0];
    }

    public function test_near_boundary_filter_isolated(): void
    {
        $repo = $this->makeRepo([], $this->thresholds());
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'NEARBY', date('Y-m-d'), 74.0); // within 5 of 72
        $this->insertSnapshotOn($db, 'FARAWAY', date('Y-m-d'), 90.0); // far from all thresholds

        $tickers = array_column($repo->getFiltered(null, null, 0, null, 'swing', null, true), 'ticker');
        $this->assertSame(['NEARBY'], $tickers);
    }

    public function test_near_boundary_filter_off_by_default_shows_everything(): void
    {
        $repo = $this->makeRepo([], $this->thresholds());
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'NEARBY', date('Y-m-d'), 74.0);
        $this->insertSnapshotOn($db, 'FARAWAY', date('Y-m-d'), 90.0);

        $tickers = array_column($repo->getFiltered(), 'ticker');
        sort($tickers);
        $this->assertSame(['FARAWAY', 'NEARBY'], $tickers);
    }

    public function test_near_boundary_filter_composes_with_sector_filter(): void
    {
        $repo = $this->makeRepo([], $this->thresholds());
        $db   = $this->dbOf($repo);
        $this->insertSnapshotOn($db, 'TECHNEAR', date('Y-m-d'), 74.0);
        $db->prepare("UPDATE cvs_snapshots SET sector = 'Technology' WHERE ticker = 'TECHNEAR'")->execute();
        $this->insertSnapshotOn($db, 'ENERGYNEAR', date('Y-m-d'), 74.0);
        $db->prepare("UPDATE cvs_snapshots SET sector = 'Energy' WHERE ticker = 'ENERGYNEAR'")->execute();

        // near_boundary=true AND sector=Technology → only the Technology one.
        $tickers = array_column($repo->getFiltered(null, null, 0, 'Technology', 'swing', null, true), 'ticker');
        $this->assertSame(['TECHNEAR'], $tickers);
    }

    // ------------------------------------------------------------------
    // FV column (migration 031) — CVS Fair Value margin over price
    // ------------------------------------------------------------------

    private function insertWithFv(PDO $db, string $ticker, float $price, ?float $fairValue): void
    {
        $db->prepare('
            INSERT INTO cvs_snapshots (ticker, score_date, price_at_snapshot, fair_value_price,
                cvs_swing, cvs_fund, reco_swing, quality_gate, origin)
            VALUES (?, date(\'now\'), ?, ?, 80.0, 70.0, \'SILNE KUPUJ\', 1, \'rescore\')
        ')->execute([$ticker, $price, $fairValue]);
    }

    public function test_fv_margin_pct_positive_when_fair_value_above_price(): void
    {
        $repo = $this->makeRepo();
        $this->insertWithFv($this->dbOf($repo), 'MU', 979.30, 1882.69);

        $byTicker = array_column($repo->getFiltered(), 'fv_margin_pct', 'ticker');
        $this->assertEqualsWithDelta(92.28, $byTicker['MU'], 0.1);
    }

    public function test_fv_margin_pct_negative_when_fair_value_below_price(): void
    {
        $repo = $this->makeRepo();
        $this->insertWithFv($this->dbOf($repo), 'SNDK', 1915.92, 1273.73);

        $byTicker = array_column($repo->getFiltered(), 'fv_margin_pct', 'ticker');
        $this->assertEqualsWithDelta(-33.5, $byTicker['SNDK'], 0.1);
    }

    public function test_fv_margin_pct_null_when_fair_value_missing(): void
    {
        $repo = $this->makeRepo();
        $this->insertWithFv($this->dbOf($repo), 'XTB', 34.19, null);

        $byTicker = array_column($repo->getFiltered(), 'fv_margin_pct', 'ticker');
        $this->assertNull($byTicker['XTB']);
    }

    public function test_fv_only_filter_keeps_only_positive_margin(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertWithFv($db, 'MU',   979.30, 1882.69);  // +92%
        $this->insertWithFv($db, 'SNDK', 1915.92, 1273.73); // -33%
        $this->insertWithFv($db, 'XTB',  34.19, null);      // unavailable

        $tickers = array_column($repo->getFiltered(null, null, 0, null, 'swing', null, false, true), 'ticker');
        $this->assertSame(['MU'], $tickers);
    }

    public function test_sort_by_fv_ranks_highest_margin_first(): void
    {
        $repo = $this->makeRepo();
        $db   = $this->dbOf($repo);
        $this->insertWithFv($db, 'SNDK', 1915.92, 1273.73); // -33%
        $this->insertWithFv($db, 'MU',   979.30, 1882.69);  // +92%
        $this->insertWithFv($db, 'XTB',  34.19, null);      // unavailable, sorts last

        $tickers = array_column($repo->getFiltered(null, null, 0, null, 'fv'), 'ticker');
        $this->assertSame(['MU', 'SNDK', 'XTB'], $tickers);
    }
}
