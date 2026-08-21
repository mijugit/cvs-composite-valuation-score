<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\FundamentalsValidationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FundamentalsValidationServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'api_key'             => 'gm-test-key',
            'base_url'            => 'https://generativelanguage.googleapis.com/v1beta',
            'model'               => 'gemini-test',
            'max_tokens'          => 2048,
            'timeout'             => 5,
            'max_retries'         => 2,
            'total_timeout'       => 25,
            'retry_base_delay_ms' => 0,
        ];
    }

    private function okBody(string $jsonText): string
    {
        return (string) json_encode([
            'candidates' => [[
                'content'      => ['parts' => [['text' => $jsonText]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50],
            'modelVersion'  => 'gemini-test',
        ]);
    }

    public function test_well_formed_response_splits_validated_and_checked_no_data(): void
    {
        $today = new DateTimeImmutable();
        $qEnd  = DateTimeImmutable::createFromFormat('Y-m-d', '2026-05-31');
        $this->assertNotFalse($qEnd);
        $expectedDaysSince = (float) (-1 * (int) floor(($qEnd->getTimestamp() - $today->getTimestamp()) / 86400));

        $responseJson = json_encode([
            'total_equity'                      => 7380600000,
            'gross_profit'                      => null,
            'last_reported_fiscal_quarter_end'  => '2026-05-31',
            'notes'                             => 'gross_profit niedostępny w źródłach.',
        ]);
        $this->assertIsString($responseJson);

        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody($responseJson), 'error' => null]]);
        $client    = new \CVS\Ai\GeminiClient($this->config(), $transport);
        $service   = new FundamentalsValidationService($this->config());

        $result = $service->validate(
            'GIS',
            'Consumer Defensive',
            ['total_equity', 'gross_profit', 'days_since_earnings'],
            ['total_equity' => null, 'gross_profit' => null, 'days_since_earnings' => 3007],
            $client
        );

        $this->assertTrue($result->ok);
        $this->assertSame('validated', $result->diff['total_equity']['status']);
        $this->assertSame(7_380_600_000.0, $result->diff['total_equity']['new']);
        $this->assertSame('checked_no_data', $result->diff['gross_profit']['status']);
        $this->assertNull($result->diff['gross_profit']['new']);
        $this->assertSame('validated', $result->diff['days_since_earnings']['status']);
        $this->assertSame($expectedDaysSince, $result->diff['days_since_earnings']['new']);
        $this->assertSame(3007, $result->diff['days_since_earnings']['old']);
        $this->assertSame('gross_profit niedostępny w źródłach.', $result->notes);
    }

    public function test_missing_field_in_response_is_checked_no_data(): void
    {
        $responseJson = json_encode(['notes' => 'brak danych']);
        $this->assertIsString($responseJson);

        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody($responseJson), 'error' => null]]);
        $client    = new \CVS\Ai\GeminiClient($this->config(), $transport);
        $service   = new FundamentalsValidationService($this->config());

        $result = $service->validate('GIS', 'Consumer Defensive', ['forward_pe'], [], $client);

        $this->assertTrue($result->ok);
        $this->assertSame('checked_no_data', $result->diff['forward_pe']['status']);
    }

    public function test_malformed_json_response_is_a_failure(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody('this is not json'), 'error' => null]]);
        $client    = new \CVS\Ai\GeminiClient($this->config(), $transport);
        $service   = new FundamentalsValidationService($this->config());

        $result = $service->validate('GIS', 'Consumer Defensive', ['forward_pe'], [], $client);

        $this->assertFalse($result->ok);
        $this->assertSame([], $result->diff);
        $this->assertNotNull($result->failureMessage);
    }

    public function test_response_wrapped_in_markdown_fence_still_parses(): void
    {
        $responseJson = "```json\n" . (string) json_encode(['forward_pe' => 12.45, 'notes' => '']) . "\n```";

        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody($responseJson), 'error' => null]]);
        $client    = new \CVS\Ai\GeminiClient($this->config(), $transport);
        $service   = new FundamentalsValidationService($this->config());

        $result = $service->validate('GIS', 'Consumer Defensive', ['forward_pe'], [], $client);

        $this->assertTrue($result->ok);
        $this->assertSame(12.45, $result->diff['forward_pe']['new']);
    }

    public function test_transport_failure_is_a_failure_result(): void
    {
        $transport = new FakeTransport([['status' => 0, 'body' => '', 'error' => 'timed out']]);
        $client    = new \CVS\Ai\GeminiClient($this->config(), $transport);
        $service   = new FundamentalsValidationService($this->config());

        $result = $service->validate('GIS', 'Consumer Defensive', ['forward_pe'], [], $client);

        $this->assertFalse($result->ok);
    }

    public function test_request_includes_google_search_tool(): void
    {
        $responseJson = json_encode(['forward_pe' => 12.45, 'notes' => '']);
        $this->assertIsString($responseJson);

        $transport = new FakeTransport([['status' => 200, 'body' => $this->okBody($responseJson), 'error' => null]]);
        $client    = new \CVS\Ai\GeminiClient($this->config(), $transport);
        $service   = new FundamentalsValidationService($this->config());

        $service->validate('GIS', 'Consumer Defensive', ['forward_pe'], [], $client);

        $this->assertCount(1, $transport->requests);
        $this->assertStringContainsString('googleSearch', $transport->requests[0]['body']);
    }
}
