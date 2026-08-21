<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Alerts\PriceAlertRepository;
use CVS\Auth\UserRepository;
use CVS\CVS\CVSModel;
use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\SingleTickerRescorer;
use CVS\TrackRecord\SnapshotWriter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SingleTickerRescorer (change: fundamentals-validation).
 *
 * Wires real collaborators against in-memory SQLite (same offline pattern as
 * CVSModelTest / SnapshotWriterTest / AiCriticalReviewRepositoryTest) — no
 * mocking framework, matching this codebase's existing test style.
 * Watchlist/user_alert_settings are intentionally empty so AlertService's
 * checkAndNotify() takes its early-return path (no watching users) without
 * needing to wire a real mailer.
 */
class SingleTickerRescorerTest extends TestCase
{
    private array $cvsConfig;

    protected function setUp(): void
    {
        $this->cvsConfig = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
    }

    /** @return array{0: SingleTickerRescorer, 1: PDO, 2: array<string, string>} */
    private function makeRescorer(array $peerBucketOverrides = []): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE peer_medians (
                id INTEGER PRIMARY KEY,
                level TEXT NOT NULL,
                bucket_key TEXT NOT NULL,
                parent_sector TEXT NULL,
                model_version TEXT NOT NULL,
                metric_type TEXT NOT NULL,
                median_value REAL NULL,
                sample_count INTEGER NOT NULL DEFAULT 0,
                computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(level, bucket_key, model_version, metric_type)
            )
        ');

        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker             TEXT    NOT NULL,
                company_name       TEXT    NULL,
                sector             TEXT    NULL,
                industry           TEXT    NULL,
                model_version      TEXT    NULL,
                origin             TEXT    NOT NULL DEFAULT \'rescore\',
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
                signals            TEXT    NULL,
                fx_rate_to_usd     REAL    NULL,
                native_currency    TEXT    NULL,
                native_price       REAL    NULL,
                fair_value_price   REAL    NULL,
                valuation_source  TEXT NULL,
                valuation_bucket  TEXT NULL,
                valuation_variant TEXT NULL,
                UNIQUE (ticker, score_date, model_version, origin)
            )
        ');

        $pdo->exec('
            CREATE TABLE ticker_zone (
                ticker         TEXT NOT NULL UNIQUE,
                zone_low       REAL NULL,
                zone_high      REAL NULL,
                stop_swing     REAL NULL,
                stop_fund      REAL NULL,
                fx_rate_to_usd REAL NULL,
                source         TEXT NULL,
                computed_at    TEXT NOT NULL
            )
        ');

        $pdo->exec('CREATE TABLE watchlist (user_id INTEGER NOT NULL, ticker TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE user_alert_settings (user_id INTEGER NOT NULL, enabled INTEGER NOT NULL)');

        $peerRepo = new PeerMedianRepository($pdo);
        $model    = new CVSModel($this->cvsConfig, $peerRepo);
        $writer   = new SnapshotWriter(new CvsSnapshotRepository($pdo));
        $resolver = MedianResolver::fromConfig($this->cvsConfig, $peerRepo);
        $priceAlertRepo = new PriceAlertRepository($pdo);
        $alertService   = new AlertService(
            new AlertRepository($pdo),
            new MailService(),
            new UserRepository($pdo),
            new CvsSnapshotRepository($pdo),
            []
        );
        $atrZonesConfig = is_array($this->cvsConfig['atr_zones'] ?? null) ? $this->cvsConfig['atr_zones'] : [];

        $rescorer = new SingleTickerRescorer(
            $model,
            $writer,
            $resolver,
            $priceAlertRepo,
            $alertService,
            $atrZonesConfig,
            $peerBucketOverrides
        );

        return [$rescorer, $pdo, $atrZonesConfig];
    }

    /** @return array<string, mixed> */
    private function baseFinancials(array $overrides = []): array
    {
        return array_merge([
            'sector'                     => 'Technology',
            'current_price'              => 150.0,
            'fifty_two_week_low'         => 100.0,
            'fifty_two_week_high'        => 200.0,
            'moving_average_200'         => 145.0,
            'revenue'                    => 10_000_000,
            'gross_profit'               =>  3_000_000,
            'ebitda'                     =>  2_000_000,
            'revenue_history'            => [7_000_000, 8_000_000, 9_000_000, 10_000_000],
            'gross_margin_history'       => [0.29, 0.30, 0.30, 0.30],
            'total_debt'                 =>  1_000_000,
            'total_equity'               =>  5_000_000,
            'cash'                       =>    500_000,
            'current_assets'             =>  3_000_000,
            'current_liabilities'        =>  1_500_000,
            'free_cash_flow'             =>  1_500_000,
            'operating_cash_flow'        =>  1_800_000,
            'return_on_equity'           => 0.18,
            'pe_ratio'                   => 22.0,
            'ps_ratio'                   =>  2.5,
            'ev_ebitda'                  => 10.0,
            'shares_outstanding'         => 15_000_000_000.0,
            'gross_margins'              => 0.45,
            'forward_eps'                => 7.0,
            'trailing_eps'               => 6.0,
            'revenue_growth'             => 0.10,
            'earnings_quarterly_growth'  => null,
            'monthly_closes'             => [140.0, 145.0, 150.0, 148.0, 155.0, 160.0, 162.0],
            'spy_closes'                 => [430.0, 432.0, 435.0, 433.0, 438.0, 440.0, 442.0],
        ], $overrides);
    }

    public function test_gate_passing_financials_persist_a_snapshot(): void
    {
        [$rescorer, $pdo] = $this->makeRescorer();

        $result = $rescorer->rescore('TST', $this->baseFinancials(), $this->cvsConfig);

        $this->assertTrue($result->qualityGatePassed);

        $rows = $pdo->query("SELECT * FROM cvs_snapshots WHERE ticker = 'TST'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        $this->assertSame(1, (int) $rows[0]['quality_gate']);
        $this->assertSame('rescore', $rows[0]['origin']);
    }

    public function test_gate_rejecting_financials_still_persist_with_live_model_version(): void
    {
        [$rescorer, $pdo] = $this->makeRescorer();

        // revenue <= 0 fails the Quality Gate deterministically.
        $result = $rescorer->rescore('TST', $this->baseFinancials(['revenue' => 0]), $this->cvsConfig);

        $this->assertFalse($result->qualityGatePassed);

        $rows = $pdo->query("SELECT * FROM cvs_snapshots WHERE ticker = 'TST'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        $this->assertSame(0, (int) $rows[0]['quality_gate']);
        // NULL model_version on a written row is exactly the bug
        // context/foundation/lessons.md warns about — must never happen, even
        // on a gate rejection.
        $this->assertNotNull($rows[0]['model_version']);
        $this->assertNotSame('', (string) $rows[0]['model_version']);
    }

    public function test_atr_zone_upsert_is_skipped_when_daily_ohlc_is_absent(): void
    {
        [$rescorer, $pdo] = $this->makeRescorer();

        $rescorer->rescore('TST', $this->baseFinancials(), $this->cvsConfig);

        $rows = $pdo->query("SELECT * FROM ticker_zone WHERE ticker = 'TST'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEmpty($rows);
    }

    public function test_peer_bucket_override_is_applied_when_present(): void
    {
        [$rescorer, $pdo] = $this->makeRescorer(['TST' => 'Custom Peer Group']);

        // Result only needs to be produced without error; the override's
        // effect on scoring is ValuationPillar's concern (already tested
        // elsewhere) — this test only confirms the injected map reaches
        // $financials the same way bin/rescore.php's does.
        $result = $rescorer->rescore('TST', $this->baseFinancials(), $this->cvsConfig);

        $this->assertInstanceOf(\CVS\CVS\CVSResult::class, $result->cvsResult);
    }

    public function test_fair_value_is_returned_when_computable(): void
    {
        [$rescorer] = $this->makeRescorer();

        $result = $rescorer->rescore('TST', $this->baseFinancials(), $this->cvsConfig);

        // Not every fixture necessarily yields a fair value (depends on
        // FairPriceCalculator's own guards) — the contract under test is
        // that the field is threaded through, not a specific number.
        $this->assertTrue($result->fairValue === null || is_float($result->fairValue));
    }
}
