<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiCriticalReviewRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AiCriticalReviewRepositoryTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ai_critical_reviews (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker        TEXT    NOT NULL UNIQUE,
                status        TEXT    NOT NULL DEFAULT \'pending\',
                content       TEXT    NULL,
                sources       TEXT    NULL,
                error_message TEXT    NULL,
                model         TEXT    NULL,
                tokens_input  INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_by  INTEGER NULL,
                started_at    TEXT    NOT NULL,
                generated_at  TEXT    NULL
            )
        ');
        return $pdo;
    }

    // ------------------------------------------------------------------
    // findByTicker / isPending
    // ------------------------------------------------------------------

    public function test_find_by_ticker_returns_null_when_no_row_exists(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $this->assertNull($repo->findByTicker('AAPL'));
    }

    public function test_mark_pending_then_find_by_ticker_returns_pending_row(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('aapl', 7);

        $row = $repo->findByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('AAPL', $row['ticker']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('7', (string) $row['generated_by']);
    }

    public function test_is_pending_true_only_while_status_is_pending(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $this->assertFalse($repo->isPending('AAPL'));

        $repo->markPending('AAPL', 1);
        $this->assertTrue($repo->isPending('AAPL'));

        $repo->markCompleted('AAPL', 'text', [], 'claude-test', 10, 20);
        $this->assertFalse($repo->isPending('AAPL'));
    }

    // ------------------------------------------------------------------
    // isFresh — only status='completed' counts, regardless of age of pending/failed
    // ------------------------------------------------------------------

    public function test_is_fresh_false_when_no_row_exists(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $this->assertFalse($repo->isFresh('AAPL'));
    }

    public function test_is_fresh_false_while_still_pending(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', 1);
        $this->assertFalse($repo->isFresh('AAPL'));
    }

    public function test_is_fresh_true_after_mark_completed(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', 1);
        $repo->markCompleted('AAPL', 'text', [], 'claude-test', 10, 20);
        $this->assertTrue($repo->isFresh('AAPL'));
    }

    public function test_is_fresh_false_when_only_failed(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', 1);
        $repo->markFailed('AAPL', 'boom');
        $this->assertFalse($repo->isFresh('AAPL'));
    }

    // ------------------------------------------------------------------
    // markPending does not clear an existing completed row's content
    // ------------------------------------------------------------------

    public function test_mark_pending_again_does_not_clear_previous_completed_content(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', 1);
        $repo->markCompleted('AAPL', 'Stara treść recenzji', [], 'claude-test', 10, 20);

        // A refresh starts — content must remain visible while it's in flight.
        $repo->markPending('AAPL', 2);

        $row = $repo->findByTicker('AAPL');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Stara treść recenzji', $row['content']);
        $this->assertSame('2', (string) $row['generated_by']);
    }

    // ------------------------------------------------------------------
    // markCompleted / markFailed content
    // ------------------------------------------------------------------

    public function test_mark_completed_stores_content_sources_and_tokens(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', 1);
        $repo->markCompleted(
            'AAPL',
            'Recenzja krytyczna...',
            [['url' => 'https://example.com', 'title' => 'Example']],
            'claude-sonnet-4-6',
            123,
            456
        );

        $row = $repo->findByTicker('AAPL');
        $this->assertSame('completed', $row['status']);
        $this->assertSame('Recenzja krytyczna...', $row['content']);
        $this->assertSame('claude-sonnet-4-6', $row['model']);
        $this->assertSame('123', (string) $row['tokens_input']);
        $this->assertSame('456', (string) $row['tokens_output']);
        $decoded = json_decode((string) $row['sources'], true);
        $this->assertSame('https://example.com', $decoded[0]['url']);
        $this->assertNull($row['error_message']);
    }

    public function test_mark_failed_stores_error_message_and_status(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', 1);
        $repo->markFailed('AAPL', 'Brak analizy etapu 1.');

        $row = $repo->findByTicker('AAPL');
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Brak analizy etapu 1.', $row['error_message']);
    }
}
