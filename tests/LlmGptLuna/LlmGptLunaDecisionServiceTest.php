<?php

declare(strict_types=1);

namespace CVS\Tests\LlmGptLuna;

use CVS\Ai\GPTClientFactory;
use CVS\LlmGptLuna\LlmGptLunaCycleRepository;
use CVS\LlmGptLuna\LlmGptLunaDecisionService;
use CVS\Tests\Ai\FakeTransport;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LlmGptLunaDecisionServiceTest extends TestCase
{
    private PDO                       $db;
    private LlmGptLunaCycleRepository $cycleRepo;
    private int                       $cycleId;

    /** @var array<string, mixed> */
    private array $gptConfig;

    /** @var array<string, mixed> */
    private array $walletConfig;

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
            CREATE TABLE llm_gpt_luna_cycle (
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
                llm_decision_json   TEXT,
                legend              TEXT,
                tokens_input        INTEGER NOT NULL DEFAULT 0,
                tokens_output       INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->db->exec("INSERT INTO llm_gpt_luna_cycle (cycle_date, status, started_at)
                         VALUES ('2026-08-19', 'started', '2026-08-19 21:40:00')");
        $this->cycleId   = (int) $this->db->lastInsertId();
        $this->cycleRepo = new LlmGptLunaCycleRepository($this->db);

        $this->gptConfig = [
            'api_key'             => 'gpt-test-key',
            'base_url'            => 'https://api.openai.com/v1/responses',
            'model'               => 'gpt-5.6-luna',
            'max_tokens'          => 6144,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
            'reasoning_effort'    => 'medium',
        ];

        $this->walletConfig = [
            'legend_max_chars' => 4000,
            'llm' => [
                'retry_delay_seconds' => 0, // no real sleep in tests
                'system_prompt_ttl'   => '5m',
                'max_tokens'          => 6144,
            ],
        ];

        $this->portfolioState = ['cash' => 10000.0, 'initial_capital' => 10000.0, 'updated_at' => '2026-08-19 21:40:00'];
        $this->holdings       = [];
        $this->screenerRows   = [
            ['ticker' => 'AAPL', 'cvs_swing' => 75, 'cvs_fund' => 68, 'reco_swing' => 'strong_buy', 'golden_signal' => 'strong', 'sector' => 'Technology', 'price_at_snapshot' => 180.0],
        ];
    }

    private function validResponseJson(): string
    {
        return '{"decisions":[{"action":"BUY","ticker":"AAPL","quantity":5,"reason":"Strong CVS"}],"legend":"Kupuję AAPL — marże rosną, sygnał strong."}';
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function successResponse(string $text): array
    {
        $body = json_encode([
            'output' => [[
                'type'    => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage'  => ['input_tokens' => 1200, 'output_tokens' => 340],
            'status' => 'completed',
        ]);
        return ['status' => 200, 'body' => $body ?: '', 'error' => null];
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function timeoutResponse(): array
    {
        return ['status' => 0, 'body' => '', 'error' => 'Connection timed out'];
    }

    private function makeService(FakeTransport $transport): LlmGptLunaDecisionService
    {
        $client = GPTClientFactory::fromConfig($this->gptConfig, 'luna', $transport);
        return new LlmGptLunaDecisionService($this->cycleRepo, $this->gptConfig, $this->walletConfig, $client);
    }

    /** @return array<string, mixed> */
    private function fetchCycle(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM llm_gpt_luna_cycle WHERE id = ?');
        $stmt->execute([$this->cycleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    // --- Success on first call ---

    public function testSuccessOnFirstCallParsesDecisionsAndLegend(): void
    {
        $transport = new FakeTransport([$this->successResponse($this->validResponseJson())]);
        $result    = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows, []
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['retryCount']);
        $this->assertCount(1, $result['decisions']);
        $this->assertSame('BUY', $result['decisions'][0]['action']);
        $this->assertSame('Kupuję AAPL — marże rosną, sygnał strong.', $result['legend']);
        $this->assertNull($result['failureKind']);
        $this->assertCount(1, $transport->requests);
    }

    // --- Retry: first malformed, second succeeds ---

    public function testMalformedResponseOnFirstSuccessOnSecondTriggersOneRetry(): void
    {
        $transport = new FakeTransport([
            $this->successResponse('not valid json at all'),
            $this->successResponse($this->validResponseJson()),
        ]);
        $result = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows, []
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['retryCount']);
        $this->assertCount(2, $transport->requests);
        $this->assertNotNull($result['legend']);
    }

    // --- Both calls fail ---

    public function testBothCallsFailReturnsNotOkWithNullLegend(): void
    {
        $transport = new FakeTransport([$this->timeoutResponse(), $this->timeoutResponse()]);
        $result    = $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows, []
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['retryCount']);
        $this->assertSame('timeout', $result['failureKind']);
        $this->assertNull($result['legend']);
    }

    // --- Audit record: legend + token usage written ---

    public function testAuditRecordWritesLegendAndTokenUsage(): void
    {
        $transport = new FakeTransport([$this->successResponse($this->validResponseJson())]);
        $this->makeService($transport)->generate(
            $this->cycleId, $this->portfolioState, $this->holdings, $this->screenerRows, []
        );

        $cycle = $this->fetchCycle();
        $this->assertSame('Kupuję AAPL — marże rosną, sygnał strong.', $cycle['legend']);
        $this->assertSame(1200, (int) $cycle['tokens_input']);
        $this->assertSame(340, (int) $cycle['tokens_output']);
        $this->assertNull($cycle['llm_failure_kind']);
    }

    // --- System prompt content (reflection — buildSystemPrompt is private) — textually identical to the sibling ---

    public function testSystemPromptStatesNoObligationToActInWalletInterest(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildSystemPrompt');
        $prompt  = $method->invoke($service);

        $this->assertStringContainsString('NIE MASZ OBOWIĄZKU działać na korzyść portfela', $prompt);
    }

    public function testSystemPromptInstructsCriticalReExamination(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildSystemPrompt');
        $prompt  = $method->invoke($service);

        $this->assertStringContainsString('krytycznie zweryfikuj', $prompt);
    }

    public function testSystemPromptRequiresLegendEveryCycle(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildSystemPrompt');
        $prompt  = $method->invoke($service);

        $this->assertStringContainsString('NOWY wpis legendy w KAŻDYM cyklu', $prompt);
    }

    // --- Data block legend history (reflection — buildDataBlock is private) ---

    public function testDataBlockIncludesExactlyProvidedLegendHistoryEntries(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildDataBlock');

        $legendHistory = [];
        for ($i = 1; $i <= 10; $i++) {
            $legendHistory[] = ['cycle_date' => sprintf('2026-08-%02d', $i), 'legend' => "Wpis {$i}"];
        }

        $block = $method->invoke($service, $this->portfolioState, $this->holdings, $this->screenerRows, $legendHistory, []);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertStringContainsString("Wpis {$i}", $block);
        }
    }

    // --- Candidate table cap (regression: unbounded prompt hung the sibling's live cron 2026-08-07) ---

    public function testCandidateTableCappedAtConfiguredMaxSortedBySwingDesc(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildDataBlock');

        $screenerRows = [];
        for ($i = 1; $i <= 60; $i++) {
            $screenerRows[] = ['ticker' => "TICK{$i}", 'cvs_swing' => $i, 'cvs_fund' => 50, 'golden_signal' => 'strong'];
        }

        $block = $method->invoke($service, $this->portfolioState, $this->holdings, $screenerRows, [], []);

        // walletConfig in setUp has no explicit max_candidates → default (40) applies.
        $this->assertStringContainsString('TICK60', $block); // highest swing (60) — kept
        $this->assertStringContainsString('TICK21', $block); // rank 40 by swing — kept
        $this->assertStringNotContainsString('TICK20', $block); // rank 41 — dropped
        $this->assertStringContainsString('Pokazano 40 najsilniejszych', $block);
    }

    public function testCandidateTableUnderCapIncludesAllWithNoTruncationNote(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildDataBlock');

        $block = $method->invoke($service, $this->portfolioState, $this->holdings, $this->screenerRows, [], []);

        $this->assertStringNotContainsString('Pokazano', $block);
        $this->assertStringContainsString('AAPL', $block);
    }

    public function testDataBlockIncludesPerTickerContext(): void
    {
        $service = $this->makeService(new FakeTransport([$this->successResponse($this->validResponseJson())]));
        $method  = new ReflectionMethod($service, 'buildDataBlock');

        $block = $method->invoke(
            $service, $this->portfolioState, $this->holdings, $this->screenerRows, [],
            ['AAPL' => 'Świeży news: Apple ogłosił nowy produkt 2026-08-05.']
        );

        $this->assertStringContainsString('Świeży news: Apple ogłosił nowy produkt', $block);
    }
}
