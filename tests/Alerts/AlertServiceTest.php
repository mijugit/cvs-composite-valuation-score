<?php

declare(strict_types=1);

namespace CVS\Tests\Alerts;

use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Auth\UserRepository;
use CVS\Mail\MailService;
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
    }

    private function makeService(bool &$mailSent = null, int &$sendCount = null): AlertService
    {
        $mailSent  = false;
        $sendCount = 0;

        $mailMock = $this->createMock(MailService::class);
        $mailMock->method('send')->willReturnCallback(function () use (&$mailSent, &$sendCount) {
            $mailSent = true;
            $sendCount++;
            return true;
        });

        return new AlertService(
            new AlertRepository($this->pdo),
            $mailMock,
            new UserRepository($this->pdo)
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
}
