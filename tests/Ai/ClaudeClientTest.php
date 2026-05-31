<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\ClaudeClient;
use CVS\Ai\AiFailureKind;
use CVS\Ai\CacheableSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ClaudeClient (phase 2) — offline via FakeTransport.
 *
 * The full guardrail matrix (every failure kind, key redaction, cache headers)
 * is expanded in phase 3; this covers the core contract.
 */
class ClaudeClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key'             => 'sk-ant-test-key',
            'base_url'            => 'https://api.anthropic.com/v1/messages',
            'model'               => 'claude-test',
            'anthropic_version'   => '2023-06-01',
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
            'content'     => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'model'       => 'claude-test',
            'usage'       => $usage ?: ['input_tokens' => 11, 'output_tokens' => 22],
        ]);
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Wyjaśnij rozjazd CVS vs analitycy.']];
    }

    public function test_success_parses_text_usage_and_metadata(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertTrue($result->ok);
        $this->assertSame('analiza', $result->text);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertNotNull($result->usage);
        $this->assertSame(11, $result->usage->inputTokens);
        $this->assertSame(22, $result->usage->outputTokens);
        $this->assertCount(1, $transport->requests);
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        $transport = new FakeTransport([
            ['status' => 429, 'body' => '{"error":{"type":"rate_limit_error"}}', 'error' => null],
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client = new ClaudeClient($this->config(), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertTrue($result->ok, 'should succeed after one retry');
        $this->assertCount(2, $transport->requests, 'should have retried once');
    }

    public function test_overloaded_529_exhausts_retries_and_returns_typed_failure(): void
    {
        $transport = new FakeTransport([
            ['status' => 529, 'body' => '', 'error' => null],
            ['status' => 529, 'body' => '', 'error' => null],
            ['status' => 529, 'body' => '', 'error' => null],
        ]);
        $client = new ClaudeClient($this->config(['max_retries' => 2]), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame(AiFailureKind::Overloaded, $result->failureKind);
        $this->assertCount(3, $transport->requests, '1 initial + 2 retries');
    }

    public function test_missing_api_key_fails_fast_without_calling_transport(): void
    {
        $transport = new FakeTransport([]); // no responses scripted
        $client    = new ClaudeClient($this->config(['api_key' => '']), $transport);

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
        yield 'network' => [['status' => 0, 'body' => '', 'error' => 'Could not resolve host: api.anthropic.com'], AiFailureKind::Network];
        yield 'auth 401' => [['status' => 401, 'body' => '{"error":{"type":"authentication_error"}}', 'error' => null], AiFailureKind::Auth];
        yield 'quota' => [['status' => 400, 'body' => '{"error":{"type":"invalid_request_error","message":"Your credit balance is too low"}}', 'error' => null], AiFailureKind::Quota];
        yield 'bad json 2xx' => [['status' => 200, 'body' => 'not-json', 'error' => null], AiFailureKind::BadResponse];
        yield 'empty content' => [['status' => 200, 'body' => '{"content":[],"usage":{}}', 'error' => null], AiFailureKind::BadResponse];
    }

    /**
     * @param array{status: int, body: string, error: string|null} $response
     */
    #[DataProvider('failureCases')]
    public function test_failure_taxonomy(array $response, AiFailureKind $expected): void
    {
        $transport = new FakeTransport([$response]);
        $client    = new ClaudeClient($this->config(['max_retries' => 0]), $transport);

        $result = $client->sendMessage($this->messages());

        $this->assertFalse($result->ok);
        $this->assertSame($expected, $result->failureKind);
        $this->assertNotNull($result->failureMessage);
    }

    // ------------------------------------------------------------------
    // Prompt caching surface
    // ------------------------------------------------------------------

    public function test_cache_control_5m_sets_block_without_beta_header(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);

        $client->sendMessage($this->messages(), new CacheableSystem('SYSTEM PROMPT', CacheableSystem::TTL_5M));

        $body    = $transport->requests[0]['body'];
        $headers = implode("\n", $transport->requests[0]['headers']);

        $this->assertStringContainsString('cache_control', $body);
        $this->assertStringContainsString('"ttl":"5m"', $body);
        $this->assertStringNotContainsString('anthropic-beta', $headers, '5m caching is GA — no beta header');
    }

    public function test_cache_control_1h_sets_extended_ttl_beta_header(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);

        $client->sendMessage($this->messages(), new CacheableSystem('SYSTEM PROMPT', CacheableSystem::TTL_1H));

        $body    = $transport->requests[0]['body'];
        $headers = implode("\n", $transport->requests[0]['headers']);

        $this->assertStringContainsString('"ttl":"1h"', $body);
        $this->assertStringContainsString('anthropic-beta: extended-cache-ttl-2025-04-11', $headers);
    }

    // ------------------------------------------------------------------
    // Secret redaction guardrail
    // ------------------------------------------------------------------

    public function test_api_key_never_leaks_to_logs_or_result_or_body(): void
    {
        $key     = 'sk-ant-secret-do-not-log';
        $logFile = (string) tempnam(sys_get_temp_dir(), 'ai_log_');
        $old     = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            // 500 is retryable; with max_retries 0 it logs once and fails.
            $transport = new FakeTransport([['status' => 500, 'body' => '{"error":{"type":"api_error"}}', 'error' => null]]);
            $client    = new ClaudeClient($this->config(['api_key' => $key, 'max_retries' => 0]), $transport);

            $result = $client->sendMessage($this->messages());

            $logged = (string) file_get_contents($logFile);

            $this->assertFalse($result->ok);
            $this->assertStringNotContainsString($key, $logged, 'API key must never be logged');
            $this->assertStringNotContainsString($key, (string) json_encode($result->toArray()), 'API key must never be on the result');
            $this->assertStringNotContainsString($key, $transport->requests[0]['body'], 'API key must not be in the request body');
            // Sanity: the key DID travel — only ever in the x-api-key header.
            $this->assertStringContainsString($key, implode("\n", $transport->requests[0]['headers']));
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
            @unlink($logFile);
        }
    }
}
