<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Calls Claude API (web search enabled) to generate a 4-section critical
 * review of the existing stage-1 divergence analysis, plus a trailing
 * bull/bear probability block — change: cvs-ai-critical-review, extended by
 * change: critical-review-models.
 *
 * - Prompt (system + user message): built by CriticalReviewPrompt — shared,
 *   provider-agnostic, so this class never diverges from GeminiCriticalReviewService's
 *   prompt content.
 * - User message: the SAME data block as stage 1 (AiDivergenceService::buildDataBlock,
 *   reused unmodified) + the stage-1 analysis text to be reviewed.
 * - Calls ClaudeClient with the web_search_20260209 tool enabled — this is
 *   the ONLY difference in transport from AiDivergenceService, and the only
 *   thing that differs from GeminiCriticalReviewService's transport call.
 * - Returns AiResult with the raw response text (narrative + trailing JSON
 *   block still attached); the caller (worker script) runs
 *   CriticalReviewProbabilityParser::parse() to split them apart. Never
 *   throws. Caller handles ok/failure.
 */
final class AiCriticalReviewService
{
    /**
     * Room for 500-750 words across 4 sections plus citation text. Measured
     * live (2026-07-07, real MU quality spike): the tool-use loop (web
     * search, sometimes routed through a code_execution sandbox) consumes
     * output-token budget too, not just the final visible text — 2048 cut a
     * real response off mid-sentence at ~4200 accounted output tokens.
     */
    private const MAX_TOKENS = 8192;

    /** Cost/time safety cap — see context/changes/cvs-ai-critical-review/koncepcja.md. */
    private const MAX_SEARCH_USES = 5;

    public function __construct(
        private readonly ClaudeClient          $client,
        private readonly AiDivergenceService   $divergenceService,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Generate the critical review narrative.
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
                'tools'      => [[
                    'type'     => 'web_search_20260209',
                    'name'     => 'web_search',
                    'max_uses' => self::MAX_SEARCH_USES,
                ]],
            ]
        );
    }
}
