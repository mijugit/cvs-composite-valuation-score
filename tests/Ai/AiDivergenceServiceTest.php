<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClient;
use PHPUnit\Framework\TestCase;

class AiDivergenceServiceTest extends TestCase
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

    private function okBody(string $text = 'Narracja testowa.'): string
    {
        return (string) json_encode([
            'content'     => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'model'       => 'claude-test',
            'usage'       => ['input_tokens' => 500, 'output_tokens' => 300],
        ]);
    }

    /** @return array<string, mixed> */
    private function cvsResult(): array
    {
        return [
            'ticker'        => 'AAPL',
            'quality_gate'  => true,
            'swing'         => ['cvs' => 74.5, 'recommendation' => 'SILNE KUPUJ'],
            'fundamental'   => ['cvs' => 62.0, 'recommendation' => 'AKUMULUJ'],
            'golden_signal' => 'strong',
            'pillar_scores' => [
                'valuation'      => 78.0,
                'momentum_swing' => 80.0,
                'momentum_fund'  => 55.0,
                'quality'        => 60.0,
            ],
            'gate_failures' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function financials(): array
    {
        return [
            'sector'          => 'Technology',
            'current_price'   => 185.0,
            'forecast' => [
                'targets' => [
                    'mean'   => 200.0,
                    'low'    => 175.0,
                    'high'   => 220.0,
                    'upside' => 0.08,
                ],
                'recommendation_mean' => 1.8,
                'num_analysts'        => 45,
                'latest' => [
                    'strong_buy'  => 20,
                    'buy'         => 15,
                    'hold'        => 8,
                    'sell'        => 2,
                    'strong_sell' => 0,
                ],
                'trend' => [],
            ],
        ];
    }

    public function test_generate_success_returns_ok_result(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => $this->okBody('## 1. Ocena modelu CVS\nTest.'), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $result = $service->generate('AAPL', $this->cvsResult(), $this->financials());

        $this->assertTrue($result->ok);
        $this->assertNotEmpty($result->text);
    }

    public function test_generate_failure_returns_not_ok(): void
    {
        $transport = new FakeTransport([
            ['status' => 500, 'body' => '{"error":"server error"}', 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $result = $service->generate('AAPL', $this->cvsResult(), $this->financials());

        $this->assertFalse($result->ok);
    }

    public function test_prompt_contains_ticker_and_cvs_scores(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $service->generate('AAPL', $this->cvsResult(), $this->financials());

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['messages'][0]['content'];

        $this->assertStringContainsString('AAPL', $userMsg);
        $this->assertStringContainsString('74.5', $userMsg);
        $this->assertStringContainsString('62.0', $userMsg);
        $this->assertStringContainsString('78.0', $userMsg); // valuation pillar
    }

    public function test_prompt_contains_analyst_data(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $service->generate('AAPL', $this->cvsResult(), $this->financials());

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['messages'][0]['content'];

        $this->assertStringContainsString('200.00', $userMsg); // mean target
        $this->assertStringContainsString('1.80', $userMsg);   // recommendation_mean
        $this->assertStringContainsString('45', $userMsg);     // num_analysts
    }

    public function test_prompt_contains_anti_hallucination_guardrail(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $service->generate('AAPL', $this->cvsResult(), $this->financials());

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['system'][0]['text'] ?? '';

        $this->assertStringContainsString('ONLY on the numerical data provided', $systemText);
    }

    public function test_null_forecast_handled_gracefully(): void
    {
        $financialsNoForecast = ['sector' => 'Technology', 'forecast' => null];

        $transport = new FakeTransport([
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $result = $service->generate('XYZ', $this->cvsResult(), $financialsNoForecast);

        $this->assertTrue($result->ok);

        $sentBody = json_decode($transport->requests[0]['body'], true);
        $userMsg  = $sentBody['messages'][0]['content'];
        $this->assertStringContainsString('No analyst coverage data available', $userMsg);
    }

    public function test_system_prompt_requests_polish_response(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => $this->okBody(), 'error' => null],
        ]);
        $client  = new ClaudeClient($this->config(), $transport);
        $service = new AiDivergenceService($client);

        $service->generate('AAPL', $this->cvsResult(), $this->financials());

        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = $sentBody['system'][0]['text'] ?? '';

        $this->assertStringContainsString('Polish', $systemText);
    }
}
