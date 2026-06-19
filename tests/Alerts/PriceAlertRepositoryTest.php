<?php

declare(strict_types=1);

namespace CVS\Tests\Alerts;

use CVS\Alerts\PriceAlertRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PriceAlertRepository using SQLite in-memory (Phase 8, slice 3).
 */
class PriceAlertRepositoryTest extends TestCase
{
    private function makeRepo(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE ticker_zone (
                ticker TEXT PRIMARY KEY, zone_low REAL, zone_high REAL,
                stop_swing REAL, stop_fund REAL, fx_rate_to_usd REAL,
                source TEXT, computed_at TEXT NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE price_alert (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, ticker TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                last_state TEXT NULL, last_sent_at TEXT NULL,
                UNIQUE (user_id, ticker)
            )
        ');
        $pdo->exec('CREATE TABLE user_alert_settings (user_id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0)');

        return [new PriceAlertRepository($pdo), $pdo];
    }

    /** @return array{zone_low: float, zone_high: float, stop_swing: float, stop_fund: float, source: string} */
    private function zone(): array
    {
        return ['zone_low' => 99.0, 'zone_high' => 101.0, 'stop_swing' => 96.0, 'stop_fund' => 93.0, 'source' => 'support'];
    }

    public function test_upsert_zone_inserts_and_reads(): void
    {
        [$repo, ] = $this->makeRepo();
        $repo->upsertZone('AAPL', $this->zone(), 1.0);

        $row = $repo->findZone('AAPL');
        $this->assertNotNull($row);
        $this->assertEquals(99.0, (float) $row['zone_low']);
        $this->assertEquals(101.0, (float) $row['zone_high']);
        $this->assertSame('support', $row['source']);
    }

    public function test_upsert_zone_updates_in_place(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $repo->upsertZone('AAPL', $this->zone(), 1.0);
        $updated = $this->zone();
        $updated['zone_low'] = 105.0;
        $repo->upsertZone('AAPL', $updated, 1.0);

        $row = $repo->findZone('AAPL');
        $this->assertEquals(105.0, (float) $row['zone_low']);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM ticker_zone')->fetchColumn();
        $this->assertSame(1, $count, 'upsert must not duplicate the ticker row');
    }

    public function test_find_zone_null_for_unknown(): void
    {
        [$repo, ] = $this->makeRepo();
        $this->assertNull($repo->findZone('NONE'));
    }

    public function test_enable_disable_keeps_row(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $repo->setEnabled(1, 'AAPL', true);
        $this->assertTrue($repo->isEnabled(1, 'AAPL'));

        $repo->setEnabled(1, 'AAPL', false);
        $this->assertFalse($repo->isEnabled(1, 'AAPL'));
        $count = (int) $pdo->query('SELECT COUNT(*) FROM price_alert')->fetchColumn();
        $this->assertSame(1, $count, 'disabling keeps the row (preserves hysteresis state)');
    }

    public function test_find_active_alerts_requires_global_on(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        // user 1: global ON + alert enabled → active
        $pdo->exec('INSERT INTO user_alert_settings (user_id, enabled) VALUES (1, 1)');
        $repo->setEnabled(1, 'AAPL', true);
        // user 2: alert enabled but global OFF → excluded
        $pdo->exec('INSERT INTO user_alert_settings (user_id, enabled) VALUES (2, 0)');
        $repo->setEnabled(2, 'MSFT', true);
        // user 3: global ON but alert disabled → excluded
        $pdo->exec('INSERT INTO user_alert_settings (user_id, enabled) VALUES (3, 1)');
        $repo->setEnabled(3, 'NVDA', false);

        $active = $repo->findActiveAlerts();
        $this->assertCount(1, $active);
        $this->assertSame(1, $active[0]['user_id']);
        $this->assertSame('AAPL', $active[0]['ticker']);
    }

    public function test_update_state_sets_state_and_sent(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $repo->setEnabled(1, 'AAPL', true);

        $repo->updateState(1, 'AAPL', 'in', true);
        $row = $pdo->query("SELECT last_state, last_sent_at FROM price_alert WHERE user_id=1 AND ticker='AAPL'")->fetch();
        $this->assertSame('in', $row['last_state']);
        $this->assertNotNull($row['last_sent_at']);

        // state-only update (no send) leaves last_sent_at intact relative to value change
        $repo->updateState(1, 'AAPL', 'out', false);
        $row2 = $pdo->query("SELECT last_state FROM price_alert WHERE user_id=1 AND ticker='AAPL'")->fetch();
        $this->assertSame('out', $row2['last_state']);
    }
}
