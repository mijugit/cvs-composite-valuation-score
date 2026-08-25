<?php

declare(strict_types=1);

namespace CVS\Ai;

use DateTimeImmutable;

/**
 * Provider-agnostic prompt builder for the stage-2 "Recenzja krytyczna" —
 * change: critical-review-models.
 *
 * Both AiCriticalReviewService (Claude) and GeminiCriticalReviewService
 * (Gemini) call these static methods rather than building prompt text
 * inline, so the two providers cannot receive different prompts — FR-004
 * (identical prompt requirement) and FR-006 (identical data-block input) are
 * both satisfied structurally: there is exactly one place that could drift,
 * and it isn't inside either provider-specific service.
 *
 * This is the same text originally inlined in AiCriticalReviewService (see
 * git history for change: cvs-ai-critical-review), extended with a 5th,
 * mandatory instruction: a trailing fenced JSON block carrying the bull/bear
 * scenario probabilities plus a short justification — parsed by
 * CriticalReviewProbabilityParser.
 *
 * The same JSON block also carries a `sources` array (change: critical-
 * review-openai's follow-up fix, 2026-08-25) — deliberately NOT relying on
 * any provider's own automatic citation mechanism (Claude's Messages API
 * citation attachment is a heuristic that reliably fires on text closely
 * reproducing a source, but NOT on heavily synthesized analytical text —
 * exactly what this prompt asks every provider to write — so it silently
 * returned zero citations for Claude despite genuinely using web search).
 * Explicitly instructing every provider to write its own sources list as
 * parseable text puts source extraction under our control uniformly across
 * all four providers, instead of depending on four different citation-API
 * quirks (Gemini's groundingMetadata and GPT's annotations happen to fire
 * reliably; Claude's does not for this content style).
 */
final class CriticalReviewPrompt
{
    public static function buildUserMessage(string $ticker, string $dataBlock, string $stage1Analysis): string
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

    public static function buildSystemPrompt(): CacheableSystem
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
search for...", "Mam wystarczające dane..."). Output ONLY the four narrative
sections below plus the trailing JSON block — no preamble, no meta text
before section 1.

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

PROBABILITY BLOCK (MANDATORY, after section 4): You do not have access to a
real statistical distribution — a bare percentage would look scientific
while actually being a guess. To keep it honest, you MUST justify the number
you give, not just state it. After section 4, on its own lines, output a
fenced JSON block with EXACTLY this shape:

```json
{"bull_probability": <integer 0-100>, "bear_probability": <integer 0-100>, "rationale": "<one or two Polish sentences justifying both numbers, referencing a specific driver from your analysis above>", "sources": [{"url": "<full URL you actually saw in your web search results>", "title": "<short source name, e.g. the publication or domain>"}]}
```

bull_probability and bear_probability are independent scenario-confidence
estimates, not a forced two-outcome split — they do NOT need to sum to 100.

SOURCES FIELD (MANDATORY, part of the same JSON block): list every distinct
URL your web search actually returned and that informed section 1 or 2 above
— copy the URLs exactly as they appeared in your search results, never
invent or guess one. Deduplicate repeated URLs. If your web search returned
no usable results, `sources` MUST be an empty array `[]` — never omit the
field and never fabricate an entry to avoid an empty list. This field is
YOUR way of showing your sources directly in your answer text — do not rely
on any other citation mechanism to convey them.

Output nothing after this JSON block.

OUTPUT REQUIREMENTS:
- Write entirely in Polish (except the JSON keys, which must stay in English exactly as shown).
- Aim for 500-750 words total across the four narrative sections.
- End the four-section narrative (before the JSON block) with this exact disclaimer on its own line:
  "⚠️ Powyższa analiza to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie."
SYSTEM;

        return new CacheableSystem($text, CacheableSystem::TTL_5M);
    }
}
