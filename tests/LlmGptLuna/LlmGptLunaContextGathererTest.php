<?php

declare(strict_types=1);

namespace CVS\Tests\LlmGptLuna;

use CVS\Ai\GPTClientFactory;
use CVS\LlmGptLuna\LlmGptLunaContextGatherer;
use CVS\Tests\Ai\FakeTransport;
use PHPUnit\Framework\TestCase;

class LlmGptLunaContextGathererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function gptConfig(): array
    {
        return [
            'api_key'             => 'gpt-test-key',
            'base_url'            => 'https://api.openai.com/v1/responses',
            'model'               => 'gpt-5.6-luna',
            'max_tokens'          => 2048,
            'timeout'             => 5,
            'max_retries'         => 0,
            'total_timeout'       => 10,
            'retry_base_delay_ms' => 0,
            'reasoning_effort'    => 'medium',
        ];
    }

    /** @return array{status: int, body: string, error: string|null} */
    private function searchSuccessResponse(string $text): array
    {
        $body = json_encode([
            'output' => [[
                'type'    => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage'  => ['input_tokens' => 200, 'output_tokens' => 80],
            'status' => 'completed',
        ]);
        return ['status' => 200, 'body' => $body ?: '', 'error' => null];
    }

    // --- No candidates: client never constructed / called ---

    public function testEmptyCandidateListMakesNoSearchCalls(): void
    {
        $transport = new FakeTransport([]); // any send() call would fail — proves none happens
        $gatherer  = new LlmGptLunaContextGatherer(
            $this->gptConfig(),
            3,
            GPTClientFactory::fromConfig($this->gptConfig(), 'luna', $transport)
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
        $gatherer = new LlmGptLunaContextGatherer(
            $this->gptConfig(),
            2, // cap of 2
            GPTClientFactory::fromConfig($this->gptConfig(), 'luna', $transport)
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
        $gatherer = new LlmGptLunaContextGatherer(
            $this->gptConfig(),
            1, // cap of 1 — only the first ticker gets searched
            GPTClientFactory::fromConfig($this->gptConfig(), 'luna', $transport)
        );

        $context = $gatherer->gather(['MSFT', 'GOOG']);

        $this->assertArrayHasKey('MSFT', $context);
        $this->assertArrayNotHasKey('GOOG', $context); // beyond cap — absent, not an error
        $this->assertCount(1, $transport->requests);
    }

    // --- web_search tool is always requested (no cross-provider cache reuse) ---

    public function testWebSearchToolIsAlwaysRequested(): void
    {
        $transport = new FakeTransport([$this->searchSuccessResponse('AAPL: brak newsów.')]);
        $gatherer  = new LlmGptLunaContextGatherer(
            $this->gptConfig(),
            3,
            GPTClientFactory::fromConfig($this->gptConfig(), 'luna', $transport)
        );

        $gatherer->gather(['AAPL']);

        $this->assertStringContainsString('"web_search"', $transport->requests[0]['body']);
    }

    // --- A single ticker's failure does not abort the loop ---

    public function testSingleTickerFailureDoesNotAbortLoop(): void
    {
        $transport = new FakeTransport([
            ['status' => 500, 'body' => '{"error":{"type":"server_error"}}', 'error' => null],
            $this->searchSuccessResponse('BBB: brak newsów.'),
        ]);
        $gatherer = new LlmGptLunaContextGatherer(
            $this->gptConfig(),
            2,
            GPTClientFactory::fromConfig($this->gptConfig(), 'luna', $transport)
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
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '   ']]]],
                'usage'  => ['input_tokens' => 1, 'output_tokens' => 1],
                'status' => 'completed',
            ]), 'error' => null],
        ]);
        $gatherer = new LlmGptLunaContextGatherer(
            $this->gptConfig(),
            1,
            GPTClientFactory::fromConfig($this->gptConfig(), 'luna', $transport)
        );

        $context = $gatherer->gather(['AAPL']);

        $this->assertArrayNotHasKey('AAPL', $context);
    }
}
