<?php

declare(strict_types=1);

namespace CVS\Tests\Alerts;

use CVS\Alerts\PriceAlertRepository;
use CVS\Alerts\PriceAlertService;
use CVS\Api\FinancialDataFetcher;
use CVS\Auth\UserRepository;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure transition decision in PriceAlertService (Phase 8, slice 3).
 * Zone = [99, 101] throughout.
 */
class PriceAlertServiceTest extends TestCase
{
    public function test_out_to_in_sends(): void
    {
        $d = PriceAlertService::decide(100.0, 99.0, 101.0, 'out', 0.0);
        $this->assertSame('send', $d['action']);
        $this->assertSame('in', $d['new_state']);
    }

    public function test_null_state_in_zone_sends(): void
    {
        $d = PriceAlertService::decide(100.0, 99.0, 101.0, null, 0.0);
        $this->assertSame('send', $d['action']);
    }

    public function test_in_to_in_stays_silent(): void
    {
        $d = PriceAlertService::decide(100.0, 99.0, 101.0, 'in', 0.0);
        $this->assertSame('none', $d['action']);
    }

    public function test_in_to_out_rearms_no_send(): void
    {
        $d = PriceAlertService::decide(105.0, 99.0, 101.0, 'in', 0.0);
        $this->assertSame('rearm', $d['action']);
        $this->assertSame('out', $d['new_state']);
    }

    public function test_below_zone_rearms(): void
    {
        $d = PriceAlertService::decide(90.0, 99.0, 101.0, 'in', 0.0);
        $this->assertSame('rearm', $d['action']);
    }

    public function test_hysteresis_margin_holds_state_within_buffer(): void
    {
        // zone width = 2; margin frac 0.5 → margin 1.0. Price 101.5 is above zone_high
        // but within the 1.0 buffer → not yet 'out', so no re-arm while last_state='in'.
        $d = PriceAlertService::decide(101.5, 99.0, 101.0, 'in', 0.5);
        $this->assertSame('none', $d['action']);
    }

    public function test_hysteresis_margin_rearms_beyond_buffer(): void
    {
        // Price 102.5 exceeds zone_high + margin(1.0)=102.0 → re-arm.
        $d = PriceAlertService::decide(102.5, 99.0, 101.0, 'in', 0.5);
        $this->assertSame('rearm', $d['action']);
    }

    // ------------------------------------------------------------------
    // checkAndNotify() — content enrichment (Phase 8 follow-up)
    // ------------------------------------------------------------------

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))
        ');
        $this->pdo->exec('
            CREATE TABLE user_alert_settings (
                user_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')), PRIMARY KEY (user_id))
        ');
        $this->pdo->exec('
            CREATE TABLE ticker_zone (
                ticker TEXT PRIMARY KEY, zone_low REAL, zone_high REAL,
                stop_swing REAL, stop_fund REAL, fx_rate_to_usd REAL,
                source TEXT, computed_at TEXT NOT NULL)
        ');
        $this->pdo->exec('
            CREATE TABLE price_alert (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ticker TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1, last_state TEXT NULL, last_sent_at TEXT NULL,
                UNIQUE (user_id, ticker))
        ');
        $this->pdo->exec('
            CREATE TABLE cvs_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL, company_name TEXT NULL,
                score_date TEXT NOT NULL, cvs_swing REAL NULL, cvs_fund REAL NULL,
                reco_swing TEXT NULL, reco_fund TEXT NULL,
                days_since_earnings INTEGER NULL, days_to_earnings INTEGER NULL, earnings_state TEXT NULL,
                model_version TEXT NULL, origin TEXT NOT NULL DEFAULT \'rescore\')
        ');
    }

    /** @return array{0: PriceAlertService, 1: PriceAlertRepository} */
    private function makeService(float $latestPrice, ?string &$lastHtml = null): array
    {
        $repo    = new PriceAlertRepository($this->pdo);
        $fetcher = $this->createMock(FinancialDataFetcher::class);
        $fetcher->method('fetchLatestPrice')->willReturn($latestPrice);

        $mailMock = $this->createMock(MailService::class);
        $mailMock->method('send')->willReturnCallback(function ($to, $subject, $html) use (&$lastHtml) {
            $lastHtml = $html;
            return true;
        });

        $svc = new PriceAlertService(
            $repo,
            $fetcher,
            $mailMock,
            new UserRepository($this->pdo),
            new CvsSnapshotRepository($this->pdo),
            [],
            '4.0',
            ['window_days' => 90, 'min_points' => 2]
        );

        return [$svc, $repo];
    }

    public function test_send_enriches_mail_with_company_and_scores(): void
    {
        $this->pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (1, ?, ?)')->execute(['user@test.com', 'hash']);
        $this->pdo->prepare('INSERT INTO user_alert_settings (user_id, enabled) VALUES (1, 1)')->execute();
        $this->pdo->prepare(
            'INSERT INTO ticker_zone (ticker, zone_low, zone_high, stop_swing, stop_fund, computed_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['AVGO', 150.0, 160.0, 140.0, 130.0, date('Y-m-d H:i:s')]);
        $this->pdo->prepare(
            'INSERT INTO price_alert (user_id, ticker, enabled, last_state) VALUES (1, ?, 1, ?)'
        )->execute(['AVGO', 'out']);
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, company_name, score_date, cvs_swing, cvs_fund, reco_swing, reco_fund, model_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['AVGO', 'Broadcom Inc.', date('Y-m-d'), 57.9, 61.2, '⬆ AKUMULUJ', '⬆ AKUMULUJ', '4.0']);

        [$svc] = $this->makeService(155.0, $html);
        $sent  = $svc->checkAndNotify();

        $this->assertSame(1, $sent);
        $this->assertStringContainsString('AVGO — Broadcom Inc.', $html);
        $this->assertStringContainsString('CVS Swing', $html);
        $this->assertStringContainsString('57.9', $html);
        $this->assertStringContainsString('CVS Fundamentalny', $html);
        $this->assertStringContainsString('61.2', $html);
        $this->assertStringContainsString('Stop (fundamentalny)', $html);
        $this->assertStringContainsString('Wersja modelu: 4.0', $html);
        $this->assertStringContainsString('Zarządzaj na stronie analizy', $html);
    }

    public function test_send_works_without_snapshot_data(): void
    {
        // No cvs_snapshots row at all — the price alert must still fire; the
        // enrichment fields just don't render (graceful degradation).
        $this->pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (1, ?, ?)')->execute(['user@test.com', 'hash']);
        $this->pdo->prepare('INSERT INTO user_alert_settings (user_id, enabled) VALUES (1, 1)')->execute();
        $this->pdo->prepare(
            'INSERT INTO ticker_zone (ticker, zone_low, zone_high, computed_at) VALUES (?, ?, ?, ?)'
        )->execute(['MU', 90.0, 100.0, date('Y-m-d H:i:s')]);
        $this->pdo->prepare(
            'INSERT INTO price_alert (user_id, ticker, enabled, last_state) VALUES (1, ?, 1, ?)'
        )->execute(['MU', 'out']);

        [$svc] = $this->makeService(95.0, $html);
        $sent  = $svc->checkAndNotify();

        $this->assertSame(1, $sent);
        $this->assertStringContainsString('MU', $html);
        $this->assertStringNotContainsString('CVS Swing', $html);
    }

    // ------------------------------------------------------------------
    // sendPreviewMail() — manual production test tool (bin/send_test_mail.php)
    // ------------------------------------------------------------------

    public function test_send_preview_mail_renders_from_real_zone_and_price(): void
    {
        $this->pdo->prepare(
            'INSERT INTO ticker_zone (ticker, zone_low, zone_high, stop_swing, stop_fund, computed_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['AVGO', 195.0, 215.0, 180.0, 165.0, date('Y-m-d H:i:s')]);

        [$svc] = $this->makeService(208.50, $html);
        $sent = $svc->sendPreviewMail('AVGO', 'demo@test.com');

        $this->assertTrue($sent);
        $this->assertStringContainsString('$208.50', $html);
        $this->assertStringContainsString('$195.00', $html);
    }

    public function test_send_preview_mail_does_not_mislabel_price_above_zone(): void
    {
        // Regression: the preview reuses the "price entered the zone" template,
        // but the live price it renders against may sit outside the zone (real
        // cron sends only fire on an actual in-zone transition — the preview has
        // no such guarantee). Must show an accurate "above zone" badge, not the
        // in-zone green/checkmark.
        $this->pdo->prepare(
            'INSERT INTO ticker_zone (ticker, zone_low, zone_high, computed_at) VALUES (?, ?, ?, ?)'
        )->execute(['TSLA', 368.60, 386.43, date('Y-m-d H:i:s')]);

        [$svc] = $this->makeService(425.30, $html);
        $svc->sendPreviewMail('TSLA', 'demo@test.com');

        $this->assertStringContainsString('Powyżej strefy', $html);
        $this->assertStringNotContainsString('Cena w strefie kupna', $html);
    }

    public function test_send_preview_mail_returns_false_without_zone(): void
    {
        [$svc] = $this->makeService(100.0, $html);
        $sent = $svc->sendPreviewMail('ZZZZ', 'demo@test.com');

        $this->assertFalse($sent);
    }

    public function test_send_preview_mail_does_not_write_price_alert_state(): void
    {
        $this->pdo->prepare(
            'INSERT INTO ticker_zone (ticker, zone_low, zone_high, computed_at) VALUES (?, ?, ?, ?)'
        )->execute(['AVGO', 195.0, 215.0, date('Y-m-d H:i:s')]);

        [$svc, $repo] = $this->makeService(208.50, $html);
        $svc->sendPreviewMail('AVGO', 'demo@test.com');

        $this->assertSame([], $repo->findActiveAlerts(), 'preview must not create/touch price_alert rows');
    }
}
