<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClient;
use CVS\Ai\GeminiClient;
use CVS\Ai\GeminiCriticalReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors AiCriticalReviewServiceTest's structure — same FakeTransport
 * pattern as GeminiClientTest.php — asserting the Gemini-specific tool shape
 * and that the shared data block / prompt builder are reused unchanged
 * (change: critical-review-models).
 */
class GeminiCriticalReviewServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function geminiConfig(): array
    {
        return [
            'api_key'             => 'gm-test-key',
            'base_url'            => 'https://generativelanguage.googleapis.com/v1beta',
            'model'               => 'gemini-test',
            'max_tokens'          => 8192,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
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

    private function okBody(string $text = 'Recenzja krytyczna (Gemini).'): string
    {
        return (string) json_encode([
            'candidates' => [[
                'content'      => ['parts' => [['text' => $text]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 200],
            'modelVersion'  => 'gemini-test',
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

    private function service(GeminiClient $client): GeminiCriticalReviewService
    {
        // AiDivergenceService::buildDataBlock() never issues a network call,
        // so an unscripted ClaudeClient (would error if actually invoked) is
        // safe here — mirrors how the real Gemini worker wires it too.
        $dummyClaudeClient = new ClaudeClient($this->claudeConfig(), new FakeTransport([]));
        return new GeminiCriticalReviewService($client, new AiDivergenceService($dummyClaudeClient));
    }

    public function test_generate_sends_google_search_tool_and_returns_ok_result(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->geminiConfig(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'Analiza etapu 1 tekst.');

        $this->assertTrue($result->ok);
        $this->assertSame('Recenzja krytyczna (Gemini).', $result->text);

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $this->assertArrayHasKey('tools', $sentBody);
        $this->assertArrayHasKey('googleSearch', $sentBody['tools'][0]);
    }

    public function test_user_message_reuses_stage1_data_block_and_includes_stage1_analysis(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->geminiConfig(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'Analiza etapu 1 — unikalny fragment XYZ.');

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['contents'][0]['parts'][0]['text'];

        $this->assertStringContainsString('MU', $userMsg);
        $this->assertStringContainsString('88.5', $userMsg); // stage-1 data block, reused
        $this->assertStringContainsString('83.6', $userMsg); // valuation pillar
        $this->assertStringContainsString('Analiza etapu 1 — unikalny fragment XYZ.', $userMsg);
    }

    public function test_system_prompt_matches_shared_claude_prompt_including_probability_block(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new GeminiClient($this->geminiConfig(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('## 1. Świeże katalizatory', $systemText);
        $this->assertStringContainsString('## 4. Dwa scenariusze', $systemText);
        $this->assertStringContainsString('PROBABILITY BLOCK', $systemText);
        $this->assertStringContainsString('bull_probability', $systemText);
    }

    public function test_generate_propagates_failure(): void
    {
        $transport = new FakeTransport([['status' => 500, 'body' => '', 'error' => null]]);
        $client    = new GeminiClient($this->geminiConfig(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $this->assertFalse($result->ok);
    }
}
