<?php

declare(strict_types=1);

namespace CVS\Tests\LlmGemini;

use CVS\Ai\GeminiClientFactory;
use CVS\LlmGemini\LlmGeminiContextGatherer;
use CVS\Tests\Ai\FakeTransport;
use PHPUnit\Framework\TestCase;

class LlmGeminiContextGathererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function geminiConfig(): array
    {
        return [
            'api_key'             => 'gm-test-key',
            'base_url'            => 'https://generativelanguage.googleapis.com/v1beta',
            'model'               => 'gemini-3.7-flash',
            'max_tokens'          => 2048,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
        ];
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function searchSuccessResponse(string $text): array
    {
        $body = json_encode([
            'candidates' => [[
                'content'      => ['parts' => [['text' => $text]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 80],
        ]);
        return ['status' => 200, 'body' => $body ?: '', 'error' => null];
    }

    // --- No candidates: client never constructed / called ---

    public function testEmptyCandidateListMakesNoSearchCalls(): void
    {
        $transport = new FakeTransport([]); // any send() call would fail — proves none happens
        $gatherer  = new LlmGeminiContextGatherer(
            $this->geminiConfig(),
            3,
            GeminiClientFactory::fromConfig($this->geminiConfig(), $transport)
        );

        $context = $gatherer->gather([]);

        $this->assertSame([], $context);
        $this->assertCount(0, $transport->requests);
    }

    // --- Search sub-calls capped at config value, always fresh (no cache check) ---

    public function testSearchSubCallsCappedAtConfigValueEvenWithMoreCandidates(): void
    {
        $transport = new FakeTransport([
            $this->searchSuccessResponse('AAA: brak newsów.'),
            $this->searchSuccessResponse('BBB: brak newsów.'),
        ]);
        $gatherer = new LlmGeminiContextGatherer(
            $this->geminiConfig(),
            2, // cap of 2
            GeminiClientFactory::fromConfig($this->geminiConfig(), $transport)
        );

        $context = $gatherer->gather(['AAA', 'BBB', 'CCC', 'DDD']);

        $this->assertCount(2, $transport->requests);
        $this->assertArrayHasKey('AAA', $context);
        $this->assertArrayHasKey('BBB', $context);
        $this->assertArrayNotHasKey('CCC', $context);
        $this->assertArrayNotHasKey('DDD', $context);
    }

    public function testTickerBeyondSearchCapGetsNoContext(): void
    {
        $transport = new FakeTransport([
            $this->searchSuccessResponse('MSFT: brak istotnych newsów w ostatnich 14 dniach.'),
        ]);
        $gatherer = new LlmGeminiContextGatherer(
            $this->geminiConfig(),
            1, // cap of 1 — only the first ticker gets searched
            GeminiClientFactory::fromConfig($this->geminiConfig(), $transport)
        );

        $context = $gatherer->gather(['MSFT', 'GOOG']);

        $this->assertArrayHasKey('MSFT', $context);
        $this->assertArrayNotHasKey('GOOG', $context); // beyond cap — absent, not an error
        $this->assertCount(1, $transport->requests);
    }

    // --- googleSearch tool is always requested (no cross-provider cache reuse) ---

    public function testGoogleSearchToolIsAlwaysRequested(): void
    {
        $transport = new FakeTransport([$this->searchSuccessResponse('AAPL: brak newsów.')]);
        $gatherer  = new LlmGeminiContextGatherer(
            $this->geminiConfig(),
            3,
            GeminiClientFactory::fromConfig($this->geminiConfig(), $transport)
        );

        $gatherer->gather(['AAPL']);

        $this->assertStringContainsString('"googleSearch"', $transport->requests[0]['body']);
    }

    // --- A single ticker's failure does not abort the loop ---

    public function testSingleTickerFailureDoesNotAbortLoop(): void
    {
        $transport = new FakeTransport([
            ['status' => 500, 'body' => '{"error":{"status":"INTERNAL"}}', 'error' => null],
            $this->searchSuccessResponse('BBB: brak newsów.'),
        ]);
        $gatherer = new LlmGeminiContextGatherer(
            $this->geminiConfig(),
            2,
            GeminiClientFactory::fromConfig($this->geminiConfig(), $transport)
        );

        $context = $gatherer->gather(['AAA', 'BBB']);

        $this->assertArrayNotHasKey('AAA', $context, 'failed ticker gets no context, not an exception');
        $this->assertArrayHasKey('BBB', $context);
        $this->assertCount(2, $transport->requests);
    }

    // --- Empty/blank text is treated as "no context available" ---

    public function testBlankResponseTextYieldsNoContextForTicker(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => (string) json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '   ']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ]), 'error' => null],
        ]);
        $gatherer = new LlmGeminiContextGatherer(
            $this->geminiConfig(),
            1,
            GeminiClientFactory::fromConfig($this->geminiConfig(), $transport)
        );

        $context = $gatherer->gather(['AAPL']);

        $this->assertArrayNotHasKey('AAPL', $context);
    }
}
