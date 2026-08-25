<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiFailureKind;
use CVS\Ai\CacheableSystem;
use CVS\Ai\GPTClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GPTClient (change: critical-review-openai) — offline via
 * FakeTransport (reused unchanged from ClaudeClientTest/GeminiClientTest —
 * same HttpTransport seam), against the OpenAI Responses API shape.
 */
class GPTClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key'             => 'sk-test-key',
            'base_url'            => 'https://api.openai.com/v1/responses',
            'model'               => 'gpt-5.6-terra',
            'max_tokens'          => 256,
            'timeout'             => 5,
            'max_retries'         => 2,
            'total_timeout'       => 25,
            'retry_base_delay_ms' => 0, // no real sleeping in tests
            'reasoning_effort'    => 'medium',
        ], $overrides);
    }

    /** @param array<string, mixed> $usage */
    private function okBody(string $text = 'analiza', array $usage = []): string
    {
        return (string) json_encode([
            'output' => [[
                'type'    => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage'  => $usage ?: ['input_tokens' => 11, 'output_tokens' => 22],
            'status' => 'completed',
            'model'  => 'gpt-5.6-terra',
        ]);
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Wyjaśnij rozjazd CVS vs analitycy.']];
    }

    public function test_success_parses_text_usage_and_metadata(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertTrue($result->ok);
        $this->assertSame('analiza', $result->text);
        $this->assertSame('completed', $result->stopReason);
        $this->assertNotNull($result->usage);
        $this->assertSame(11, $result->usage->inputTokens);
        $this->assertSame(22, $result->usage->outputTokens);
        $this->assertCount(1, $transport->requests);
    }

    public function test_sends_url_and_model_in_body(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(['base_url' => 'https://api.openai.com/v1/responses', 'model' => 'gpt-5.6-luna']), $transport);

        $client->sendMessage($this->messages());

        $this->assertSame('https://api.openai.com/v1/responses', $transport->requests[0]['url']);
        $decoded = json_decode($transport->requests[0]['body'], true);
        $this->assertSame('gpt-5.6-luna', $decoded['model']);
    }

    public function test_sends_api_key_as_bearer_header_not_query_param(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(['api_key' => 'sk-secret-key']), $transport);

        $client->sendMessage($this->messages());

        $headers = implode("\n", $transport->requests[0]['headers']);
        $this->assertStringContainsString('Authorization: Bearer sk-secret-key', $headers);
        $this->assertStringNotContainsString('sk-secret-key', $transport->requests[0]['url'], 'key must not be in URL/query string');
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        $transport = new FakeTransport([
            ['status' => 429, 'body' => '{"error":{"type":"rate_limit_exceeded","message":"rate limit"}}', 'error' => null],
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client = new GPTClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertTrue($result->ok, 'should succeed after one retry');
        $this->assertCount(2, $transport->requests, 'should have retried once');
    }

    public function test_service_unavailable_503_exhausts_retries_and_returns_typed_failure(): void
    {
        $transport = new FakeTransport([
            ['status' => 503, 'body' => '', 'error' => null],
            ['status' => 503, 'body' => '', 'error' => null],
            ['status' => 503, 'body' => '', 'error' => null],
        ]);
        $client = new GPTClient($this->config(['max_retries' => 2]), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame(AiFailureKind::Overloaded, $result->failureKind);
        $this->assertCount(3, $transport->requests, '1 initial + 2 retries');
    }

    public function test_missing_api_key_fails_fast_without_calling_transport(): void
    {
        $transport = new FakeTransport([]); // no responses scripted
        $client    = new GPTClient($this->config(['api_key' => '']), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame(AiFailureKind::Auth, $result->failureKind);
        $this->assertCount(0, $transport->requests, 'must not hit transport without a key');
    }

    // ------------------------------------------------------------------
    // Failure taxonomy (single attempt — max_retries 0)
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array{status: int, body: string, error: string|null}, 1: AiFailureKind}>
     */
    public static function failureCases(): iterable
    {
        yield 'timeout' => [['status' => 0, 'body' => '', 'error' => 'Operation timed out after 5000 ms'], AiFailureKind::Timeout];
        yield 'network' => [['status' => 0, 'body' => '', 'error' => 'Could not resolve host: api.openai.com'], AiFailureKind::Network];
        yield 'auth 401' => [['status' => 401, 'body' => '{"error":{"type":"invalid_request_error","message":"invalid key"}}', 'error' => null], AiFailureKind::Auth];
        yield 'forbidden 403' => [['status' => 403, 'body' => '{"error":{"type":"permission_denied"}}', 'error' => null], AiFailureKind::Auth];
        yield 'quota' => [['status' => 429, 'body' => '{"error":{"type":"insufficient_quota","message":"quota exceeded for this project"}}', 'error' => null], AiFailureKind::RateLimited];
        yield 'billing 400' => [['status' => 400, 'body' => '{"error":{"type":"billing_error","message":"billing hard limit reached"}}', 'error' => null], AiFailureKind::Quota];
        yield 'deadline 504' => [['status' => 504, 'body' => '', 'error' => null], AiFailureKind::Timeout];
        yield 'bad json 2xx' => [['status' => 200, 'body' => 'not-json', 'error' => null], AiFailureKind::BadResponse];
        yield 'no output' => [['status' => 200, 'body' => '{"output":[]}', 'error' => null], AiFailureKind::BadResponse];
        yield 'empty text' => [['status' => 200, 'body' => '{"output":[{"type":"message","content":[]}]}', 'error' => null], AiFailureKind::BadResponse];
    }

    /**
     * @param array{status: int, body: string, error: string|null} $response
     */
    #[DataProvider('failureCases')]
    public function test_failure_taxonomy(array $response, AiFailureKind $expected): void
    {
        $transport = new FakeTransport([$response]);
        $client    = new GPTClient($this->config(['max_retries' => 0]), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame($expected, $result->failureKind);
        $this->assertNotNull($result->failureMessage);
    }

    // ------------------------------------------------------------------
    // Request shape
    // ------------------------------------------------------------------

    public function test_system_becomes_instructions_field(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $client->sendMessage($this->messages(), new CacheableSystem('SYSTEM PROMPT', CacheableSystem::TTL_5M));

        $decoded = json_decode($transport->requests[0]['body'], true);
        $this->assertSame('SYSTEM PROMPT', $decoded['instructions']);
    }

    public function test_reasoning_effort_is_sent_from_config(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(['reasoning_effort' => 'high']), $transport);

        $client->sendMessage($this->messages());

        $decoded = json_decode($transport->requests[0]['body'], true);
        $this->assertSame('high', $decoded['reasoning']['effort']);
    }

    public function test_tools_option_is_included_in_request_body_when_provided(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $client->sendMessage($this->messages(), null, ['tools' => [['type' => 'web_search']]]);

        $this->assertStringContainsString('"web_search"', $transport->requests[0]['body']);
    }

    public function test_tools_key_absent_from_body_when_not_provided(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $client->sendMessage($this->messages());

        $this->assertStringNotContainsString('"tools"', $transport->requests[0]['body']);
    }

    public function test_max_tokens_is_clamped_to_at_least_one(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $client->sendMessage($this->messages(), null, ['max_tokens' => 0]);

        $this->assertStringContainsString('"max_output_tokens":1', $transport->requests[0]['body']);
    }

    public function test_input_is_array_of_role_content_pairs(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $client->sendMessage([
            ['role' => 'user', 'content' => 'pytanie'],
            ['role' => 'assistant', 'content' => 'odpowiedź'],
        ]);

        $decoded = json_decode($transport->requests[0]['body'], true);
        $this->assertSame('user', $decoded['input'][0]['role']);
        $this->assertSame('pytanie', $decoded['input'][0]['content']);
        $this->assertSame('assistant', $decoded['input'][1]['role']);
        $this->assertSame('odpowiedź', $decoded['input'][1]['content']);
    }

    // ------------------------------------------------------------------
    // Web search citations (url_citation annotations)
    // ------------------------------------------------------------------

    public function test_url_citations_are_collected_and_deduplicated_by_url(): void
    {
        $body = (string) json_encode([
            'output' => [[
                'type'    => 'message',
                'content' => [[
                    'type'        => 'output_text',
                    'text'        => 'Fakt z cytatem.',
                    'annotations' => [
                        ['type' => 'url_citation', 'url' => 'https://example.com/a', 'title' => 'Źródło A'],
                        ['type' => 'url_citation', 'url' => 'https://example.com/a', 'title' => 'Źródło A'],
                        ['type' => 'url_citation', 'url' => 'https://example.com/b', 'title' => 'Źródło B'],
                    ],
                ]],
            ]],
            'usage'  => ['input_tokens' => 5, 'output_tokens' => 5],
            'status' => 'completed',
        ]);
        $transport = new FakeTransport([['status' => 200, 'body' => $body, 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages(), null, ['tools' => [['type' => 'web_search']]]);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->citations, 'duplicate URL must be deduplicated');
        $urls = array_column($result->citations, 'url');
        $this->assertContains('https://example.com/a', $urls);
        $this->assertContains('https://example.com/b', $urls);
    }

    public function test_non_message_output_items_are_skipped(): void
    {
        $body = (string) json_encode([
            'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Odpowiedź.']]],
            ],
            'usage'  => ['input_tokens' => 5, 'output_tokens' => 5],
            'status' => 'completed',
        ]);
        $transport = new FakeTransport([['status' => 200, 'body' => $body, 'error' => null]]);
        $client    = new GPTClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertTrue($result->ok);
        $this->assertSame('Odpowiedź.', $result->text);
    }

    // ------------------------------------------------------------------
    // Secret redaction guardrail
    // ------------------------------------------------------------------

    public function test_api_key_never_leaks_to_logs_or_result_or_body(): void
    {
        $key     = 'sk-secret-do-not-log';
        $logFile = (string) tempnam(sys_get_temp_dir(), 'ai_log_');
        $old     = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $transport = new FakeTransport([['status' => 500, 'body' => '{"error":{"type":"server_error"}}', 'error' => null]]);
            $client    = new GPTClient($this->config(['api_key' => $key, 'max_retries' => 0]), $transport);

            $result = $client->sendMessage($this->messages());

            $logged = (string) file_get_contents($logFile);

            $this->assertFalse($result->ok);
            $this->assertStringNotContainsString($key, $logged, 'API key must never be logged');
            $this->assertStringNotContainsString($key, (string) json_encode($result->toArray()), 'API key must never be on the result');
            $this->assertStringNotContainsString($key, $transport->requests[0]['body'], 'API key must not be in the request body');
            // Sanity: the key DID travel — only ever in the Authorization header.
            $this->assertStringContainsString($key, implode("\n", $transport->requests[0]['headers']));
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
            @unlink($logFile);
        }
    }
}
