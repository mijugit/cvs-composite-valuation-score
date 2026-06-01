<?php

declare(strict_types=1);

namespace CVS\Tests\Pro;

use CVS\Pro\AiUsageRepository;
use CVS\Pro\ProGate;
use CVS\Pro\ProRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ProGateTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE pro_codes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                code        TEXT    NOT NULL UNIQUE,
                user_id     INTEGER NULL,
                description TEXT    NULL,
                is_active   INTEGER NOT NULL DEFAULT 1,
                created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        $this->pdo->exec('
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)
        ');
        $this->pdo->exec('
            CREATE TABLE ai_usage_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                pro_code      TEXT    NOT NULL,
                tokens_input  INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');

        // Reset session for each test
        $_SESSION = [];
    }

    private function makeGate(int $dailyLimit = 10, int $monthlyLimit = 100): ProGate
    {
        return new ProGate(
            new ProRepository($this->pdo),
            new AiUsageRepository($this->pdo),
            ['pro' => ['daily_limit' => $dailyLimit, 'monthly_limit' => $monthlyLimit]]
        );
    }

    public function test_can_generate_returns_false_without_session_code(): void
    {
        $gate = $this->makeGate();
        $this->assertFalse($gate->canGenerate(1));
    }

    public function test_activate_code_returns_false_for_invalid_code(): void
    {
        $gate = $this->makeGate();
        $this->assertFalse($gate->activateCode('BAD', 1));
        $this->assertSame('', $gate->getSessionCode());
    }

    public function test_activate_code_sets_session_for_valid_global_code(): void
    {
        $gate  = $this->makeGate();
        $repo  = new ProRepository($this->pdo);
        $repo->create('VALID', null, '');

        $this->assertTrue($gate->activateCode('VALID', 1));
        $this->assertSame('VALID', $gate->getSessionCode());
    }

    public function test_can_generate_true_after_activation(): void
    {
        $gate = $this->makeGate();
        $repo = new ProRepository($this->pdo);
        $repo->create('CODE', null, '');

        $gate->activateCode('CODE', 1);
        $this->assertTrue($gate->canGenerate(1));
    }

    public function test_can_generate_false_when_daily_limit_reached(): void
    {
        $gate  = $this->makeGate(dailyLimit: 2);
        $repo  = new ProRepository($this->pdo);
        $usage = new AiUsageRepository($this->pdo);
        $repo->create('CODE', null, '');

        $gate->activateCode('CODE', 1);
        $usage->log(1, 'CODE', 10, 20);
        $usage->log(1, 'CODE', 10, 20);

        $this->assertFalse($gate->canGenerate(1));
    }

    public function test_get_usage_returns_correct_counts(): void
    {
        $gate  = $this->makeGate(dailyLimit: 5, monthlyLimit: 50);
        $usage = new AiUsageRepository($this->pdo);
        $usage->log(1, 'CODE', 10, 20);

        $u = $gate->getUsage(1);
        $this->assertSame(1, $u['today']);
        $this->assertSame(1, $u['month']);
        $this->assertSame(5, $u['daily_limit']);
        $this->assertSame(50, $u['monthly_limit']);
    }

    public function test_activate_empty_code_returns_false(): void
    {
        $gate = $this->makeGate();
        $this->assertFalse($gate->activateCode('', 1));
    }
}
