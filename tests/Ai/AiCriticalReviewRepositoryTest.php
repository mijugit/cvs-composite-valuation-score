<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiCriticalReviewRepository;
use CVS\Ai\CriticalReviewProvider;
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
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker                TEXT    NOT NULL,
                provider              TEXT    NOT NULL DEFAULT \'claude\',
                status                TEXT    NOT NULL DEFAULT \'pending\',
                content               TEXT    NULL,
                sources               TEXT    NULL,
                error_message         TEXT    NULL,
                model                 TEXT    NULL,
                tokens_input          INTEGER NOT NULL DEFAULT 0,
                tokens_output         INTEGER NOT NULL DEFAULT 0,
                bull_probability      INTEGER NULL,
                bear_probability      INTEGER NULL,
                probability_rationale TEXT    NULL,
                generated_by          INTEGER NULL,
                started_at            TEXT    NOT NULL,
                generated_at          TEXT    NULL,
                UNIQUE (ticker, provider)
            )
        ');
        return $pdo;
    }

    // ------------------------------------------------------------------
    // findByTickerAndProvider / isPending
    // ------------------------------------------------------------------

    public function test_find_by_ticker_and_provider_returns_null_when_no_row_exists(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $this->assertNull($repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE));
    }

    public function test_mark_pending_then_find_by_ticker_and_provider_returns_pending_row(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('aapl', CriticalReviewProvider::CLAUDE, 7);

        $row = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE);
        $this->assertNotNull($row);
        $this->assertSame('AAPL', $row['ticker']);
        $this->assertSame('claude', $row['provider']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('7', (string) $row['generated_by']);
    }

    public function test_is_pending_true_only_while_status_is_pending(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $this->assertFalse($repo->isPending('AAPL', CriticalReviewProvider::CLAUDE));

        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $this->assertTrue($repo->isPending('AAPL', CriticalReviewProvider::CLAUDE));

        $repo->markCompleted('AAPL', CriticalReviewProvider::CLAUDE, 'text', [], 'claude-test', 10, 20, null, null, null);
        $this->assertFalse($repo->isPending('AAPL', CriticalReviewProvider::CLAUDE));
    }

    // ------------------------------------------------------------------
    // Two providers coexist independently for the same ticker
    // ------------------------------------------------------------------

    public function test_two_providers_coexist_independently_for_same_ticker(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());

        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markCompleted('AAPL', CriticalReviewProvider::CLAUDE, 'Claude narrative', [], 'claude-test', 10, 20, 60, 40, 'Claude rationale');

        // Triggering Gemini while Claude is completed must not be blocked and
        // must not disturb the Claude row (FR-002 — independent rows).
        $this->assertFalse($repo->isPending('AAPL', CriticalReviewProvider::GEMINI));
        $repo->markPending('AAPL', CriticalReviewProvider::GEMINI, 2);

        $this->assertTrue($repo->isPending('AAPL', CriticalReviewProvider::GEMINI));
        $this->assertFalse($repo->isPending('AAPL', CriticalReviewProvider::CLAUDE));

        $claudeRow = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE);
        $this->assertSame('completed', $claudeRow['status']);
        $this->assertSame('Claude narrative', $claudeRow['content']);

        $geminiRow = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::GEMINI);
        $this->assertSame('pending', $geminiRow['status']);
    }

    public function test_find_all_providers_for_ticker_returns_only_present_providers(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());

        $this->assertSame([], $repo->findAllProvidersForTicker('AAPL'));

        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $onlyClaude = $repo->findAllProvidersForTicker('AAPL');
        $this->assertCount(1, $onlyClaude);
        $this->assertArrayHasKey('claude', $onlyClaude);

        $repo->markPending('AAPL', CriticalReviewProvider::GEMINI, 1);
        $both = $repo->findAllProvidersForTicker('AAPL');
        $this->assertCount(2, $both);
        $this->assertArrayHasKey('claude', $both);
        $this->assertArrayHasKey('gemini', $both);
    }

    // ------------------------------------------------------------------
    // isFresh — only status='completed' counts, regardless of age of pending/failed
    // ------------------------------------------------------------------

    public function test_is_fresh_false_when_no_row_exists(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $this->assertFalse($repo->isFresh('AAPL', CriticalReviewProvider::CLAUDE));
    }

    public function test_is_fresh_false_while_still_pending(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $this->assertFalse($repo->isFresh('AAPL', CriticalReviewProvider::CLAUDE));
    }

    public function test_is_fresh_true_after_mark_completed(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markCompleted('AAPL', CriticalReviewProvider::CLAUDE, 'text', [], 'claude-test', 10, 20, null, null, null);
        $this->assertTrue($repo->isFresh('AAPL', CriticalReviewProvider::CLAUDE));
    }

    public function test_is_fresh_false_when_only_failed(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markFailed('AAPL', CriticalReviewProvider::CLAUDE, 'boom');
        $this->assertFalse($repo->isFresh('AAPL', CriticalReviewProvider::CLAUDE));
    }

    // ------------------------------------------------------------------
    // markPending does not clear an existing completed row's content
    // ------------------------------------------------------------------

    public function test_mark_pending_again_does_not_clear_previous_completed_content(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markCompleted('AAPL', CriticalReviewProvider::CLAUDE, 'Stara treść recenzji', [], 'claude-test', 10, 20, null, null, null);

        // A refresh starts — content must remain visible while it's in flight.
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 2);

        $row = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Stara treść recenzji', $row['content']);
        $this->assertSame('2', (string) $row['generated_by']);
    }

    // ------------------------------------------------------------------
    // markCompleted / markFailed content
    // ------------------------------------------------------------------

    public function test_mark_completed_stores_content_sources_tokens_and_probabilities(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markCompleted(
            'AAPL',
            CriticalReviewProvider::CLAUDE,
            'Recenzja krytyczna...',
            [['url' => 'https://example.com', 'title' => 'Example']],
            'claude-sonnet-4-6',
            123,
            456,
            62,
            38,
            'Krótkie uzasadnienie.'
        );

        $row = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('Recenzja krytyczna...', $row['content']);
        $this->assertSame('claude-sonnet-4-6', $row['model']);
        $this->assertSame('123', (string) $row['tokens_input']);
        $this->assertSame('456', (string) $row['tokens_output']);
        $this->assertSame('62', (string) $row['bull_probability']);
        $this->assertSame('38', (string) $row['bear_probability']);
        $this->assertSame('Krótkie uzasadnienie.', $row['probability_rationale']);
        $decoded = json_decode((string) $row['sources'], true);
        $this->assertSame('https://example.com', $decoded[0]['url']);
        $this->assertNull($row['error_message']);
    }

    public function test_mark_completed_allows_null_probabilities(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markCompleted('AAPL', CriticalReviewProvider::CLAUDE, 'text', [], 'claude-test', 10, 20, null, null, null);

        $row = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE);
        $this->assertNull($row['bull_probability']);
        $this->assertNull($row['bear_probability']);
        $this->assertNull($row['probability_rationale']);
    }

    public function test_mark_failed_stores_error_message_and_status(): void
    {
        $repo = new AiCriticalReviewRepository($this->makePdo());
        $repo->markPending('AAPL', CriticalReviewProvider::CLAUDE, 1);
        $repo->markFailed('AAPL', CriticalReviewProvider::CLAUDE, 'Brak analizy etapu 1.');

        $row = $repo->findByTickerAndProvider('AAPL', CriticalReviewProvider::CLAUDE);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Brak analizy etapu 1.', $row['error_message']);
    }
}
