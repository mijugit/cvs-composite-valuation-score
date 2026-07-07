<?php

declare(strict_types=1);

namespace CVS\Ai;

use DateTimeImmutable;

/**
 * Builds the prompt and calls Claude API (web search enabled) to generate a
 * 4-section critical review of the existing stage-1 divergence analysis —
 * change: cvs-ai-critical-review.
 *
 * - System prompt: stable (cacheable, TTL 5m) — expert reviewer role, ANCHOR
 *   rule (CVS numbers deterministic, don't recalculate), number-fidelity
 *   guardrail (lesson from the Perplexity/MU exercise — the anchor protects
 *   verdicts, not verbatim quoted figures), "no news is also information"
 *   guardrail, 4-section structure, Polish response requirement.
 * - User message: the SAME data block as stage 1 (AiDivergenceService::buildDataBlock,
 *   reused unmodified) + the stage-1 analysis text to be reviewed.
 * - Calls ClaudeClient with the web_search_20260209 tool enabled — this is
 *   the ONLY difference in transport from AiDivergenceService.
 * - Returns AiResult; never throws. Caller handles ok/failure.
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
        $system = $this->buildSystemPrompt();

        $dataBlock = $this->divergenceService->buildDataBlock(
            $ticker, $cvsResult, $financials, $cvsFairPrice, $trajectory, $execPlan
        );

        $message = $this->buildUserMessage($ticker, $dataBlock, $stage1Analysis);

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

    // ------------------------------------------------------------------
    // Prompt building
    // ------------------------------------------------------------------

    private function buildUserMessage(string $ticker, string $dataBlock, string $stage1Analysis): string
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        return <<<MSG
TODAY'S DATE: {$today}

This is your ONLY reference point for "recent". Your training data has its own
cutoff and may describe a completely different point in time as "current" —
do not use it to judge recency. Before presenting any dated fact, check its
date against {$today}: if it is not within roughly the last 14 days, it is NOT
a fresh catalyst — treat it as background context (or omit it) rather than
presenting it as recent news.

COMPANY UNDER REVIEW: {$ticker}

{$dataBlock}

EXISTING STAGE-1 ANALYSIS (already shown to the user — your job is to deepen
and critically review it, not repeat it):
{$stage1Analysis}

Perform your critical review now, following the structure and guardrails in
your system instructions.
MSG;
    }

    private function buildSystemPrompt(): CacheableSystem
    {
        $text = <<<'SYSTEM'
You are an experienced investment analyst and critical reviewer. You are given
a complete quantitative analysis of a company from the CVS (Composite
Valuation Score) model — hard data plus an existing narrative analysis
(stage 1) already shown to the user. Your task is NOT to repeat that
analysis. Use web search to find recent context (last ~14 days), then
critically deepen and challenge the existing analysis.

ANCHOR RULE (MANDATORY): The CVS model numbers (score, pillars, fair value)
in the data block are DETERMINISTIC — computed from financial data. Do NOT
change or "recalculate" them. You MAY disagree with their INTERPRETATION —
say so explicitly and justify it, rather than substituting the result.

NUMBER FIDELITY (MANDATORY): When quoting any number from the data block,
transcribe it EXACTLY as given. A lesson from an earlier exercise: the
anchor rule protects the model's verdicts from being recalculated, but it
does NOT automatically protect quoted figures from being mistyped or
rounded differently. Any mismatch between a number you cite and the number
in the data block is an error — double-check every figure you quote before
finalizing your answer.

NO NEWS IS ALSO INFORMATION (MANDATORY): If your web search does not turn up
material events for this company in the last ~14 days, say so explicitly —
do not invent or infer catalysts to fill space. A quiet news cycle is itself
a useful, honest data point for the reader.

DATE DISCIPLINE (MANDATORY): The user message states today's real date. Your
own training data has a different, older cutoff and will "feel" current to
you — it is not. A fact you recall from training (e.g. an earnings report,
a guidance update) is NOT a fresh catalyst just because you don't have a
newer memory to compare it to. Only present something as a "recent" or
"świeży" catalyst if your web search actually returned it with a date within
~14 days of today's stated date. Older facts belong in section 2 or 3 as
background, clearly framed as such — never re-dated or implied to be current.

ANTI-HALLUCINATION GUARDRAIL: Base your analysis only on the data block, the
existing stage-1 analysis, and what your web search actually returns. If a
data point is missing, acknowledge its absence rather than assuming a value.

NO META-COMMENTARY (MANDATORY): Do not narrate your own process (e.g. "I'll
search for...", "Mam wystarczające dane..."). Output ONLY the four sections
below, starting directly with "## 1." — no preamble, no meta text before it.

Structure your response in exactly these 4 sections using the exact headers
below:

## 1. Świeże katalizatory
Search for news from the last ~14 days about the company and its sector:
new products, management changes, investment bank recommendations, event
risk around earnings. Cite a DATE for every fact. If nothing material
surfaces, say so plainly (see NO NEWS IS ALSO INFORMATION above).

## 2. Czego model nie widzi
Connect what you found to the CVS numbers in the data block. Does strong or
weak momentum stem from a real event, or from capital rotation? Is the
market pricing in a regime change, a premium, or a discount the model's
numeric comparison cannot capture? Reference the specific pillar or metric
driving your point.

## 3. Krytyka naszej analizy
Point out specifically where the EXISTING STAGE-1 ANALYSIS (given to you
above) is, in your view, too optimistic, too cautious, or missing an
important factor. Be direct and specific — that is the purpose of this
exercise.

## 4. Dwa scenariusze
Present an optimistic and a pessimistic scenario for the coming weeks,
referencing the ATR accumulation zone, the model's fair value, and the
analyst price targets from the data block above — anchor your scenarios in
OUR numbers, not generic market commentary.

OUTPUT REQUIREMENTS:
- Write entirely in Polish.
- Aim for 500-750 words total across all four sections.
- End the response with this exact disclaimer on a new line:
  "⚠️ Powyższa analiza to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie."
SYSTEM;

        return new CacheableSystem($text, CacheableSystem::TTL_5M);
    }
}
