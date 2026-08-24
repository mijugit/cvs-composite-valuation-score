<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Calls Gemini (web search / grounding enabled) to generate the same
 * 4-section critical review + trailing probability block as
 * AiCriticalReviewService, using Gemini instead of Claude — change:
 * critical-review-models.
 *
 * Deliberately isolated from AiCriticalReviewService (separate class, own
 * worker script bin/generate_critical_review_gemini.php) so the proven
 * Claude path carries zero regression risk from this addition. Prompt
 * content identity between the two providers is guaranteed structurally by
 * both classes calling the same CriticalReviewPrompt static builder — never
 * reintroduce inline prompt-building here or in AiCriticalReviewService, or
 * the two providers' prompts will silently drift (see CriticalReviewPrompt's
 * own docblock).
 *
 * Tool shape is the one genuine transport difference from Claude: Gemini's
 * googleSearch tool has no max_uses-style cap (unlike Claude's
 * web_search_20260209 tool) — same shape already proven in
 * FundamentalsValidationService.
 *
 * Returns AiResult with the raw response text (narrative + trailing JSON
 * block still attached); the caller (bin/generate_critical_review_gemini.php)
 * runs CriticalReviewProbabilityParser::parse() to split them apart. Never
 * throws.
 */
final class GeminiCriticalReviewService
{
    /**
     * Same budget as Claude's critical review (AiCriticalReviewService) —
     * Gemini has no pause_turn-style continuation, so headroom matters even
     * more for a single-shot call covering 4 narrative sections.
     */
    private const MAX_TOKENS = 8192;

    public function __construct(
        private readonly GeminiClient        $client,
        private readonly AiDivergenceService $divergenceService,
    ) {}

    /**
     * Generate the critical review narrative. Same parameter list as
     * AiCriticalReviewService::generate() by design — both providers accept
     * identical inputs (FR-006).
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
            [
                'max_tokens' => self::MAX_TOKENS,
                'tools'      => [['googleSearch' => new \stdClass()]],
            ]
        );
    }
}
