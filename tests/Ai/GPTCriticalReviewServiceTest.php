<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClient;
use CVS\Ai\GPTClient;
use CVS\Ai\GPTCriticalReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors GeminiCriticalReviewServiceTest's structure — same FakeTransport
 * pattern as GPTClientTest.php — asserting the OpenAI-specific tool shape and
 * that the shared data block / prompt builder are reused unchanged
 * (change: critical-review-openai).
 */
class GPTCriticalReviewServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function gptConfig(): array
    {
        return [
            'api_key'             => 'sk-test-key',
            'base_url'            => 'https://api.openai.com/v1/responses',
            'model'               => 'gpt-5.6-terra',
            'max_tokens'          => 8000,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
            'reasoning_effort'    => 'medium',
        ];
    }

    /** @return array<string, mixed> */
    private function claudeConfig(): array
    {
        return [
            'api_key'             => 'sk-ant-test',
            'base_url'            => 'https://api.anthropic.com/v1/messages',
            'model'               => 'claude-test',
            'anthropic_version'   => '2023-06-01',
            'max_tokens'          => 2048,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
        ];
    }

    private function okBody(string $text = 'Recenzja krytyczna (GPT).'): string
    {
        return (string) json_encode([
            'output' => [[
                'type'    => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage'  => ['input_tokens' => 100, 'output_tokens' => 200],
            'status' => 'completed',
            'model'  => 'gpt-5.6-terra',
        ]);
    }

    /** @return array<string, mixed> */
    private function cvsResult(): array
    {
        return [
            'swing'         => ['cvs' => 88.5, 'recommendation' => 'SILNE KUPUJ'],
            'fundamental'   => ['cvs' => 88.6, 'recommendation' => 'SILNE KUPUJ'],
            'pillar_scores' => [
                'valuation'      => 83.6,
                'momentum_swing' => 89.2,
                'momentum_fund'  => 95.0,
                'quality'        => 100.0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function financials(): array
    {
        return ['sector' => 'Technology', 'current_price' => 998.80, 'forecast' => null];
    }

    private function service(GPTClient $client): GPTCriticalReviewService
    {
        // AiDivergenceService::buildDataBlock() never issues a network call,
        // so an unscripted ClaudeClient (would error if actually invoked) is
        // safe here — mirrors how GeminiCriticalReviewServiceTest wires it.
        $dummyClaudeClient = new ClaudeClient($this->claudeConfig(), new FakeTransport([]));
        return new GPTCriticalReviewService($client, new AiDivergenceService($dummyClaudeClient));
    }

    public function test_generate_sends_web_search_tool_and_returns_ok_result(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->gptConfig(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'Analiza etapu 1 tekst.');

        $this->assertTrue($result->ok);
        $this->assertSame('Recenzja krytyczna (GPT).', $result->text);

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $this->assertSame('web_search', $sentBody['tools'][0]['type']);
    }

    public function test_generate_does_not_override_configured_max_tokens(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->gptConfig(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody = json_decode($transport->requests[0]['body'], true);
        // 8000 comes from gptConfig()'s max_tokens — the service must NOT
        // hardcode its own budget and silently override the configured value.
        $this->assertSame(8000, $sentBody['max_output_tokens']);
    }

    public function test_user_message_reuses_stage1_data_block_and_includes_stage1_analysis(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->gptConfig(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'Analiza etapu 1 — unikalny fragment XYZ.');

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['input'][0]['content'];

        $this->assertStringContainsString('MU', $userMsg);
        $this->assertStringContainsString('88.5', $userMsg); // stage-1 data block, reused
        $this->assertStringContainsString('83.6', $userMsg); // valuation pillar
        $this->assertStringContainsString('Analiza etapu 1 — unikalny fragment XYZ.', $userMsg);
    }

    public function test_system_prompt_matches_shared_prompt_including_probability_block(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GPTClient($this->gptConfig(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['instructions'];

        $this->assertStringContainsString('## 1. Świeże katalizatory', $systemText);
        $this->assertStringContainsString('## 4. Dwa scenariusze', $systemText);
        $this->assertStringContainsString('PROBABILITY BLOCK', $systemText);
        $this->assertStringContainsString('bull_probability', $systemText);
    }

    public function test_generate_propagates_failure(): void
    {
        $transport = new FakeTransport([['status' => 500, 'body' => '', 'error' => null]]);
        $client    = new GPTClient($this->gptConfig(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $this->assertFalse($result->ok);
    }
}
