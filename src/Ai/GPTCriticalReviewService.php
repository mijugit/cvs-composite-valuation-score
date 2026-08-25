<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Calls OpenAI (GPT-5.6 Terra or Luna, web search enabled) to generate the
 * same 4-section critical review + trailing probability block as
 * AiCriticalReviewService/GeminiCriticalReviewService — change: critical-review-openai.
 *
 * ONE shared service for both flavors — Terra and Luna are the identical
 * Responses API shape (see GPTClientFactory), so unlike Claude vs Gemini
 * (genuinely different APIs), splitting this into two service classes would
 * just duplicate identical logic for no isolation benefit. Each flavor still
 * gets its own worker script (bin/generate_critical_review_gpt_terra.php /
 * _luna.php), preserving independent async job isolation where it matters.
 *
 * Prompt content identity with every other provider is guaranteed
 * structurally by calling the same CriticalReviewPrompt static builder — never
 * reintroduce inline prompt-building here.
 *
 * Does NOT hardcode a MAX_TOKENS class constant (unlike AiCriticalReviewService/
 * GeminiCriticalReviewService) — omits max_tokens from options so GPTClient
 * falls through to config/gpt.php's max_tokens (8000, the user's own .env
 * value, set before this class existed). Respects that configuration rather
 * than silently overriding it.
 *
 * Returns AiResult with the raw response text (narrative + trailing JSON
 * block still attached); the caller (worker script) runs
 * CriticalReviewProbabilityParser::parse() to split them apart. Never throws.
 */
final class GPTCriticalReviewService
{
    public function __construct(
        private readonly GPTClient            $client,
        private readonly AiDivergenceService  $divergenceService,
    ) {}

    /**
     * Generate the critical review narrative. Same parameter list as
     * AiCriticalReviewService::generate()/GeminiCriticalReviewService::generate()
     * by design — every provider accepts identical inputs (FR-006).
     *
     * @param array<string, mixed>      $cvsResult    CVSResult::toArray()
     * @param array<string, mixed>      $financials   FinancialDataFetcher output
     * @param string                    $stage1Analysis Cached stage-1 analysis text (to be reviewed)
     * @param float|null                $cvsFairPrice Sector-parity implied price (null = not calculable)
     * @param array<string, mixed>|null $trajectory   CVS trajectory summary or null
     * @param array<string, mixed>|null $execPlan     ATR execution plan or null
     */
    public function generate(
        string $ticker,
        array  $cvsResult,
        array  $financials,
        string $stage1Analysis,
        ?float $cvsFairPrice = null,
        ?array $trajectory = null,
        ?array $execPlan = null,
    ): AiResult {
        $system = CriticalReviewPrompt::buildSystemPrompt();

        $dataBlock = $this->divergenceService->buildDataBlock(
            $ticker, $cvsResult, $financials, $cvsFairPrice, $trajectory, $execPlan
        );

        $message = CriticalReviewPrompt::buildUserMessage($ticker, $dataBlock, $stage1Analysis);

        return $this->client->sendMessage(
            [['role' => 'user', 'content' => $message]],
            $system,
            ['tools' => [['type' => 'web_search']]]
        );
    }
}
