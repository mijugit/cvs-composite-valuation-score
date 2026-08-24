<?php

declare(strict_types=1);

namespace CVS\Tests\LlmFree;

use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiCriticalReviewRepository;
use CVS\Ai\ClaudeClientFactory;
use CVS\LlmFree\LlmFreeContextGatherer;
use CVS\Tests\Ai\FakeTransport;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

class LlmFreeContextGathererTest extends TestCase
{
    private PDO $db;

    /** @var array<string, mixed> */
    private array $aiConfig;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE ai_analyses (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker        TEXT NOT NULL UNIQUE,
                content       TEXT NOT NULL,
                model         TEXT,
                tokens_input  INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                generated_by  INTEGER,
                generated_at  TEXT NOT NULL
            )
        ');

        $this->db->exec('
            CREATE TABLE ai_critical_reviews (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker                TEXT NOT NULL,
                provider              TEXT NOT NULL DEFAULT "claude",
                status                TEXT NOT NULL DEFAULT "pending",
                content               TEXT,
                sources               TEXT,
                error_message         TEXT,
                model                 TEXT,
                tokens_input          INTEGER NOT NULL DEFAULT 0,
                tokens_output         INTEGER NOT NULL DEFAULT 0,
                bull_probability      INTEGER,
                bear_probability      INTEGER,
                probability_rationale TEXT,
                generated_by          INTEGER,
                started_at            TEXT NOT NULL,
                generated_at          TEXT,
                UNIQUE (ticker, provider)
            )
        ');

        $this->aiConfig = [
            'api_key'             => 'sk-ant-test',
            'base_url'            => 'https://api.anthropic.com/v1/messages',
            'model'               => 'claude-sonnet-4-6',
            'anthropic_version'   => '2023-06-01',
            'max_tokens'          => 2048,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
            'critical_review'     => ['timeout' => 30, 'total_timeout' => 35, 'max_retries' => 0],
        ];
    }

    private function insertFreshAnalysis(string $ticker, string $content): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO ai_analyses (ticker, content, model, generated_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ticker, $content, 'claude-sonnet-4-6', $now]);
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function searchSuccessResponse(string $text): array
    {
        $body = json_encode([
            'content'     => [['type' => 'text', 'text' => $text]],
            'usage'       => ['input_tokens' => 200, 'output_tokens' => 80, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            'stop_reason' => 'end_turn',
            'model'       => 'claude-sonnet-4-6',
        ]);
        return ['status' => 200, 'body' => $body ?: '', 'error' => null];
    }

    // --- Fresh existing analysis: no search call ---

    public function testFreshAnalysisUsedWithoutSearchCall(): void
    {
        $this->insertFreshAnalysis('AAPL', 'Apple ma silne fundamenty i rosnące marże.');

        $transport = new FakeTransport([]); // any send() call would fail — proves none happens
        $gatherer  = new LlmFreeContextGatherer(
            new AiAnalysisRepository($this->db),
            new AiCriticalReviewRepository($this->db),
            $this->aiConfig,
            3,
            ClaudeClientFactory::fromConfig($this->aiConfig, $transport)
        );

        $context = $gatherer->gather(['AAPL']);

        $this->assertArrayHasKey('AAPL', $context);
        $this->assertStringContainsString('Apple ma silne fundamenty', $context['AAPL']);
        $this->assertCount(0, $transport->requests);
    }

    // --- No fresh source, beyond cap: no context, not an error ---

    public function testTickerBeyondSearchCapGetsNoContext(): void
    {
        $transport = new FakeTransport([
            $this->searchSuccessResponse('MSFT: brak istotnych newsów w ostatnich 14 dniach.'),
        ]);
        $gatherer = new LlmFreeContextGatherer(
            new AiAnalysisRepository($this->db),
            new AiCriticalReviewRepository($this->db),
            $this->aiConfig,
            1, // cap of 1 — only the first ticker gets searched
            ClaudeClientFactory::fromConfig($this->aiConfig, $transport)
        );

        $context = $gatherer->gather(['MSFT', 'GOOG']);

        $this->assertArrayHasKey('MSFT', $context);
        $this->assertArrayNotHasKey('GOOG', $context); // beyond cap — absent, not an error
        $this->assertCount(1, $transport->requests);
    }

    // --- Search sub-calls capped at config value ---

    public function testSearchSubCallsCappedAtConfigValueEvenWithMoreCandidates(): void
    {
        $transport = new FakeTransport([
            $this->searchSuccessResponse('AAA: brak newsów.'),
            $this->searchSuccessResponse('BBB: brak newsów.'),
        ]);
        $gatherer = new LlmFreeContextGatherer(
            new AiAnalysisRepository($this->db),
            new AiCriticalReviewRepository($this->db),
            $this->aiConfig,
            2, // cap of 2
            ClaudeClientFactory::fromConfig($this->aiConfig, $transport)
        );

        $context = $gatherer->gather(['AAA', 'BBB', 'CCC', 'DDD']);

        $this->assertCount(2, $transport->requests);
        $this->assertArrayHasKey('AAA', $context);
        $this->assertArrayHasKey('BBB', $context);
        $this->assertArrayNotHasKey('CCC', $context);
        $this->assertArrayNotHasKey('DDD', $context);
    }

    // --- No candidates need search: client never constructed / called ---

    public function testAllCandidatesFreshMakesNoSearchCalls(): void
    {
        $this->insertFreshAnalysis('AAPL', 'Fresh stage-1.');
        $this->insertFreshAnalysis('MSFT', 'Fresh stage-1 too.');

        $transport = new FakeTransport([]);
        $gatherer  = new LlmFreeContextGatherer(
            new AiAnalysisRepository($this->db),
            new AiCriticalReviewRepository($this->db),
            $this->aiConfig,
            3,
            ClaudeClientFactory::fromConfig($this->aiConfig, $transport)
        );

        $context = $gatherer->gather(['AAPL', 'MSFT']);

        $this->assertCount(2, $context);
        $this->assertCount(0, $transport->requests);
    }
}
