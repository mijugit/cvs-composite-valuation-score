<?php

declare(strict_types=1);

namespace CVS\Tests\Pro;

use CVS\Pro\AiUsageRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AiUsageRepositoryTest extends TestCase
{
    private function makeRepo(): AiUsageRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ai_usage_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                pro_code      TEXT    NOT NULL,
                tokens_input  INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        return new AiUsageRepository($pdo);
    }

    public function test_count_today_zero_for_new_user(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame(0, $repo->countToday(1));
    }

    public function test_count_today_reflects_todays_logs(): void
    {
        $repo = $this->makeRepo();
        $repo->log(1, 'CODE', 100, 200);
        $repo->log(1, 'CODE', 50, 80);

        $this->assertSame(2, $repo->countToday(1));
    }

    public function test_count_today_scoped_to_user(): void
    {
        $repo = $this->makeRepo();
        $repo->log(1, 'CODE', 100, 200);
        $repo->log(2, 'CODE', 100, 200);

        $this->assertSame(1, $repo->countToday(1));
        $this->assertSame(1, $repo->countToday(2));
    }

    public function test_count_this_month_includes_todays_logs(): void
    {
        $repo = $this->makeRepo();
        $repo->log(1, 'CODE', 100, 200);

        $this->assertSame(1, $repo->countThisMonth(1));
    }

    public function test_find_by_user_returns_latest_first(): void
    {
        $repo = $this->makeRepo();
        $repo->log(1, 'A', 10, 20);
        $repo->log(1, 'B', 30, 40);

        $rows = $repo->findByUser(1);
        $this->assertCount(2, $rows);
    }
}
