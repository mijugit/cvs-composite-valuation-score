<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiCriticalReviewService;
use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClient;
use PHPUnit\Framework\TestCase;

class AiCriticalReviewServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
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

    private function okBody(string $text = 'Recenzja krytyczna.'): string
    {
        return (string) json_encode([
            'content'     => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'model'       => 'claude-test',
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 200],
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

    private function service(ClaudeClient $client): AiCriticalReviewService
    {
        return new AiCriticalReviewService($client, new AiDivergenceService($client));
    }

    public function test_generate_sends_web_search_tool_and_returns_ok_result(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'Analiza etapu 1 tekst.');

        $this->assertTrue($result->ok);
        $this->assertSame('Recenzja krytyczna.', $result->text);

        $body = $transport->requests[0]['body'];
        $this->assertStringContainsString('web_search_20260209', $body);
        $this->assertStringContainsString('"max_uses":5', $body);
    }

    public function test_user_message_reuses_stage1_data_block_and_includes_stage1_analysis(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'Analiza etapu 1 — unikalny fragment XYZ.');

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['messages'][0]['content'];

        $this->assertStringContainsString('MU', $userMsg);
        $this->assertStringContainsString('88.5', $userMsg); // stage-1 data block, reused
        $this->assertStringContainsString('83.6', $userMsg); // valuation pillar
        $this->assertStringContainsString('Analiza etapu 1 — unikalny fragment XYZ.', $userMsg);
    }

    public function test_system_prompt_contains_four_sections_and_guardrails(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['system'][0]['text'];

        $this->assertStringContainsString('## 1. Świeże katalizatory', $systemText);
        $this->assertStringContainsString('## 2. Czego model nie widzi', $systemText);
        $this->assertStringContainsString('## 3. Krytyka naszej analizy', $systemText);
        $this->assertStringContainsString('## 4. Dwa scenariusze', $systemText);
        $this->assertStringContainsString('ANCHOR RULE', $systemText);
        $this->assertStringContainsString('NUMBER FIDELITY', $systemText);
        $this->assertStringContainsString('transcribe it EXACTLY', $systemText);
        $this->assertStringContainsString('NO NEWS IS ALSO INFORMATION', $systemText);
        $this->assertStringContainsString('Inwestuj świadomie', $systemText);
    }

    /**
     * Real-world bug (production, ticker MU, 2026-07-07): with no anchor for
     * "today", the model presented training-era news (mid-2025) as being
     * within the last 14 days. The user message must state the actual current
     * date so the model has something concrete to check recency against.
     */
    public function test_user_message_includes_todays_date_anchor(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['messages'][0]['content'];

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->assertStringContainsString("TODAY'S DATE: {$today}", $userMsg);
    }

    public function test_system_prompt_contains_date_discipline_and_no_meta_commentary_guardrails(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['system'][0]['text'];

        $this->assertStringContainsString('DATE DISCIPLINE', $systemText);
        $this->assertStringContainsString('NO META-COMMENTARY', $systemText);
    }

    public function test_system_prompt_mandates_trailing_probability_json_block(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['system'][0]['text'];

        $this->assertStringContainsString('PROBABILITY BLOCK', $systemText);
        $this->assertStringContainsString('bull_probability', $systemText);
        $this->assertStringContainsString('bear_probability', $systemText);
    }

    public function test_generate_surfaces_degraded_search_without_failing(): void
    {
        $body = (string) json_encode([
            'content' => [
                ['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'MU']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 's1', 'content' => [
                    'type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded',
                ]],
                ['type' => 'text', 'text' => 'Recenzja mimo ograniczonego wyszukiwania.'],
            ],
            'stop_reason' => 'end_turn',
            'model'       => 'claude-test',
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 200],
        ]);
        $transport = new FakeTransport([['status' => 200, 'body' => $body, 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $this->assertTrue($result->ok);
        $this->assertTrue($result->searchDegraded);
        $this->assertSame('Recenzja mimo ograniczonego wyszukiwania.', $result->text);
    }

    public function test_generate_follows_pause_turn_continuation_to_completion(): void
    {
        $paused = (string) json_encode([
            'content'     => [['type' => 'text', 'text' => 'Szukam…']],
            'stop_reason' => 'pause_turn',
            'model'       => 'claude-test',
            'usage'       => ['input_tokens' => 50, 'output_tokens' => 50],
        ]);
        $transport = new FakeTransport([
            ['status' => 200, 'body' => $paused, 'error' => null],
            ['status' => 200, 'body' => $this->okBody('Recenzja dokończona.'), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $this->assertTrue($result->ok);
        $this->assertSame('Recenzja dokończona.', $result->text);
        $this->assertCount(2, $transport->requests);
    }

    public function test_generate_propagates_failure(): void
    {
        $transport = new FakeTransport([['status' => 529, 'body' => '', 'error' => null]]);
        $client    = new ClaudeClient($this->config(), $transport);
        $service   = $this->service($client);

        $result = $service->generate('MU', $this->cvsResult(), $this->financials(), 'x');

        $this->assertFalse($result->ok);
    }
}
