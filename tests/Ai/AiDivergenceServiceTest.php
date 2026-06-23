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

    // ------------------------------------------------------------------
    // Phase 8 enrichment
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function richFinancials(): array
    {
        return array_merge($this->financials(), [
            'eps_revision_pct'     => -0.13,
            'eps_revision_breadth' => -0.40,
            'eps_surprise_pct'     => 0.05,
            'eps_beat_count_4q'    => 3,
            'days_to_earnings'     => 3,
            'days_since_earnings'  => 85,
            'fifty_two_week_low'   => 103.38,
            'fifty_two_week_high'  => 1149.43,
            'pe_ratio'             => 25.0,
            'ev_ebitda'            => 18.0,
            'ps_ratio'             => 6.0,
            'revenue_growth'       => 0.12,
            'return_on_equity'     => 0.30,
            'total_debt'           => 10000.0,
            'cash'                 => 4000.0,
            'ebitda'               => 3000.0,
        ]);
    }

    /** @return array<string, mixed> */
    private function trajectory(): array
    {
        return ['has_trajectory' => true, 'latest' => 74.5, 'delta_daily' => -2.3, 'delta_weekly' => 1.0, 'points' => []];
    }

    /** @return array<string, mixed> */
    private function execPlan(): array
    {
        return [
            'has_zone' => true, 'atr' => 77.84, 'support' => 700.66,
            'zone_low' => 700.66, 'zone_high' => 778.50,
            'stop_swing' => 583.90, 'stop_fund' => 467.13,
            'state' => 'above', 'source' => 'support',
        ];
    }

    private function captureUserMsg(FakeTransport $transport): string
    {
        $sentBody = json_decode($transport->requests[0]['body'], true);
        return (string) $sentBody['messages'][0]['content'];
    }

    public function test_prompt_contains_enrichment_signals(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $service   = new AiDivergenceService(new ClaudeClient($this->config(), $transport));

        $cvs = $this->cvsResult();
        $cvs['earnings_timing'] = ['days_since' => 85, 'days_to' => 3, 'state' => 'before', 'guard_active' => true];

        $service->generate('AAPL', $cvs, $this->richFinancials(), null, $this->trajectory(), $this->execPlan());
        $userMsg = $this->captureUserMsg($transport);

        // Tier 1 — expectations + trajectory
        $this->assertStringContainsString('-13.0%', $userMsg);     // eps_revision_pct
        $this->assertStringContainsString('5.0%', $userMsg);       // eps_surprise_pct
        $this->assertStringContainsString('-0.40', $userMsg);      // revision breadth
        $this->assertStringContainsString('CVS TRAJECTORY', $userMsg);
        $this->assertStringContainsString('-2.3', $userMsg);       // delta day-over-day
        // Tier 2 — earnings timing + execution plan
        $this->assertStringContainsString('Earnings state: before', $userMsg);
        $this->assertStringContainsString('EXECUTION PLAN', $userMsg);
        $this->assertStringContainsString('700.66', $userMsg);
        $this->assertStringContainsString('above', $userMsg);
        // Tier 3 — 52w + multiples + net debt/EBITDA
        $this->assertStringContainsString('52-WEEK RANGE', $userMsg);
        $this->assertStringContainsString('2.00', $userMsg);       // net debt/EBITDA = (10000-4000)/3000
    }

    public function test_enrichment_na_when_missing(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $service   = new AiDivergenceService(new ClaudeClient($this->config(), $transport));

        // Minimal financials, no trajectory, no execPlan.
        $service->generate('XYZ', $this->cvsResult(), ['sector' => 'Technology', 'forecast' => null], null, null, null);
        $userMsg = $this->captureUserMsg($transport);

        $this->assertStringContainsString('CVS TRAJECTORY', $userMsg);
        $this->assertStringContainsString('N/A (insufficient snapshot history)', $userMsg);
        $this->assertStringContainsString('N/A (insufficient price data', $userMsg);
        $this->assertStringContainsString('Net debt / EBITDA: N/A', $userMsg);
    }

    public function test_system_prompt_has_five_sections_and_interpret_rule(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody(), 'error' => null]]);
        $service   = new AiDivergenceService(new ClaudeClient($this->config(), $transport));

        $service->generate('AAPL', $this->cvsResult(), $this->financials());
        $sentBody   = json_decode($transport->requests[0]['body'], true);
        $systemText = (string) ($sentBody['system'][0]['text'] ?? '');

        $this->assertStringContainsString('## 5. Plan egzekucji', $systemText);
        $this->assertStringContainsString('exactly these 5 sections', $systemText);
        $this->assertStringContainsString('do NOT merely', $systemText); // interpret-not-repeat rule
    }
}
