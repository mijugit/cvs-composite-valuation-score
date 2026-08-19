<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiFailureKind;
use CVS\Ai\CacheableSystem;
use CVS\Ai\GeminiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GeminiClient (change: llm-gemini-wallet) — offline via
 * FakeTransport (reused unchanged from ClaudeClientTest — same HttpTransport seam).
 */
class GeminiClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key'             => 'gm-test-key',
            'base_url'            => 'https://generativelanguage.googleapis.com/v1beta',
            'model'               => 'gemini-test',
            'max_tokens'          => 256,
            'timeout'             => 5,
            'max_retries'         => 2,
            'total_timeout'       => 25,
            'retry_base_delay_ms' => 0, // no real sleeping in tests
        ], $overrides);
    }

    /** @param array<string, mixed> $usage */
    private function okBody(string $text = 'analiza', array $usage = []): string
    {
        return (string) json_encode([
            'candidates' => [[
                'content'      => ['parts' => [['text' => $text]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => $usage ?: ['promptTokenCount' => 11, 'candidatesTokenCount' => 22],
            'modelVersion'  => 'gemini-test',
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
        $client    = new GeminiClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertTrue($result->ok);
        $this->assertSame('analiza', $result->text);
        $this->assertSame('STOP', $result->stopReason);
        $this->assertNotNull($result->usage);
        $this->assertSame(11, $result->usage->inputTokens);
        $this->assertSame(22, $result->usage->outputTokens);
        $this->assertCount(1, $transport->requests);
    }

    public function test_builds_url_with_model_in_path(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(['base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.7-flash']), $transport);

        $client->sendMessage($this->messages());

        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent',
            $transport->requests[0]['url']
        );
    }

    public function test_sends_api_key_as_header_not_query_param(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(['api_key' => 'gm-secret-key']), $transport);

        $client->sendMessage($this->messages());

        $headers = implode("\n", $transport->requests[0]['headers']);
        $this->assertStringContainsString('x-goog-api-key: gm-secret-key', $headers);
        $this->assertStringNotContainsString('gm-secret-key', $transport->requests[0]['url'], 'key must not be in URL/query string');
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        $transport = new FakeTransport([
            ['status' => 429, 'body' => '{"error":{"status":"RESOURCE_EXHAUSTED","message":"rate limit"}}', 'error' => null],
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client = new GeminiClient($this->config(), $transport);

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
        $client = new GeminiClient($this->config(['max_retries' => 2]), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame(AiFailureKind::Overloaded, $result->failureKind);
        $this->assertCount(3, $transport->requests, '1 initial + 2 retries');
    }

    public function test_missing_api_key_fails_fast_without_calling_transport(): void
    {
        $transport = new FakeTransport([]); // no responses scripted
        $client    = new GeminiClient($this->config(['api_key' => '']), $transport);

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
        yield 'network' => [['status' => 0, 'body' => '', 'error' => 'Could not resolve host: generativelanguage.googleapis.com'], AiFailureKind::Network];
        yield 'auth 401' => [['status' => 401, 'body' => '{"error":{"status":"UNAUTHENTICATED","message":"invalid key"}}', 'error' => null], AiFailureKind::Auth];
        yield 'permission 403' => [['status' => 403, 'body' => '{"error":{"status":"PERMISSION_DENIED"}}', 'error' => null], AiFailureKind::Auth];
        yield 'quota' => [['status' => 400, 'body' => '{"error":{"status":"FAILED_PRECONDITION","message":"quota exceeded for this project"}}', 'error' => null], AiFailureKind::Quota];
        yield 'deadline 504' => [['status' => 504, 'body' => '', 'error' => null], AiFailureKind::Timeout];
        yield 'bad json 2xx' => [['status' => 200, 'body' => 'not-json', 'error' => null], AiFailureKind::BadResponse];
        yield 'no candidates' => [['status' => 200, 'body' => '{"candidates":[]}', 'error' => null], AiFailureKind::BadResponse];
        yield 'empty text' => [['status' => 200, 'body' => '{"candidates":[{"content":{"parts":[]},"finishReason":"STOP"}]}', 'error' => null], AiFailureKind::BadResponse];
    }

    /**
     * @param array{status: int, body: string, error: string|null} $response
     */
    #[DataProvider('failureCases')]
    public function test_failure_taxonomy(array $response, AiFailureKind $expected): void
    {
        $transport = new FakeTransport([$response]);
        $client    = new GeminiClient($this->config(['max_retries' => 0]), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame($expected, $result->failureKind);
        $this->assertNotNull($result->failureMessage);
    }

    // ------------------------------------------------------------------
    // Request shape
    // ------------------------------------------------------------------

    public function test_system_instruction_is_sent_and_ttl_is_ignored(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(), $transport);

        $client->sendMessage($this->messages(), new CacheableSystem('SYSTEM PROMPT', CacheableSystem::TTL_5M));

        $body = $transport->requests[0]['body'];
        $this->assertStringContainsString('"systemInstruction"', $body);
        $this->assertStringContainsString('SYSTEM PROMPT', $body);
        $this->assertStringNotContainsString('cache_control', $body, 'Gemini has no cache_control equivalent');
    }

    public function test_tools_option_is_included_in_request_body_when_provided(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(), $transport);

        $client->sendMessage($this->messages(), null, ['tools' => [['googleSearch' => new \stdClass()]]]);

        $this->assertStringContainsString('"googleSearch"', $transport->requests[0]['body']);
    }

    public function test_tools_key_absent_from_body_when_not_provided(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(), $transport);

        $client->sendMessage($this->messages());

        $this->assertStringNotContainsString('"tools"', $transport->requests[0]['body']);
    }

    public function test_max_tokens_is_clamped_to_at_least_one(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(), $transport);

        $client->sendMessage($this->messages(), null, ['max_tokens' => 0]);

        $this->assertStringContainsString('"maxOutputTokens":1', $transport->requests[0]['body']);
    }

    public function test_assistant_role_is_mapped_to_model(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->config(), $transport);

        $client->sendMessage([
            ['role' => 'user', 'content' => 'pytanie'],
            ['role' => 'assistant', 'content' => 'odpowiedź'],
        ]);

        $decoded = json_decode($transport->requests[0]['body'], true);
        $this->assertSame('user', $decoded['contents'][0]['role']);
        $this->assertSame('model', $decoded['contents'][1]['role']);
    }

    // ------------------------------------------------------------------
    // Grounding citations (googleSearch)
    // ------------------------------------------------------------------

    public function test_grounding_citations_are_collected_and_deduplicated_by_url(): void
    {
        $body = (string) json_encode([
            'candidates' => [[
                'content'      => ['parts' => [['text' => 'Fakt z cytatem.']]],
                'finishReason' => 'STOP',
                'groundingMetadata' => [
                    'groundingChunks' => [
                        ['web' => ['uri' => 'https://example.com/a', 'title' => 'Źródło A']],
                        ['web' => ['uri' => 'https://example.com/a', 'title' => 'Źródło A']],
                        ['web' => ['uri' => 'https://example.com/b', 'title' => 'Źródło B']],
                    ],
                ],
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 5],
        ]);
        $transport = new FakeTransport([['status' => 200, 'body' => $body, 'error' => null]]);
        $client    = new GeminiClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages(), null, ['tools' => [['googleSearch' => new \stdClass()]]]);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->citations, 'duplicate URL must be deduplicated');
        $urls = array_column($result->citations, 'url');
        $this->assertContains('https://example.com/a', $urls);
        $this->assertContains('https://example.com/b', $urls);
    }

    // ------------------------------------------------------------------
    // Secret redaction guardrail
    // ------------------------------------------------------------------

    public function test_api_key_never_leaks_to_logs_or_result_or_body(): void
    {
        $key     = 'gm-secret-do-not-log';
        $logFile = (string) tempnam(sys_get_temp_dir(), 'ai_log_');
        $old     = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $transport = new FakeTransport([['status' => 500, 'body' => '{"error":{"status":"INTERNAL"}}', 'error' => null]]);
            $client    = new GeminiClient($this->config(['api_key' => $key, 'max_retries' => 0]), $transport);

            $result = $client->sendMessage($this->messages());

            $logged = (string) file_get_contents($logFile);

            $this->assertFalse($result->ok);
            $this->assertStringNotContainsString($key, $logged, 'API key must never be logged');
            $this->assertStringNotContainsString($key, (string) json_encode($result->toArray()), 'API key must never be on the result');
            $this->assertStringNotContainsString($key, $transport->requests[0]['body'], 'API key must not be in the request body');
            // Sanity: the key DID travel — only ever in the x-goog-api-key header.
            $this->assertStringContainsString($key, implode("\n", $transport->requests[0]['headers']));
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
            @unlink($logFile);
        }
    }
}
