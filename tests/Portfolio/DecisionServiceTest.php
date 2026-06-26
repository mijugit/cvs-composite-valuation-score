<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Ai\ClaudeClient;
use CVS\Ai\ClaudeClientFactory;
use CVS\Ai\HttpTransport;
use CVS\Portfolio\CycleRepository;
use CVS\Portfolio\DecisionService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * FakeTransport returns pre-configured HTTP responses without hitting the network.
 * Each call pops from a queue; after the queue is exhausted, repeats the last entry.
 */
final class FakeTransport implements HttpTransport
{
    /** @var array<int, array{status: int, body: string, error: string|null}> */
    private array $queue;
    private int   $callCount = 0;

    /** @param array<int, array{status: int, body: string, error: string|null}> $responses */
    public function __construct(array $responses)
    {
        $this->queue = $responses;
    }

    public function send(string $url, string $jsonBody, array $headers, int $timeout): array
    {
        $index           = min($this->callCount, count($this->queue) - 1);
        $this->callCount++;
        return $this->queue[$index];
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}

class DecisionServiceTest extends TestCase
{
    private PDO             $db;
    private CycleRepository $cycleRepo;
    private int             $cycleId;

    /** @var array<string, mixed> */
    private array $aiConfig;

    /** @var array<string, mixed> */
    private array $portfolioConfig;

    /** @var array<string, mixed> */
    private array $portfolioState;

    /** @var array<int, array<string, mixed>> */
    private array $holdings;

    /** @var array<int, array<string, mixed>> */
    private array $screenerRows;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE rebalance_cycle (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date          TEXT    NOT NULL UNIQUE,
                status              TEXT    NOT NULL DEFAULT "started",
                started_at          TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at         TEXT,
                cash_before         REAL,
                cash_after          REAL,
                portfolio_value_usd REAL,
                executed_count      INTEGER NOT NULL DEFAULT 0,
                skipped_count       INTEGER NOT NULL DEFAULT 0,
                notes               TEXT,
                retry_count         INTEGER NOT NULL DEFAULT 0,
                llm_raw_response    TEXT,
                llm_failure_kind    TEXT,
                llm_decision_json   TEXT
            )
        ');

        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, started_at)
                         VALUES ('2026-06-26', 'started', '2026-06-26 21:30:00')");
        $this->cycleId   = (int) $this->db->lastInsertId();
        $this->cycleRepo = new CycleRepository($this->db);

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
        ];

        $this->portfolioConfig = [
            'llm' => [
                'retry_delay_seconds' => 0, // no real sleep in tests
                'system_prompt_ttl'   => '5m',
            ],
        ];

        $this->portfolioState = ['cash' => 10000.0, 'initial_capital' => 10000.0, 'updated_at' => '2026-06-26 21:30:00'];
        $this->holdings       = [];
        $this->screenerRows   = [
            ['ticker' => 'AAPL', 'cvs_swing' => 75, 'cvs_fund' => 68, 'reco_swing' => 'strong_buy', 'reco_fund' => 'accumulate', 'golden_signal' => 'strong', 'sector' => 'Technology', 'price' => 180.0],
        ];
    }

    private function validDecisionJson(): string
    {
        return '[{"action":"BUY","ticker":"AAPL","quantity":5,"reason":"Strong CVS"}]';
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function successResponse(string $text): array
    {
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage'   => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            'stop_reason' => 'end_turn',
            'model'   => 'claude-sonnet-4-6',
        ]);
        return ['status' => 200, 'body' => $body ?: '', 'error' => null];
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function timeoutResponse(): array
    {
        return ['status' => 0, 'body' => '', 'error' => 'Connection timed out'];
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function rateLimitResponse(): array
    {
        return ['status' => 429, 'body' => '{"error":{"type":"rate_limit_error","message":"Rate limited"}}', 'error' => null];
    }

    private function makeService(FakeTransport $transport): DecisionService
    {
        $client = ClaudeClientFactory::fromConfig($this->aiConfig, $transport);
        return new DecisionService($this->cycleRepo, $this->aiConfig, $this->portfolioConfig, $client);
    }

    /** @return array<string, mixed> */
    private function fetchCycle(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM rebalance_cycle WHERE id = ?');
        $stmt->execute([$this->cycleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    // --- Success on first call ---

    public function testSuccessOnFirstCallReturnsOkWithZeroRetry(): void
    {
        $transport = new FakeTransport([$this->successResponse($this->validDecisionJson())]);
        $result    = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['retryCount']);
        $this->assertCount(1, $result['decisions']);
        $this->assertSame('BUY', $result['decisions'][0]['action']);
        $this->assertNull($result['failureKind']);
        $this->assertSame(1, $transport->callCount());
    }

    // --- Retry: first timeout, second succeeds ---

    public function testTimeoutOnFirstSuccessOnSecondReturnsOkWithRetry(): void
    {
        $transport = new FakeTransport([
            $this->timeoutResponse(),
            $this->successResponse($this->validDecisionJson()),
        ]);
        $result = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['retryCount']);
        $this->assertSame(2, $transport->callCount());
    }

    // --- Retry: first parse error, second succeeds ---

    public function testParseErrorOnFirstSuccessOnSecondReturnsOkWithRetry(): void
    {
        $transport = new FakeTransport([
            $this->successResponse('not valid json at all'),
            $this->successResponse($this->validDecisionJson()),
        ]);
        $result = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['retryCount']);
        $this->assertNull($result['failureKind']); // success => no failure kind
    }

    // --- Both calls fail ---

    public function testBothCallsFailReturnsNotOk(): void
    {
        $transport = new FakeTransport([$this->timeoutResponse()]);
        $result    = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['retryCount']);
        $this->assertSame('timeout', $result['failureKind']);
        $this->assertEmpty($result['decisions']);
        $this->assertSame(2, $transport->callCount());
    }

    // --- DB audit record written exactly once, regardless of outcome ---

    public function testAuditRecordWrittenOnSuccess(): void
    {
        $transport = new FakeTransport([$this->successResponse($this->validDecisionJson())]);
        $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $cycle = $this->fetchCycle();
        $this->assertNotNull($cycle['llm_raw_response']);
        $this->assertNull($cycle['llm_failure_kind']);
        $this->assertNotNull($cycle['llm_decision_json']);
        $this->assertSame(0, (int) $cycle['retry_count']);
    }

    public function testAuditRecordWrittenOnDoubleFail(): void
    {
        $transport = new FakeTransport([$this->rateLimitResponse()]);
        $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $cycle = $this->fetchCycle();
        $this->assertSame('rate_limited', $cycle['llm_failure_kind']);
        $this->assertNull($cycle['llm_decision_json']);
        $this->assertSame(1, (int) $cycle['retry_count']);
    }

    // --- Sleep: with retry_delay>0 both attempts happen ---

    public function testRetryHappensWhenFirstFails(): void
    {
        // Service with retry_delay=0 still makes exactly 2 calls when first fails.
        $transport = new FakeTransport([
            $this->timeoutResponse(),
            $this->successResponse($this->validDecisionJson()),
        ]);
        $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows
        );

        $this->assertSame(2, $transport->callCount());
    }
}
