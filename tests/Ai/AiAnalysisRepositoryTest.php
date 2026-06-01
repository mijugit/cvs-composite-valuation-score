<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiAnalysisRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AiAnalysisRepositoryTest extends TestCase
{
    private function makeRepo(): AiAnalysisRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ai_analyses (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker        TEXT    NOT NULL UNIQUE,
                content       TEXT    NOT NULL,
                model         TEXT    NULL,
                tokens_input  INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_by  INTEGER NULL,
                generated_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        return new AiAnalysisRepository($pdo);
    }

    public function test_find_by_ticker_returns_null_when_none(): void
    {
        $repo = $this->makeRepo();
        $this->assertNull($repo->findByTicker('AAPL'));
    }

    public function test_save_and_find(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', 'Narracja testowa', 'claude-test', 100, 200, 1);

        $row = $repo->findByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('AAPL', $row['ticker']);
        $this->assertSame('Narracja testowa', $row['content']);
        $this->assertSame(100, (int) $row['tokens_input']);
    }

    public function test_save_is_idempotent_overwrites_on_duplicate(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', 'Pierwsza', 'm1', 10, 20, 1);
        $repo->save('AAPL', 'Druga', 'm2', 30, 40, 1);

        $row = $repo->findByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('Druga', $row['content']);
        $this->assertSame(30, (int) $row['tokens_input']);
    }

    public function test_is_fresh_returns_false_when_no_analysis(): void
    {
        $repo = $this->makeRepo();
        $this->assertFalse($repo->isFresh('AAPL', 7));
    }

    public function test_is_fresh_returns_true_for_recent_analysis(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', 'content', 'm', 10, 20, 1);

        $this->assertTrue($repo->isFresh('AAPL', 7));
    }

    public function test_is_fresh_returns_false_for_old_analysis(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ai_analyses (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL UNIQUE,
                content TEXT NOT NULL, model TEXT NULL,
                tokens_input INTEGER NOT NULL DEFAULT 0, tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_by INTEGER NULL, generated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        // Insert with old date (10 days ago)
        $pdo->exec("INSERT INTO ai_analyses (ticker, content, model, generated_at)
            VALUES ('MSFT', 'old', 'm', datetime('now', '-10 days'))");

        $repo = new AiAnalysisRepository($pdo);
        $this->assertFalse($repo->isFresh('MSFT', 7));
    }

    public function test_needs_refresh_returns_false_when_no_analysis(): void
    {
        $repo = $this->makeRepo();
        $this->assertFalse($repo->needsRefresh('AAPL', 24));
    }

    public function test_needs_refresh_returns_false_for_very_recent_analysis(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', 'content', 'm', 10, 20, 1);

        // Analysis just created — less than 24h old → no refresh needed
        $this->assertFalse($repo->needsRefresh('AAPL', 24));
    }

    public function test_needs_refresh_returns_true_for_old_analysis(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ai_analyses (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL UNIQUE,
                content TEXT NOT NULL, model TEXT NULL,
                tokens_input INTEGER NOT NULL DEFAULT 0, tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_by INTEGER NULL, generated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        $pdo->exec("INSERT INTO ai_analyses (ticker, content, model, generated_at)
            VALUES ('NVDA', 'old', 'm', datetime('now', '-30 hours'))");

        $repo = new AiAnalysisRepository($pdo);
        $this->assertTrue($repo->needsRefresh('NVDA', 24));
    }

    public function test_ticker_normalized_to_uppercase(): void
    {
        $repo = $this->makeRepo();
        $repo->save('aapl', 'content', 'm', 10, 20, null);

        $this->assertNotNull($repo->findByTicker('AAPL'));
        $this->assertTrue($repo->isFresh('aapl', 7));
    }
}
