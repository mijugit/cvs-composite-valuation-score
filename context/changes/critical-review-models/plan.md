# Critical Review Models — Implementation Plan

## Overview

Extends the existing single-provider (Claude) "Recenzja krytyczna" (critical review)
feature on the analysis card into a two-provider feature (Claude + Gemini), with
independent per-(ticker, provider) storage, a new tab strip in the UI, and a new
prompt requirement: both providers must report a bull/bear probability (%) with a
short justification, alongside the existing narrative.

## Current State Analysis

The feature today is a single vertical slice, fully working in production:

- **Trigger/poll actions**: `AiAnalysisController::criticalReview()` /
  `criticalReviewStatus()` ([src/Ai/AiAnalysisController.php:296-433](src/Ai/AiAnalysisController.php:296))
  — auth → CSRF → stage-1-freshness check → **global** `isPending($ticker)` guard →
  `ProGate::canGenerate()` → `usageRepo->log(...)` (0/0 tokens, quota-only) →
  `markPending()` → `exec($cmd . ' &')` firing `bin/generate_critical_review.php` →
  `202 {ok:true, status:'pending'}`. Poll returns `{status: none|pending|completed|failed}`.
- **Data**: `ai_critical_reviews` (migration 030) — `UNIQUE KEY uq_ticker (ticker)`,
  one row per ticker. `AiCriticalReviewRepository` uses an INSERT-then-catch-duplicate-
  then-UPDATE idiom (not `ON DUPLICATE KEY UPDATE`) — same portable pattern as
  `AiAnalysisRepository::save()`.
- **Generation**: `AiCriticalReviewService` ([src/Ai/AiCriticalReviewService.php](src/Ai/AiCriticalReviewService.php))
  is Claude-only — inline `buildSystemPrompt()`/`buildUserMessage()`, calls
  `AiDivergenceService::buildDataBlock()` (already provider-agnostic plain text,
  reused unmodified from stage-1) for the CVS data block, then `ClaudeClient::sendMessage()`
  with `tools => [['type'=>'web_search_20260209', 'name'=>'web_search', 'max_uses'=>5]]`.
  System prompt mandates exactly 4 sections (`## 1.`–`## 4.`) and ends with the fixed
  disclaimer line.
- **Worker**: `bin/generate_critical_review.php <ticker> <userId>` — positional CLI
  args, no provider concept today. Re-derives CVS enrichment (fair price, trajectory,
  ATR plan) identically to `AiAnalysisController::generate()`, then calls the service
  and `markCompleted()`/`markFailed()`.
- **UI**: `#critical-review-section` in `templates/analysis.php` — a single
  `.ai-analysis-card`, one trigger button, one result pane, JS block `cr*`
  (lines ~1540-1712) with a rotating "stage" message array and manual regex-based
  markdown rendering. No CSS or JS tab pattern exists anywhere in this codebase.

**Gemini is already integrated** (from `fundamentals-validation`): `GeminiClient`/
`GeminiClientFactory` mirror `ClaudeClient`'s exact `sendMessage(messages, ?system,
options): AiResult` contract. The only caller-visible divergence is the `tools`
payload shape (Gemini: `[['googleSearch' => new \stdClass()]]`, no `max_uses` concept)
and that `searchDegraded` is always `false` for Gemini. `FundamentalsValidationService`
is a proven example of the Gemini+search+JSON-parsing call shape.

### Key Discoveries:

- `ai_usage_log` has **no provider column** — `countToday()`/`countThisMonth()` are
  bare `COUNT(*)` ([src/Pro/AiUsageRepository.php](src/Pro/AiUsageRepository.php)).
  FR-008 (shared PRO limit) requires **zero code changes** — any code path that calls
  `usageRepo->log(...)` already shares the same counters.
- `AiCriticalReviewRepository::isPending($ticker)` is currently **global per ticker**,
  not per-provider. Left unchanged, this would incorrectly block triggering Gemini
  while a Claude job is in flight for the same ticker — violating FR-002 ("każdy
  dostawca to niezależny wiersz i niezależny worker, nie ma współdzielonego zasobu
  wymagającego blokady"). Every repository read/write must become provider-scoped.
- `AiDivergenceService::buildDataBlock()` ([src/Ai/AiDivergenceService.php:186-459](src/Ai/AiDivergenceService.php:186))
  is public specifically so callers besides `generate()` can reuse it unmodified —
  already the correct FR-006 reuse point, no changes needed there.
- Neither client supports structured JSON-schema output — `FundamentalsValidationService`
  gets JSON from Gemini purely via prompt instruction + fence-stripping + `json_decode`
  ([src/Ai/FundamentalsValidationService.php:191-201](src/Ai/FundamentalsValidationService.php:191)).
  The same technique is the only option for extracting the new probability block from
  either provider's free-text response.
- `config/gemini.php` already defaults to a 180s/200s timeout budget — no
  `critical_review`-style override block (like `config/ai.php`'s) is needed for Gemini;
  it's already sized for a slow, web-search-enabled call.
- Migration numbering: the next free slot is **041** (`039`/`040` were the last used,
  by `fundamentals-validation`).

## Desired End State

A user with an active PRO code, on `/analysis/{ticker}`, sees a tab strip
("Claude" | "Gemini") inside the existing critical-review card. Each tab has its own
trigger button, its own pending/completed/failed state, its own narrative, sources,
and a new probability box (bull % / bear % + short rationale). Triggering one
provider never blocks or is blocked by the other. Switching tabs never loses a
tab's in-flight polling. Existing Claude reviews from before this change render
unchanged under the "Claude" tab. The shared PRO daily/monthly limit is unaffected
by which provider(s) a user chooses.

Verify by: opening `/analysis/MU` as a PRO user, triggering "Zleć recenzję" on the
Gemini tab while a Claude review already exists, confirming both tabs show
independent, correct state, and confirming `ai_usage_log` recorded one row per
trigger against the same shared limit.

## What We're NOT Doing

- More than 2 providers (Non-Goal, PRD).
- Any influence of the probability fields on CVS score, alerts, screener sort, or
  any other automated logic (Non-Goal, PRD — determinism guardrail).
- Version history per provider — re-triggering overwrites the provider's row exactly
  like today's Claude behavior (Non-Goal, PRD).
- A separate/expanded PRO limit for "second opinion" (Non-Goal, PRD) — explicitly
  rejected; shared limit stays.
- Any change to `ProGate`/`AiUsageRepository` limiting logic — already provider-agnostic.
- Any change to the stage-1 analysis flow, `AiAnalysisController::generate()`, or
  `AiDivergenceService::generate()` (the stage-1 Claude call) — untouched.
- A `critical_review`-style Gemini config override block — the existing
  `config/gemini.php` defaults are already sized for this call.

## Implementation Approach

Per the planning discussion: the PHP service/worker layer stays **isolated per
provider** (a new `GeminiCriticalReviewService` + a new worker script, not a branch
inside the existing Claude classes) to keep the proven Claude path completely
undisturbed. To reconcile that isolation with FR-004/FR-006's requirement that both
providers receive an **identical** prompt, the prompt-building and probability-parsing
logic is factored into two small, static, provider-agnostic helper classes that both
services call — so there is exactly one place that can drift, and it isn't inside
either provider-specific service.

The UI stays as **one card** with a tab strip, per the UX decision — implemented as
two parallel, independently-stateful content panes toggled via the `hidden` attribute
(the same visibility mechanism already used by `#critical-review-result` and
`#fv-diff` today), not a new DOM-diffing render pattern. The JS layer is
necessarily touched for both providers (there is no way to add a second tab without
generalizing the tab-switching shell), but the newer `fv-*` convention (a single
parametrized handler keyed by an argument) replaces the older `cr-*` per-instance-only
style, following the more current pattern already established by fundamentals-validation.

## Critical Implementation Details

**Prompt/parsing isolation vs. content identity**: `AiCriticalReviewService` (Claude)
and the new `GeminiCriticalReviewService` are separate classes with separate transport
wiring, but both call `CriticalReviewPrompt::buildSystemPrompt()` /
`buildUserMessage()` (new, static, provider-agnostic) rather than building prompt text
inline. This is the mechanism that satisfies FR-004/FR-006 despite the two services
being architecturally isolated — implementers must not let either service reintroduce
an inline prompt-building method, or the two providers' prompts will silently drift.

**Probability parsing happens in the worker, not the service**: `generate()` on both
services keeps returning a plain `AiResult` (unchanged Claude contract, so existing
`AiCriticalReviewServiceTest` assertions about the returned `AiResult` shape don't
need restructuring). Each worker script (`bin/generate_critical_review.php` and the
new `bin/generate_critical_review_gemini.php`) calls
`CriticalReviewProbabilityParser::parse($result->text)` immediately before
`markCompleted()`. On parse failure (missing or malformed trailing JSON block), the
parser returns the full original text as `narrative` and `null` for all three
probability fields — the worker still calls `markCompleted()` (not `markFailed()`),
so a probability-formatting hiccup from the model never costs the user the narrative
they already paid a PRO-usage unit for.

**Both provider states must be resumable independently on page load**: today,
`AnalysisController::show()` computes one `$criticalReviewStatus`/`$cachedCriticalReview`
pair. It must now compute both (Claude and Gemini) and pass both into the page's JS
init data, so that if either provider's job is `pending` when the page loads, that
tab's polling resumes even while the other tab is active — this is what the PRD's
US-01 acceptance criterion ("przełączanie zakładek nie gubi stanu pollingu w drugiej
zakładce") actually requires at the data layer, not just in the JS.

## Phase 1: Database migration + repository provider-scoping

### Overview

Add the `provider` dimension and the three new probability columns to
`ai_critical_reviews`, widen its unique constraint, and make every
`AiCriticalReviewRepository` method provider-aware. This is the foundation every
later phase depends on.

### Changes Required:

#### 1. Migration

**File**: `database/migrations/041_add_provider_and_probability_to_ai_critical_reviews.sql`

**Intent**: Additive schema change — add `provider` (backfills existing rows to
`'claude'` automatically via MySQL's `NOT NULL DEFAULT` semantics on `ADD COLUMN`, no
explicit `UPDATE` needed), add the three probability columns, widen the unique
constraint from ticker-only to `(ticker, provider)`.

**Contract**:
```sql
ALTER TABLE ai_critical_reviews
    ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT 'claude' AFTER ticker,
    ADD COLUMN bull_probability TINYINT UNSIGNED NULL AFTER sources,
    ADD COLUMN bear_probability TINYINT UNSIGNED NULL AFTER bull_probability,
    ADD COLUMN probability_rationale TEXT NULL AFTER bear_probability,
    DROP KEY uq_ticker,
    ADD UNIQUE KEY uq_ticker_provider (ticker, provider);
```

#### 2. Provider allow-list

**File**: `src/Ai/CriticalReviewProvider.php` (new)

**Intent**: A single, shared source of truth for the two valid provider values, used
by the controller (input validation), the repository (query scoping), and the view
(tab rendering) — prevents the three layers from drifting on what counts as a valid
provider.

**Contract**: `final class CriticalReviewProvider { public const CLAUDE = 'claude'; public const GEMINI = 'gemini'; public const ALL = [self::CLAUDE, self::GEMINI]; public static function isValid(string $value): bool; }`

#### 3. Repository

**File**: `src/Ai/AiCriticalReviewRepository.php`

**Intent**: Every method gains a `string $provider` parameter and scopes its
query/WHERE clause by it, preserving the existing insert-then-catch-duplicate-then-
update idiom (do not switch to `ON DUPLICATE KEY UPDATE` — that would break the
project's established portable-SQL convention). Add one new read method that fetches
both providers' rows for a ticker in a single query, for the page-load use case in
Phase 3.

**Contract**:
- `findByTickerAndProvider(string $ticker, string $provider): ?array` (renamed from `findByTicker`)
- `findAllProvidersForTicker(string $ticker): array<string, array<string, mixed>>` — new; keyed by provider, only present providers included
- `isFresh(string $ticker, string $provider, int $hours = 48): bool`
- `isPending(string $ticker, string $provider): bool`
- `markPending(string $ticker, string $provider, int $userId): void` — INSERT includes `provider`; the duplicate-catch UPDATE's `WHERE` becomes `ticker = ? AND provider = ?`
- `markCompleted(string $ticker, string $provider, string $content, array $sources, string $model, int $tokensIn, int $tokensOut, ?int $bullProbability, ?int $bearProbability, ?string $probabilityRationale): void` — `WHERE` scoped by provider; new columns included in the `UPDATE`
- `markFailed(string $ticker, string $provider, string $errorMessage): void` — `WHERE` scoped by provider

### Success Criteria:

#### Automated Verification:

- [ ] Migration applies cleanly against the local/dev DB
- [ ] `vendor/bin/phpunit tests/Ai/AiCriticalReviewRepositoryTest.php` passes (existing tests updated to pass `'claude'` explicitly; new tests cover two-provider coexistence and independent pending/completed/failed state per provider)
- [ ] `composer stan` reports no new errors

#### Manual Verification:

- [ ] `SHOW CREATE TABLE ai_critical_reviews` on the dev DB shows `uq_ticker_provider (ticker, provider)` and the three new nullable columns
- [ ] Pre-existing Claude rows (if any in the dev DB snapshot) show `provider = 'claude'` after migration

---

## Phase 2: Shared prompt/parsing helpers + Gemini service + Gemini worker

### Overview

Add the two provider-agnostic helper classes, refactor `AiCriticalReviewService` to
use the shared prompt builder (minimal, behavior-preserving change), add the new
isolated `GeminiCriticalReviewService`, and add its dedicated worker script.

### Changes Required:

#### 1. Shared prompt builder

**File**: `src/Ai/CriticalReviewPrompt.php` (new)

**Intent**: Extract `AiCriticalReviewService::buildSystemPrompt()` /
`buildUserMessage()` verbatim into static methods on this new class, then extend the
system prompt with a 5th, mandatory instruction block: after the existing 4 narrative
sections, the model must emit a fenced ` ```json ` block containing exactly
`{"bull_probability": <0-100 int>, "bear_probability": <0-100 int>, "rationale": "<short Polish justification>"}`
— framed per FR-004's Socrates resolution (the model must justify the number, not
just state it; bull+bear need not sum to 100, since they represent independent
scenario-confidence estimates, not a two-outcome probability split).

**Contract**: `static function buildSystemPrompt(): CacheableSystem`, `static function buildUserMessage(string $ticker, string $dataBlock, string $stage1Analysis): string` — same parameters as today's private methods on `AiCriticalReviewService`.

#### 2. Shared probability parser

**File**: `src/Ai/CriticalReviewProbabilityParser.php` (new)

**Intent**: Given the full raw response text (narrative + trailing JSON block), split
off and decode the JSON block, clamp `bull_probability`/`bear_probability` to
`[0, 100]`, and return the narrative with the JSON block stripped. Never throws;
degrades gracefully to "whole text is narrative, all probability fields null" on any
parse failure (missing fence, malformed JSON, missing/non-numeric keys) — mirrors
`FundamentalsValidationService::decodeJson()`'s fence-stripping approach, but must
locate the JSON block at the **tail** of a larger text rather than treating the whole
response as JSON.

**Contract**: `static function parse(string $rawText): array{narrative: string, bull_probability: ?int, bear_probability: ?int, rationale: ?string}`

#### 3. Refactor existing Claude service

**File**: `src/Ai/AiCriticalReviewService.php`

**Intent**: Replace the private `buildSystemPrompt()`/`buildUserMessage()` methods
with calls to `CriticalReviewPrompt::buildSystemPrompt()`/`buildUserMessage()`. No
change to `generate()`'s signature or return type — it still returns the raw
`AiResult` (now with the trailing JSON block included in `$result->text`, to be
stripped by the worker, not the service).

**Contract**: `generate()` signature unchanged; body's two prompt-building calls now delegate to `CriticalReviewPrompt`.

#### 4. New Gemini service

**File**: `src/Ai/GeminiCriticalReviewService.php` (new)

**Intent**: Mirror `AiCriticalReviewService`'s structure and public contract exactly,
substituting `GeminiClient` for `ClaudeClient` and Gemini's `googleSearch` tool shape
for Claude's `web_search_20260209` tool shape — same pattern as
`FundamentalsValidationService`'s Gemini call. Uses the same
`CriticalReviewPrompt`/`AiDivergenceService::buildDataBlock()` as the Claude service.

**Contract**: `final class GeminiCriticalReviewService { public function __construct(GeminiClient $client, AiDivergenceService $divergenceService) {} public function generate(string $ticker, array $cvsResult, array $financials, string $stage1Analysis, ?float $cvsFairPrice = null, ?array $trajectory = null, ?array $execPlan = null): AiResult {} }` — identical parameter list to `AiCriticalReviewService::generate()`. `MAX_TOKENS = 8192` (same budget as Claude's critical review — Gemini has no `pause_turn`-style continuation, so headroom matters even more for a single-shot call). Tools option: `['googleSearch' => new \stdClass()]` (no `max_uses` — Gemini's tool has no equivalent cap).

#### 5. New Gemini worker

**File**: `bin/generate_critical_review_gemini.php` (new)

**Intent**: Clone of `bin/generate_critical_review.php`'s structure (CLI guard, `.env`
parsing, `$_SESSION` reset, stage-1 + CVS re-enrichment, try/catch-with-`markFailed`
envelope), wired to `GeminiCriticalReviewService`/`GeminiClientFactory::fromConfig(config/gemini.php)`
instead of the Claude wiring. Calls `CriticalReviewProbabilityParser::parse($result->text)`
before `markCompleted()`, passing the parsed narrative (not the raw text) as `$content`
and the three probability fields into the extended `markCompleted()` signature. Always
passes `CriticalReviewProvider::GEMINI` to every repository call.

**Contract**: `php bin/generate_critical_review_gemini.php <ticker> <userId>` — same positional-arg contract as the Claude worker; provider is implicit in which script runs (not a third CLI arg), avoiding any risk of a caller passing a mismatched provider argument.

#### 6. Update existing Claude worker

**File**: `bin/generate_critical_review.php`

**Intent**: Minimal touch — add the `CriticalReviewProbabilityParser::parse()` call
before `markCompleted()` (same as the new Gemini worker), pass
`CriticalReviewProvider::CLAUDE` to every repository call, and pass the parsed
probability fields into the extended `markCompleted()` signature.

**Contract**: CLI contract unchanged (`<ticker> <userId>`); internal calls to the now-provider-scoped repository methods updated.

### Success Criteria:

#### Automated Verification:

- [ ] `vendor/bin/phpunit tests/Ai/CriticalReviewPromptTest.php` passes (new — asserts all 4 narrative sections + the new JSON-probability instruction are present)
- [ ] `vendor/bin/phpunit tests/Ai/CriticalReviewProbabilityParserTest.php` passes (new — happy path, malformed JSON, missing block, out-of-range clamping)
- [ ] `vendor/bin/phpunit tests/Ai/AiCriticalReviewServiceTest.php` passes (existing assertions about system-prompt content still hold; one assertion added for the new probability instruction)
- [ ] `vendor/bin/phpunit tests/Ai/GeminiCriticalReviewServiceTest.php` passes (new — mirrors `AiCriticalReviewServiceTest`, asserts `googleSearch` tool shape and reused data block)
- [ ] `composer stan` reports no new errors

#### Manual Verification:

- [ ] Manually run `php bin/generate_critical_review_gemini.php MU <userId>` against a real ticker on a dev/staging environment and confirm a `completed` row lands in `ai_critical_reviews` with `provider='gemini'` and non-null probability fields
- [ ] Confirm the existing Claude worker still produces a correct `completed` row (regression check) with `provider='claude'`

---

## Phase 3: Controller, routes, and page-load provider-awareness

### Overview

Wire the `provider` parameter through the trigger/status endpoints, and make the
analysis page's server-side render fetch both providers' state on load.

### Changes Required:

#### 1. Trigger endpoint

**File**: `src/Ai/AiAnalysisController.php` — `criticalReview()`

**Intent**: Read an optional `provider` request param (default `claude`, per the
PRD's backward-compatibility contract), validate it against
`CriticalReviewProvider::isValid()` (400 on invalid), and thread it through the
now-provider-scoped `isPending()`/`markPending()` calls. Branch which worker script
to `exec()` based on the validated provider. Everything else (auth, CSRF, exec-
availability guard, stage-1-freshness check, PRO gate, usage log) is unchanged and
stays provider-agnostic — stage-1 is the same shared input for both providers (FR-006).

**Contract**: Method signature unchanged (`criticalReview(Request $req): void`); reads `$req->param('provider', CriticalReviewProvider::CLAUDE)`.

#### 2. Status endpoint

**File**: `src/Ai/AiAnalysisController.php` — `criticalReviewStatus()`

**Intent**: Same param-read/validate as the trigger endpoint; calls
`findByTickerAndProvider()`; the `completed` response payload gains
`bull_probability`, `bear_probability`, `probability_rationale` alongside the
existing `content`/`sources`/`generated_at`/`stale` fields.

**Contract**: Method signature unchanged (`criticalReviewStatus(Request $req): void`); JSON response shape extended with 3 new nullable fields on the `completed` branch.

#### 3. Page-load render

**File**: `src/CVS/AnalysisController.php` — `show()` (currently lines 248-264)

**Intent**: Replace the single `findByTicker($ticker)` call with
`findAllProvidersForTicker($ticker)`, and build the existing `$cachedCriticalReview`/
`$criticalReviewStatus` shape twice — once per provider — passed to the view as a
provider-keyed structure so both tabs' initial JS state (including whether either is
still `pending`) is available at page load without an extra round-trip.

**Contract**: View-data keys change from single `criticalReviewStatus`/`cachedCriticalReview` to a provider-keyed structure, e.g. `criticalReviewByProvider['claude'|'gemini'] => ['status' => ..., 'cached' => ...]`.

### Success Criteria:

#### Automated Verification:

- [ ] `vendor/bin/phpunit tests/Ai/AiAnalysisControllerCriticalReviewTest.php` passes (existing structural tests still pass unmodified — method signatures don't change; route registrations don't change since no new routes are added)
- [ ] `composer stan` reports no new errors

#### Manual Verification:

- [ ] `POST /analysis/MU/critical-review` with no `provider` param behaves exactly as before (defaults to Claude) — backward compatibility confirmed
- [ ] `POST /analysis/MU/critical-review` with `provider=gemini` while a Claude review is already `completed` for MU does not error or block, and does not disturb the Claude row
- [ ] Triggering Gemini while Claude is `pending` for the same ticker succeeds immediately (not blocked by the old global `isPending` behavior)
- [ ] `GET /analysis/MU/critical-review/status?provider=gemini` returns the Gemini row independently of the Claude row's status

---

## Phase 4: UI — tab strip, dual panes, JS refactor, CSS

### Overview

Build the tab strip and the two independently-stateful content panes inside the
existing `#critical-review-section` card, generalize the trigger/poll/render JS to be
provider-parametrized (following the newer `fv-*` convention), and add the
probability box.

### Changes Required:

#### 1. CSS

**File**: `public/css/app.css`

**Intent**: Add the first tab-strip pattern in this codebase, plus the probability-box
styling. Reuse the existing `hidden`-attribute visibility convention for pane
switching rather than inventing a new mechanism.

**Contract**: New classes: `.cr-tabs`, `.cr-tabs__item`, `.cr-tabs__item.is-active`, `.cr-probability`, `.cr-probability__bar`, `.cr-probability__bull`, `.cr-probability__bear`, `.cr-probability__rationale`.

#### 2. Template restructure

**File**: `templates/analysis.php` — `#critical-review-section` block (currently lines ~1052-1150)

**Intent**: Wrap the existing single-provider markup into two parallel panes (each
keeping today's element structure, ids suffixed by provider — e.g.
`critical-review-result-claude` / `-gemini`), gated behind a new tab strip. PRO-gating
(`$canGenerateAi` button-swap) applies independently inside each pane, since the limit
check is the same for both but each pane needs its own trigger button. Add the new
probability box below the sources list in each pane, rendered only when the provider's
`bull_probability` is non-null.

**Contract**: New tab-strip markup with `data-provider="claude"|"gemini"` buttons; existing element ids gain a `-{provider}` suffix; loop/duplicate the existing per-provider block for both providers from the `criticalReviewByProvider` view data (Phase 3).

#### 3. JS refactor

**File**: `templates/analysis.php` — inline JS block (currently lines ~1540-1712)

**Intent**: Generalize the `cr*` functions to take a `provider` argument and read/write
a small per-provider state object (`crState = {claude: {...}, gemini: {...}}`) instead
of the current single set of module-level variables — following the `fv-*` pattern's
single-parametrized-handler convention rather than duplicating the whole block per
provider. Each provider keeps its own poll `setInterval`, independent of which tab is
currently visible, so switching tabs never interrupts the other provider's in-flight
polling (the acceptance criterion from US-01). Tab-click handlers only toggle the
`hidden` attribute on the two panes; they never start/stop polling. On page load, both
providers' initial state (from Phase 3's `criticalReviewByProvider`) is checked, and
polling resumes independently for either one if its status is `pending`, regardless
of which tab is active by default (Claude).

**Contract**: `crHandleClick(provider)`, `crPoll(provider)`, `crStartPolling(provider)`, `crRenderCompleted(provider, content, sources, probability, generatedAt)` — provider-parametrized versions of today's fixed functions; new `crRenderCompleted` param for the probability object `{bull_probability, bear_probability, rationale}`.

### Success Criteria:

#### Automated Verification:

- [ ] `composer stan` reports no new errors (template/JS aren't PHPStan-scoped, but any new PHP view-data plumbing is)

#### Manual Verification:

- [ ] On `/analysis/MU` as a PRO user: both tabs render, clicking "Gemini" switches the visible pane without losing the Claude pane's content
- [ ] Triggering a review on one tab shows that tab's own pending state (rotating status / disabled button) without affecting the other tab's button or content
- [ ] Reloading the page mid-generation (one provider `pending`) resumes polling correctly for that provider only, regardless of which tab is active on load
- [ ] The probability box renders consistently (same visual format) on both tabs once each completes
- [ ] A provider whose response fails probability-JSON parsing still shows its narrative, with no probability box (graceful degradation) and no error surfaced to the user
- [ ] Existing pre-migration Claude reviews (if present on staging) render unchanged under the Claude tab
- [ ] No regression on the stage-1 AI analysis section or any other part of the analysis page

---

## Phase 5: Test suite completion

### Overview

Fill in remaining test coverage gaps and confirm the full suite is green.

### Changes Required:

#### 1. Repository tests

**File**: `tests/Ai/AiCriticalReviewRepositoryTest.php`

**Intent**: Update the in-memory SQLite fixture schema to include `provider`,
`bull_probability`, `bear_probability`, `probability_rationale`, and a
`(ticker, provider)` unique constraint. Update every existing test call to pass
`'claude'` explicitly (preserving today's coverage). Add new tests: two providers can
coexist for the same ticker with independent `pending`/`completed`/`failed` states;
`findAllProvidersForTicker()` returns both when both exist and only one when only one
exists.

#### 2. Prompt/parser unit tests

**Files**: `tests/Ai/CriticalReviewPromptTest.php` (new), `tests/Ai/CriticalReviewProbabilityParserTest.php` (new)

**Intent**: `CriticalReviewPromptTest` asserts the shared builder's output contains all
4 narrative section headers plus the new JSON-probability instruction (guards against
future accidental prompt drift between providers, since both services depend on this
one class). `CriticalReviewProbabilityParserTest` covers: well-formed trailing JSON
block, JSON wrapped in a ` ```json ` fence, missing block (whole text is narrative),
malformed JSON (graceful fallback), and out-of-range percentages being clamped to
`[0, 100]`.

#### 3. Service tests

**File**: `tests/Ai/GeminiCriticalReviewServiceTest.php` (new)

**Intent**: Mirror `AiCriticalReviewServiceTest`'s structure (same `FakeTransport`
pattern as `GeminiClientTest.php`), asserting the `googleSearch` tool shape is sent,
the shared data block is reused, and the shared system prompt (via
`CriticalReviewPrompt`) is present in the request body.

#### 4. Controller tests

**File**: `tests/Ai/AiAnalysisControllerCriticalReviewTest.php`

**Intent**: Following the established structural-only pattern for this file (`Response::json()`
calls `exit()`, so end-to-end HTTP tests aren't possible here — same constraint
documented in this file's own header comment) — no changes needed to existing tests
since neither method's signature changes. Optionally add one structural assertion
that routes.php still registers exactly the same 2 critical-review routes (guards
against an accidental route-shape change, since no new routes are being added for
the `provider` param — it travels as a request param, not a path segment).

### Success Criteria:

#### Automated Verification:

- [ ] `vendor/bin/phpunit` (full suite) passes with zero failures
- [ ] `composer stan` reports no errors

#### Manual Verification:

- [ ] None — this phase is test-only

---

## Testing Strategy

### Unit Tests:

- Provider-scoping correctness in the repository (isolation between `claude`/`gemini`
  rows for the same ticker; `UNIQUE(ticker, provider)` behavior via the duplicate-catch
  idiom).
- Prompt-content identity guaranteed structurally by `CriticalReviewPromptTest`
  (both services depend on the one shared builder — there's no per-provider prompt
  test to duplicate).
- Probability-parsing robustness: well-formed, fenced, missing, malformed, and
  out-of-range inputs.
- Gemini transport call shape (tool payload, reused data block) — mirrors the
  existing Claude coverage exactly.

### Integration Tests:

- None new — the existing structural-only controller test pattern (imposed by
  `Response::json()`'s `exit()`) is preserved; end-to-end behavior is verified
  manually per phase.

### Manual Testing Steps:

1. On a PRO account, open `/analysis/MU` (or any ticker with an existing Claude
   review) and confirm the Claude tab shows the pre-existing review unchanged.
2. Click the Gemini tab, trigger a review, and confirm it completes independently
   with its own narrative, sources, and probability box.
3. While Gemini is still `pending`, switch back to the Claude tab and trigger a
   Claude refresh — confirm both jobs run concurrently without either blocking the
   other.
4. Reload the page mid-generation and confirm polling resumes correctly for whichever
   provider(s) were still pending.
5. Check `ai_usage_log` after both triggers — confirm exactly 2 rows, both counted
   against the same shared daily/monthly limit.
6. Confirm the CVS score, screener, and portfolio pages are visually and
   numerically unaffected (determinism guardrail / non-goal check).

## Performance Considerations

No new performance surface — Gemini's critical-review call runs in the same
detached-background-worker pattern as Claude's (never blocks a synchronous request),
and `config/gemini.php`'s existing 180s/200s timeout budget is already sized for a
web-search-enabled call (no new config override needed).

## Migration Notes

Migration 041 is purely additive (new nullable columns, a `NOT NULL DEFAULT`-backfilled
column, and a widened unique constraint) — no existing data is at risk. Deploy to
production follows the same established pattern as `fundamentals-validation`'s
migrations 039/040 (SSH + `MYSQL_PWD` env var, per project convention — never `-p'password'`
inline).

## References

- Shape notes: `context/foundation/shape-notes-critical-review-models.md`
- PRD: `context/foundation/prd-critical-review-models.md`
- Prior art (Gemini+search+JSON prompt shape): `src/Ai/FundamentalsValidationService.php`
- Prior art (async job pattern, provider-agnostic upsert idiom): `src/Ai/AiCriticalReviewRepository.php`, `context/changes/fundamentals-validation/plan.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Database migration + repository provider-scoping

#### Automated

- [x] 1.1 Migration applies cleanly against the local/dev DB — a445da2
- [x] 1.2 `vendor/bin/phpunit tests/Ai/AiCriticalReviewRepositoryTest.php` passes — a445da2
- [x] 1.3 `composer stan` reports no new errors — a445da2

#### Manual

- [x] 1.4 `SHOW CREATE TABLE ai_critical_reviews` shows the new unique key and columns — a445da2
- [x] 1.5 Pre-existing Claude rows show `provider = 'claude'` after migration — a445da2

### Phase 2: Shared prompt/parsing helpers + Gemini service + Gemini worker

#### Automated

- [x] 2.1 `vendor/bin/phpunit tests/Ai/CriticalReviewPromptTest.php` passes
- [x] 2.2 `vendor/bin/phpunit tests/Ai/CriticalReviewProbabilityParserTest.php` passes
- [x] 2.3 `vendor/bin/phpunit tests/Ai/AiCriticalReviewServiceTest.php` passes
- [x] 2.4 `vendor/bin/phpunit tests/Ai/GeminiCriticalReviewServiceTest.php` passes
- [x] 2.5 `composer stan` reports no new errors

#### Manual

- [ ] 2.6 Manual worker run for Gemini produces a correct `completed` row with probabilities
- [ ] 2.7 Existing Claude worker still produces a correct `completed` row (regression check)

### Phase 3: Controller, routes, and page-load provider-awareness

#### Automated

- [ ] 3.1 `vendor/bin/phpunit tests/Ai/AiAnalysisControllerCriticalReviewTest.php` passes
- [ ] 3.2 `composer stan` reports no new errors

#### Manual

- [ ] 3.3 No-provider-param request defaults to Claude (backward compatibility)
- [ ] 3.4 Gemini trigger while Claude `completed` succeeds without disturbing Claude's row
- [ ] 3.5 Gemini trigger while Claude `pending` succeeds immediately (not blocked)
- [ ] 3.6 Status endpoint with `provider=gemini` returns the Gemini row independently

### Phase 4: UI — tab strip, dual panes, JS refactor, CSS

#### Automated

- [ ] 4.1 `composer stan` reports no new errors

#### Manual

- [ ] 4.2 Both tabs render; switching tabs preserves the other pane's content
- [ ] 4.3 Triggering one tab doesn't affect the other tab's button/content
- [ ] 4.4 Reload mid-generation resumes polling correctly regardless of active tab
- [ ] 4.5 Probability box renders consistently across both tabs
- [ ] 4.6 Probability-parse failure degrades gracefully (narrative shown, no error)
- [ ] 4.7 Pre-migration Claude reviews render unchanged under the Claude tab
- [ ] 4.8 No regression on stage-1 AI analysis or the rest of the analysis page

### Phase 5: Test suite completion

#### Automated

- [ ] 5.1 Full `vendor/bin/phpunit` suite passes with zero failures
- [ ] 5.2 `composer stan` reports no errors
