<?php

declare(strict_types=1);

namespace CVS\Tests\Alerts;

use CVS\Alerts\AlertRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AlertRepositoryTest extends TestCase
{
    private function makeRepo(): AlertRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE user_alert_settings (
                user_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                PRIMARY KEY (user_id)
            )
        ');
        $pdo->exec('
            CREATE TABLE user_alert_ticker (
                user_id INTEGER NOT NULL, ticker TEXT NOT NULL,
                disabled INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (user_id, ticker)
            )
        ');
        $pdo->exec('
            CREATE TABLE alert_sent (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, ticker TEXT NOT NULL,
                last_reco TEXT NULL, last_signal TEXT NULL,
                sent_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (user_id, ticker)
            )
        ');
        $pdo->exec('
            CREATE TABLE watchlist (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, ticker TEXT NOT NULL,
                added_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (user_id, ticker)
            )
        ');

        return new AlertRepository($pdo);
    }

    // ------------------------------------------------------------------
    // Global preference
    // ------------------------------------------------------------------

    public function test_default_disabled(): void
    {
        $repo = $this->makeRepo();
        $this->assertFalse($repo->isGlobalEnabled(1));
    }

    public function test_enable_toggle(): void
    {
        $repo = $this->makeRepo();
        $repo->setGlobalEnabled(1, true);
        $this->assertTrue($repo->isGlobalEnabled(1));

        $repo->setGlobalEnabled(1, false);
        $this->assertFalse($repo->isGlobalEnabled(1));
    }

    public function test_enable_idempotent(): void
    {
        $repo = $this->makeRepo();
        $repo->setGlobalEnabled(1, true);
        $repo->setGlobalEnabled(1, true); // second call — must not throw
        $this->assertTrue($repo->isGlobalEnabled(1));
    }

    // ------------------------------------------------------------------
    // Per-ticker
    // ------------------------------------------------------------------

    public function test_ticker_not_disabled_by_default(): void
    {
        $repo = $this->makeRepo();
        $this->assertFalse($repo->isTickerDisabled(1, 'AAPL'));
    }

    public function test_ticker_disabled(): void
    {
        $repo = $this->makeRepo();
        $repo->setTickerDisabled(1, 'AAPL', true);
        $this->assertTrue($repo->isTickerDisabled(1, 'AAPL'));
    }

    public function test_ticker_re_enabled(): void
    {
        $repo = $this->makeRepo();
        $repo->setTickerDisabled(1, 'AAPL', true);
        $repo->setTickerDisabled(1, 'AAPL', false);
        $this->assertFalse($repo->isTickerDisabled(1, 'AAPL'));
    }

    // ------------------------------------------------------------------
    // Deduplication
    // ------------------------------------------------------------------

    public function test_get_last_sent_null_when_never_alerted(): void
    {
        $repo = $this->makeRepo();
        $this->assertNull($repo->getLastSent(1, 'AAPL'));
    }

    public function test_upsert_and_get_last_sent(): void
    {
        $repo = $this->makeRepo();
        $repo->upsertSent(1, 'AAPL', '⬆ AKUMULUJ', 'strong');

        $last = $repo->getLastSent(1, 'AAPL');
        $this->assertNotNull($last);
        $this->assertSame('⬆ AKUMULUJ', $last['last_reco']);
        $this->assertSame('strong', $last['last_signal']);
    }

    public function test_upsert_overwrites_on_duplicate(): void
    {
        $repo = $this->makeRepo();
        $repo->upsertSent(1, 'AAPL', '→ NEUTRALNIE', null);
        $repo->upsertSent(1, 'AAPL', '⬆⬆ SILNE KUPUJ', 'strong');

        $last = $repo->getLastSent(1, 'AAPL');
        $this->assertSame('⬆⬆ SILNE KUPUJ', $last['last_reco']);
    }

    // ------------------------------------------------------------------
    // User discovery
    // ------------------------------------------------------------------

    public function test_find_users_watching_ticker(): void
    {
        $repo = $this->makeRepo();
        $pdo  = new \ReflectionProperty(AlertRepository::class, 'db');
        $pdo->setAccessible(true);
        $db = $pdo->getValue($repo);

        // User 1: alerts ON, watches AAPL
        $repo->setGlobalEnabled(1, true);
        $db->exec("INSERT INTO watchlist (user_id, ticker) VALUES (1, 'AAPL')");

        // User 2: alerts OFF, watches AAPL
        $db->exec("INSERT INTO watchlist (user_id, ticker) VALUES (2, 'AAPL')");

        $users = $repo->findUsersWatchingTicker('AAPL');
        $this->assertSame([1], $users);
    }
}
