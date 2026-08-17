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

    /**
     * @param bool     $mailSent  set true once >=1 digest actually sent
     * @param int      $sendCount number of digest EMAILS sent (one per user, not per row)
     * @param string   $lastHtml  HTML of the most recently sent digest
     */
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
    // checkAndNotify() — detection and queueing only, nothing sent yet
    // ------------------------------------------------------------------

    public function test_no_alert_when_no_eligible_users(): void
    {
        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $this->assertSame(0, $count);
        $svc->flushDigests();
        $this->assertFalse($mailSent);
    }

    public function test_change_is_queued_but_not_sent_until_flush(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);

        $this->assertSame(1, $count);
        $this->assertFalse($mailSent, 'checkAndNotify() alone must not send mail — that is flushDigests()\'s job');
    }

    public function test_alert_sent_on_first_score_no_history(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent);
        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $svc->flushDigests();
        $this->assertTrue($mailSent);
    }

    public function test_no_alert_when_state_unchanged(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->upsertSent(1, 'AAPL', '⬆ AKUMULUJ', null);

        $svc   = $this->makeService($mailSent);
        $count = $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $svc->flushDigests();
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
        $svc->flushDigests();
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
        $svc->flushDigests();
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
        $svc->flushDigests();
        $this->assertSame(0, $count);
        $this->assertFalse($mailSent);
    }

    public function test_updates_last_sent_at_queue_time_not_flush_time(): void
    {
        // alert_sent must be written as soon as checkAndNotify() detects the
        // change — not deferred to flushDigests() — so an SMTP failure at
        // flush time does not cause the same change to be re-queued tomorrow.
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
    // flushDigests() — batching: the whole point of this change
    // ------------------------------------------------------------------

    public function test_one_digest_email_covers_every_changed_ticker_for_a_user(): void
    {
        // The regression this rewrite exists to fix: a user watching several
        // tickers that all change in the same rescore run must get ONE email,
        // not one per ticker.
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $this->pdo->prepare('INSERT INTO watchlist (user_id, ticker) VALUES (?, ?)')->execute([1, 'MSFT']);
        $this->pdo->prepare('INSERT INTO watchlist (user_id, ticker) VALUES (?, ?)')->execute([1, 'GOOG']);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ',   'cvs' => 65.0], 'golden_signal' => null]);
        $svc->checkAndNotify('MSFT', ['swing' => ['recommendation' => '⬆⬆ SILNE KUPUJ', 'cvs' => 80.0], 'golden_signal' => 'strong']);
        $svc->checkAndNotify('GOOG', ['swing' => ['recommendation' => '⬇ REDUKUJ',     'cvs' => 25.0], 'golden_signal' => null]);

        $sent = $svc->flushDigests();

        $this->assertSame(1, $sent, 'three changed tickers for one user must produce exactly one digest email');
        $this->assertSame(1, $sendCount);
        $this->assertStringContainsString('AAPL', $html);
        $this->assertStringContainsString('MSFT', $html);
        $this->assertStringContainsString('GOOG', $html);
    }

    public function test_digests_are_separate_per_user(): void
    {
        $this->setupUser(1, 'alice@test.com', true, 'AAPL');
        $this->setupUser(2, 'bob@test.com', true, 'AAPL');

        $svc = $this->makeService($mailSent, $sendCount);
        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $sent = $svc->flushDigests();

        $this->assertSame(2, $sent, 'two watchers of the same ticker get two separate digests, one each');
    }

    public function test_flush_with_nothing_queued_sends_nothing(): void
    {
        $svc  = $this->makeService($mailSent, $sendCount);
        $sent = $svc->flushDigests();

        $this->assertSame(0, $sent);
        $this->assertFalse($mailSent);
    }

    public function test_flush_clears_the_queue(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount);
        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);

        $svc->flushDigests();
        $secondFlush = $svc->flushDigests();

        $this->assertSame(1, $sendCount, 'a second flushDigests() with nothing newly queued must not resend');
        $this->assertSame(0, $secondFlush);
    }

    public function test_digest_subject_names_the_ticker_when_singular(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $sentSubject = null;
        $mailMock = $this->createMock(MailService::class);
        $mailMock->method('send')->willReturnCallback(function ($to, $subject) use (&$sentSubject) {
            $sentSubject = $subject;
            return true;
        });
        $svc = new AlertService(new AlertRepository($this->pdo), $mailMock, new UserRepository($this->pdo), new CvsSnapshotRepository($this->pdo));

        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $svc->flushDigests();

        $this->assertSame('CVS Alert: AAPL — zmiana sygnału', $sentSubject);
    }

    public function test_digest_subject_gives_a_count_when_plural(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $this->pdo->prepare('INSERT INTO watchlist (user_id, ticker) VALUES (?, ?)')->execute([1, 'MSFT']);
        $sentSubject = null;
        $mailMock = $this->createMock(MailService::class);
        $mailMock->method('send')->willReturnCallback(function ($to, $subject) use (&$sentSubject) {
            $sentSubject = $subject;
            return true;
        });
        $svc = new AlertService(new AlertRepository($this->pdo), $mailMock, new UserRepository($this->pdo), new CvsSnapshotRepository($this->pdo));

        $svc->checkAndNotify('AAPL', ['swing' => ['recommendation' => '⬆ AKUMULUJ',     'cvs' => 65.0], 'golden_signal' => null]);
        $svc->checkAndNotify('MSFT', ['swing' => ['recommendation' => '⬆⬆ SILNE KUPUJ', 'cvs' => 80.0], 'golden_signal' => 'strong']);
        $svc->flushDigests();

        $this->assertSame('CVS Alert: 2 zmian w Twojej watchliście', $sentSubject);
    }

    public function test_digest_rows_are_sorted_alphabetically(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'ZETA');
        $this->pdo->prepare('INSERT INTO watchlist (user_id, ticker) VALUES (?, ?)')->execute([1, 'ALPHA']);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $svc->checkAndNotify('ZETA',  ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $svc->checkAndNotify('ALPHA', ['swing' => ['recommendation' => '⬆ AKUMULUJ', 'cvs' => 65.0], 'golden_signal' => null]);
        $svc->flushDigests();

        $this->assertLessThan(strpos($html, 'ZETA'), strpos($html, 'ALPHA'), 'ALPHA must render before ZETA regardless of detection order');
    }

    // ------------------------------------------------------------------
    // Digest content enrichment (company name, Fund score, price/zone,
    // trend arrow, mute link, old→new signal)
    //
    // Earnings-proximity ("Wyniki za N dni") is deliberately not in the
    // digest row — it was a full label+value row in the old single-ticker
    // mail, and repeating that per row across N tickers competes with the
    // "scan in 2 seconds" goal the digest exists for. Still available on the
    // analysis page the row links to.
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

    private function sendOne(AlertService $svc, string $ticker = 'AVGO', ?string $companyName = null, ?float $price = null, ?array $zone = null): void
    {
        $svc->checkAndNotify($ticker, $this->fullResult(), $companyName, $price, $zone);
        $svc->flushDigests();
    }

    public function test_digest_includes_ticker_and_company_name(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AVGO');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $this->sendOne($svc, 'AVGO', 'Broadcom Inc.');

        $this->assertStringContainsString('AVGO', $html);
        $this->assertStringContainsString('Broadcom Inc.', $html);
    }

    public function test_digest_includes_fundamental_score(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $this->sendOne($svc, 'AAPL');

        $this->assertStringContainsString('F 61.5', $html);
    }

    public function test_digest_includes_run_date(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $this->sendOne($svc, 'AAPL');

        $this->assertStringContainsString((new \DateTimeImmutable())->format('d.m.Y'), $html);
    }

    public function test_digest_includes_price_and_zone_state(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc  = $this->makeService($mailSent, $sendCount, $html);
        $zone = ['has_zone' => true, 'zone_low' => 150.0, 'zone_high' => 160.0];
        $this->sendOne($svc, 'AAPL', null, 155.0, $zone);

        $this->assertStringContainsString('$155.00', $html);
        $this->assertStringContainsString('w strefie', $html);
    }

    public function test_digest_row_links_to_the_analysis_page(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $svc = $this->makeService($mailSent, $sendCount, $html);
        $this->sendOne($svc, 'AAPL');

        $this->assertStringContainsString('/analysis/AAPL', $html);
    }

    public function test_digest_shows_signal_old_to_new_not_just_new(): void
    {
        // Regression: the pre-enrichment mail only rendered the NEW signal, so a
        // mail triggered purely by a signal change (reco unchanged) never told the
        // user what the signal actually transitioned from.
        $this->setupUser(1, 'user@test.com', true, 'AAPL');
        $alertRepo = new AlertRepository($this->pdo);
        $alertRepo->upsertSent(1, 'AAPL', '⬆ AKUMULUJ', null);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $this->sendOne($svc, 'AAPL');

        $this->assertStringContainsString('⭐ Obserwuj', $html);
    }

    public function test_digest_shows_trend_arrow_when_history_present(): void
    {
        $this->setupUser(1, 'user@test.com', true, 'AAPL');

        // Two prior snapshots under the live model version, rising — enough
        // for a trajectory with a positive delta_daily.
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, model_version, origin) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AAPL', date('Y-m-d', strtotime('-1 day')), 60.0, '4.0', 'rescore']);
        $this->pdo->prepare(
            'INSERT INTO cvs_snapshots (ticker, score_date, cvs_swing, model_version, origin) VALUES (?, ?, ?, ?, ?)'
        )->execute(['AAPL', date('Y-m-d'), 65.0, '4.0', 'rescore']);

        $svc = $this->makeService($mailSent, $sendCount, $html);
        $this->sendOne($svc, 'AAPL');

        $this->assertStringContainsString('▲', $html);
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
        $this->assertStringContainsString('AVGO', $html);
        $this->assertStringContainsString('Broadcom Inc.', $html);
        $this->assertStringContainsString('57.9', $html);
        $this->assertStringContainsString('F 54.3', $html);
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
