<?php

declare(strict_types=1);

namespace CVS\Tests\Alerts;

use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Auth\UserRepository;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AlertServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_alert_settings (
                user_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                PRIMARY KEY (user_id))
        ');
        $this->pdo->exec('
            CREATE TABLE user_alert_ticker (
                user_id INTEGER NOT NULL, ticker TEXT NOT NULL, disabled INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (user_id, ticker))
        ');
        $this->pdo->exec('
            CREATE TABLE alert_sent (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                ticker TEXT NOT NULL, last_reco TEXT NULL, last_signal TEXT NULL,
                sent_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (user_id, ticker))
        ');
        $this->pdo->exec('
            CREATE TABLE watchlist (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ticker TEXT NOT NULL,
                added_at TEXT NOT NULL DEFAULT (datetime(\'now\')), UNIQUE (user_id, ticker))
        ');
        $this->pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))
        ');
        // Covers both findTrajectory() (score_date, cvs_swing, ticker, origin,
        // model_version) and findLatestByTicker() (used by sendPreviewMail()).
        // Absence is also tolerated gracefully (AlertService catches DB errors),
        // but most tests here want real data rather than a swallowed exception.
        $this->pdo->exec('
            CREATE TABLE cvs_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL, company_name TEXT NULL,
                score_date TEXT NOT NULL, cvs_swing REAL NULL, cvs_fund REAL NULL,
                reco_swing TEXT NULL, reco_fund TEXT NULL, golden_signal TEXT NULL,
                price_at_snapshot REAL NULL,
                days_since_earnings INTEGER NULL, days_to_earnings INTEGER NULL, earnings_state TEXT NULL,
                model_version TEXT NULL, origin TEXT NOT NULL DEFAULT \'rescore\')
        ');
    }

    private function makeService(bool &$mailSent = null, int &$sendCount = null, ?string &$lastHtml = null): AlertService
    {
        $mailSent  = false;
        $sendCount = 0;
        $lastHtml  = null;

        $mailMock = $this->createMock(MailService::class);
        $mailMock->method('send')->willReturnCallback(function ($to, $subject, $html) use (&$mailSent, &$sendCount, &$lastHtml) {
            $mailSent  = true;
            $sendCount++;
            $lastHtml  = $html;
            return true;
        });

        return new AlertService(
            new AlertRepository($this->pdo),
            $mailMock,
            new UserRepository($this->pdo),
            new CvsSnapshotRepository($this->pdo),
            ['window_days' => 90, 'min_points' => 2]
        );
    }

    private function setupUser(int $userId, string $email, bool $alertsEnabled, string $ticker): void
    {
        $this->pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$userId, $email, 'hash']);
        $this->pdo->prepare('INSERT INTO watchlist (user_id, ticker) VALUES (?, ?)')
            ->execute([$userId, $ticker]);
        if ($alertsEnabled) {
            $this->pdo->prepare('INSERT INTO user_alert_settings (user_id, enabled) VALUES (?, 1)')
                ->execute([$userId]);
        }
    }

    // ------------------------------------------------------------------

    public function test_no_alert_when_no_eligible_users(): void
    {
        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $this->assertSame(0, $count);
        $this->assertFalse($mailSent);
    }

    public function test_alert_sent_on_first_score_no_history(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $this->assertSame(1, $count);
        $this->assertTrue($mailSent);
    }

    public function test_no_alert_when_state_unchanged(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->upsertSent(1, 'AAPL', '⬆ AKUMULUJ', null);

        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $this->assertSame(0, $count);
        $this->assertFalse($mailSent);
    }

    public function test_alert_sent_on_reco_change(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->upsertSent(1, 'AAPL', '→ NEUTRALNIE', null);

        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆⬆ SILNE KUPUJ', 'cvs' => 80.0], 'golden_signal' => 'strong']);
        $this->assertSame(1, $count);
        $this->assertTrue($mailSent);
    }

    public function test_alert_sent_on_signal_change(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->upsertSent(1, 'AAPL', '⬆ AKUMULUJ', null);

        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => 'strong']);
        $this->assertSame(1, $count);
        $this->assertTrue($mailSent);
    }

    public function test_no_alert_when_ticker_disabled(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->setTickerDisabled(1, 'AAPL', true);

        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆⬆ SILNE KUPUJ', 'cvs' => 80.0], 'golden_signal' => null]);
        $this->assertSame(0, $count);
        $this->assertFalse($mailSent);
    }

    public function test_updates_last_sent_after_alert(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc       = $this->makeService();
        $alertRepo = new AlertRepository($this->pdo);

        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆⬆ SILNE KUPUJ', 'cvs' => 80.0], 'golden_signal' => 'strong']);

        $last = $alertRepo->getLastSent(1, 'AAPL');
        $this->assertNotNull($last);
        $this->assertSame('⬆⬆ SILNE KUPUJ', $last['last_reco']);
        $this->assertSame('strong', $last['last_signal']);
    }

    // ------------------------------------------------------------------
    // Content enrichment (company name, Fund score, price/zone, trajectory,
    // earnings timing, model version, mute-ticker link, old→new signal)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function fullResult(string $reco = '⬆ AKUMULUJ', float $swing = 65.0): array
    {
        return [
            'swing'           => ['recommendation' => $reco, 'cvs' => $swing],
            'fundamental'     => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 61.5],
            'golden_signal'   => 'watchlist',
            'model_version'   => '4.0',
            'earnings_timing' => ['days_since' => null, 'days_to' => 3, 'state' => 'before', 'guard_active' => true],
        ];
    }

    public function test_mail_includes_company_name_in_header(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AVGO');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AVGO', $this->fullResult(), 'Broadcom Inc.');
        $this->assertStringContainsString('AVGO — Broadcom Inc.', $html);
    }

    public function test_mail_includes_fundamental_score(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', $this->fullResult());
        $this->assertStringContainsString('CVS Fundamentalny', $html);
        $this->assertStringContainsString('61.5', $html);
    }

    public function test_mail_includes_model_version_and_date_footer(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', $this->fullResult());
        $this->assertStringContainsString('Wersja modelu: 4.0', $html);
    }

    public function test_mail_includes_earnings_proximity(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', $this->fullResult());
        $this->assertStringContainsString('Wyniki za 3 dni', $html);
    }

    public function test_mail_includes_price_and_zone_badge(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc  = $this->makeService($mailSent, $sendCount, $html);
        $zone = ['has_zone' => true, 'zone_low' => 150.0, 'zone_high' => 160.0];
        $svc->checkAndNotify('AAPL', $this->fullResult(), null, 155.0, $zone);
        $this->assertStringContainsString('$155.00', $html);
        $this->assertStringContainsString('Cena w strefie kupna', $html);
    }

    public function test_mail_includes_mute_ticker_link(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', $this->fullResult());
        $this->assertStringContainsString('/analysis/AAPL', $html);
        $this->assertStringContainsString('Zarządzaj na stronie analizy', $html);
    }

    public function test_mail_shows_signal_old_to_new_not_just_new(): void
    {
        // Regression: the pre-enrichment mail only rendered the NEW signal, so a
        // mail triggered purely by a signal change (reco unchanged) never told the
        // user what the signal actually transitioned from.
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->upsertSent(1, 'AAPL', '⬆ AKUMULUJ', null);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', $this->fullResult('⬆ AKUMULUJ'));

        $this->assertStringContainsString('brak → ⭐ Obserwuj', $html);
    }

    public function test_mail_includes_trajectory_when_history_present(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');

        // Two prior snapshots under the live model version — enough for a trajectory.
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, model_version, origin) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AAPL', date('Y-m-d', strtotime('-1 day')), 60.0, '4.0', 'rescore']);
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, model_version, origin) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AAPL', date('Y-m-d'), 65.0, '4.0', 'rescore']);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', $this->fullResult());

        $this->assertStringContainsString('Trajektoria', $html);
    }

    // ------------------------------------------------------------------
    // sendPreviewMail() — manual production test tool (bin/send_test_mail.php)
    // ------------------------------------------------------------------

    public function test_send_preview_mail_renders_from_latest_snapshot(): void
    {
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, company_name, score_date, cvs_swing, cvs_fund, reco_swing, reco_fund, golden_signal, model_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['AVGO', 'Broadcom Inc.', date('Y-m-d'), 57.9, 54.3, '⬆ AKUMULUJ', '⬆ AKUMULUJ', 'watchlist', '4.0']);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $sent = $svc->sendPreviewMail('AVGO', 'demo@test.com', '4.0');

        $this->assertTrue($sent);
        $this->assertTrue($mailSent);
        $this->assertStringContainsString('AVGO — Broadcom Inc.', $html);
        $this->assertStringContainsString('57.9', $html);
        $this->assertStringContainsString('54.3', $html);
    }

    public function test_send_preview_mail_uses_test_subject(): void
    {
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, reco_swing, model_version) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AAPL', date('Y-m-d'), 70.0, '⬆ AKUMULUJ', '4.0']);

        $sentSubject = null;
        $mailMock = $this->createMock(MailService::class);
        $mailMock->method('send')->willReturnCallback(function ($to, $subject) use (&$sentSubject) {
            $sentSubject = $subject;
            return true;
        });

        $svc = new AlertService(
            new AlertRepository($this->pdo),
            $mailMock,
            new UserRepository($this->pdo),
            new CvsSnapshotRepository($this->pdo)
        );
        $svc->sendPreviewMail('AAPL', 'demo@test.com', '4.0');

        $this->assertSame('[TEST] CVS Alert: AAPL — podgląd', $sentSubject);
    }

    public function test_send_preview_mail_returns_false_when_no_snapshot(): void
    {
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $sent = $svc->sendPreviewMail('ZZZZ', 'demo@test.com', '4.0');

        $this->assertFalse($sent);
        $this->assertFalse($mailSent);
    }

    public function test_send_preview_mail_does_not_write_alert_state(): void
    {
        // Safety property: a preview send must never touch alert_sent — it
        // would otherwise suppress a legitimate future real alert.
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, reco_swing, model_version) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AAPL', date('Y-m-d'), 70.0, '⬆ AKUMULUJ', '4.0']);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->sendPreviewMail('AAPL', 'demo@test.com', '4.0');

        $alertRepo = new AlertRepository($this->pdo);
        $this->assertNull($alertRepo->getLastSent(1, 'AAPL'));
    }
}
