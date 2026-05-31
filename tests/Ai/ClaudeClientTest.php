<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\ClaudeClient;
use CVS\Ai\AiFailureKind;
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
}
