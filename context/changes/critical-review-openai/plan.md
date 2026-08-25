# Critical Review OpenAI (GPT-5.6 Terra + Luna) — Implementation Plan

## Overview

Adds two new critical-review providers — GPT-5.6 Terra and GPT-5.6 Luna (OpenAI
Responses API) — as a deliberate expansion beyond `critical-review-models`'
"max 2 providers" Non-Goal. Per the user's explicit framing, this is a natural
extension of the existing mechanism (same shared prompt, same data block, same
async job pattern), not a new feature: reuse the proven Claude/Gemini
architecture exactly, adding only what's genuinely new.

## Current State Analysis

- `CriticalReviewProvider::ALL = ['claude', 'gemini']` is the single allow-list
  consumed by the controller's validation and the repository's query scoping.
- Two isolated services already exist — `AiCriticalReviewService` (Claude) and
  `GeminiCriticalReviewService` (Gemini) — each paired with its own worker
  script, both depending on the shared `CriticalReviewPrompt` (prompt content)
  and (via the worker) `CriticalReviewProbabilityParser` (response parsing).
  Neither client/service pair was duplicated because of API differences that
  don't apply here — see Key Discoveries.
- `AiAnalysisController::criticalReview()` currently picks the worker
  script/log filename via a two-way ternary on `provider === GEMINI` — not yet
  a lookup structure, since only 2 providers have existed until now.
- `templates/analysis.php` declares `$crProviderLabels` (PHP, for tab/pane
  rendering) and `CR_PROVIDERS` (JS, for the `crState` setup loop)
  independently — both are local 2-entry arrays, not derived from
  `CriticalReviewProvider::ALL`.
- No OpenAI/GPT integration exists anywhere in this codebase yet.
- Production `.env` is already configured (user-provided) with everything
  needed: `GPT_Terra_CVS` / `GPT_Luna_CVS` (separate API keys per flavor),
  `GPT_MODEL_Terra=gpt-5.6-terra` / `GPT_MODEL_Luna=gpt-5.6-luna`,
  `GPT_BASE_URL=https://api.openai.com/v1/responses`, `GPT_MAX_TOKENS=8000`,
  `GPT_TIMEOUT=180`, `GPT_MAX_RETRIES=2`, `GPT_TOTAL_TIMEOUT=200`,
  `GPT_RETRY_BASE_DELAY_MS=500`.

### Key Discoveries:

- **Terra and Luna are the SAME REST API shape** — verified directly against
  OpenAI's current API reference (not taken on faith from a pasted ChatGPT
  answer, which got one detail wrong — see below): both are Responses API
  (`POST https://api.openai.com/v1/responses`), same request/response
  structure, differing only in `model` (and, per the user's own `.env`, a
  separate API key per flavor). Unlike Claude vs Gemini (genuinely different
  endpoints/auth headers/body shapes, which is why they're separate client
  classes), Terra vs Luna do NOT warrant two client classes or two service
  classes — one `GPTClient` + one `GPTCriticalReviewService`, each
  instantiated twice (once per flavor's config), is the correct amount of
  code, not an under-isolation shortcut. Two separate worker scripts still
  give each flavor its own independent async job, preserving the
  per-provider isolation that matters (concurrent triggering, independent
  poll/DB rows).
- **Verified request shape**: `{"model": ..., "instructions": "<system prompt>", "input": [{"role": "user", "content": "..."}], "tools": [{"type": "web_search"}], "reasoning": {"effort": "low"|"medium"|"high"}, "max_output_tokens": N}`.
- **Verified response shape**: generated text is nested at
  `output[].content[].text` (only for `output[]` items where `type ===
  "message"`, and within those, `content[]` items where `type ===
  "output_text"` — other item types, e.g. web-search tool-call records, can
  appear in the same array and must be skipped, mirroring how
  `GeminiClient::parseSuccess()` already filters `candidates[0].content.parts[]`
  for `text` fields only). Token usage is `usage.input_tokens` /
  `usage.output_tokens` (no cache-token fields — same as Gemini, `AiUsage`'s
  last two constructor args are always `0`).
- **One correction to the pasted ChatGPT answer**: current OpenAI docs state
  *"For new Responses API integrations, use `{"type": "web_search"}`. The
  earlier `web_search_preview` tool remains available for legacy
  integrations."* — GPT recommended the legacy `web_search_preview` name;
  this plan uses `web_search`.
- **Web search is not optional for either flavor** — `CriticalReviewPrompt`'s
  shared system prompt already mandates "Use web search to find recent
  context" as part of the one prompt contract all providers share (FR-004/
  FR-006-equivalent from `critical-review-models`). Disabling it for Luna to
  save the ~$0.01/call search fee would make Luna's actual behavior diverge
  from the prompt it's given — not a real cost lever, just a broken contract.
  Both flavors get `tools: [{"type": "web_search"}]`, unconditionally.
- **`GPT_MAX_TOKENS=8000` (not 8192) is intentional** — the user already set
  this value in production `.env` before this plan existed. Unlike Claude/
  Gemini's services (which hardcode `MAX_TOKENS = 8192` as a class constant,
  because no pre-existing config value existed when those were built),
  `GPTCriticalReviewService` does NOT hardcode a token budget — it omits
  `max_tokens` from `options`, letting `GPTClient` fall through to
  `config['max_tokens']` (8000, from the user's own `.env`). This respects
  configuration the user already deliberately set rather than silently
  overriding it.
- **Reasoning effort is configurable, not hardcoded to a specific enum** — the
  verified value set is `low`/`medium`/`high` (not the wider `none`/`xhigh`/
  `max` list ChatGPT mentioned, which either applies to a different model
  family or wasn't accurately reported). Default `medium` for both flavors
  (matches the user's own stated goal of a fair, apples-to-apples comparison
  between Terra and Luna — varying reasoning effort per flavor would
  confound the comparison), read from `config/gpt.php`, overridable via env
  without a code change.
- **Citations are attempted defensively, not guaranteed** — whether OpenAI
  attaches source URLs via an `annotations` array on `output_text` content
  items was not independently confirmed against a live response. `GPTClient`
  attempts to read `annotations[].type === 'url_citation'` entries (deduped
  by URL, mirroring `GeminiClient`'s citation-dedup pattern) and degrades to
  an empty citations list if the shape doesn't match — never a hard failure.
  Phase 1's manual verification step confirms this against a real response.

## Desired End State

A PRO user sees FOUR tabs on the critical-review card: Claude, Gemini, GPT
Terra, GPT Luna. Each triggers, polls, and displays independently (existing
provider-keyed `crState` mechanics already generalize — no per-provider
special-casing needed beyond adding two more array entries). Both GPT flavors
use the real, already-configured production API keys, genuinely call OpenAI's
Responses API with web search, and produce the same narrative + bull/bear
probability format as Claude/Gemini (identical shared prompt).

Verify by: triggering GPT Terra and GPT Luna independently (including
concurrently with each other and with Claude/Gemini) for a real ticker,
confirming both produce a correctly-formatted, web-search-grounded review, and
confirming each flavor's `ai_critical_reviews` row shows realistic token usage
matching the flavor's actual model.

## What We're NOT Doing

- Not adding a cost-tracking/comparison dashboard between providers — out of
  scope; the user's comparison is manual (trigger both, read the results).
- Not building a "which model is cheaper" UI indicator — this plan only adds
  the providers; any future cost-visibility feature is separate.
- Not touching Claude or Gemini's existing service/worker code, beyond the
  mechanical extension of the shared provider lookup structures (Changes
  Required #4 in Phase 2) that all four providers now go through identically.
- Not deriving `$crProviderLabels`/`CR_PROVIDERS` from `CriticalReviewProvider::ALL`
  automatically (e.g. via a JSON-encoded PHP-to-JS bridge) — out of scope
  refactor; both arrays are extended by hand, in place, matching how they
  were originally written.

## Implementation Approach

Phase 1 builds the new, fully offline-testable backend infrastructure (client,
factory, config, service) with zero risk to the live app — nothing is wired in
yet. Phase 2 does the wiring: provider registration, two new worker scripts,
the controller's script-selection lookup, and the UI's fourth/fifth... no,
third/fourth tab. This mirrors `critical-review-models`' own phase split
(backend infra before UI wiring), compressed from 5 phases to 2 because the
provider-agnostic scaffolding (repository, controller param-handling, tab
strip, `crState` JS pattern) already exists and needs extension, not
invention.

## Phase 1: GPTClient + GPTCriticalReviewService (backend infrastructure)

### Overview

New, isolated, offline-testable OpenAI Responses API client and critical-review
service — nothing consumes them yet, so this phase cannot regress the live app.

### Changes Required:

#### 1. Config

**File**: `config/gpt.php` (new)

**Intent**: Mirror `config/gemini.php`'s shape (plain array, env-sourced, never hardcoded secrets), extended with per-flavor sub-arrays since Terra/Luna have separate API keys and model IDs but share every other setting.

**Contract**:
```php
return [
    'base_url'            => (string) ($_ENV['GPT_BASE_URL'] ?? 'https://api.openai.com/v1/responses'),
    'max_tokens'          => (int) ($_ENV['GPT_MAX_TOKENS'] ?? 8000),
    'timeout'             => (int) ($_ENV['GPT_TIMEOUT'] ?? 180),
    'max_retries'         => (int) ($_ENV['GPT_MAX_RETRIES'] ?? 2),
    'total_timeout'       => (int) ($_ENV['GPT_TOTAL_TIMEOUT'] ?? 200),
    'retry_base_delay_ms' => (int) ($_ENV['GPT_RETRY_BASE_DELAY_MS'] ?? 500),
    'reasoning_effort'    => (string) ($_ENV['GPT_REASONING_EFFORT'] ?? 'medium'),
    'terra' => ['api_key' => (string) ($_ENV['GPT_Terra_CVS'] ?? ''), 'model' => (string) ($_ENV['GPT_MODEL_Terra'] ?? 'gpt-5.6-terra')],
    'luna'  => ['api_key' => (string) ($_ENV['GPT_Luna_CVS']  ?? ''), 'model' => (string) ($_ENV['GPT_MODEL_Luna']  ?? 'gpt-5.6-luna')],
];
```

#### 2. GPTClient

**File**: `src/Ai/GPTClient.php` (new)

**Intent**: Mirrors `ClaudeClient`/`GeminiClient`'s exact public contract (`sendMessage(messages, ?system, options): AiResult`, never throws, same retry/backoff/timeout-budget loop) for OpenAI's Responses API. Class name matches what the user already referenced in their `.env` comment (`CVS\Ai\GPTClient`).

**Contract**: `final class GPTClient { public function __construct(array $config, HttpTransport $transport) {} public function sendMessage(array $messages, ?CacheableSystem $system = null, array $options = []): AiResult {} }`. Request body per the verified shape in Key Discoveries (`instructions` from `$system->text`, `input` as `[{role, content}]`, `tools` passed through from `$options['tools']`, `reasoning.effort` from config, `max_output_tokens` from `$options['max_tokens'] ?? $config['max_tokens']`). Auth header: `Authorization: Bearer <api_key>` (not a custom header name like Claude/Gemini). Success parsing extracts text only from `output[]` items where `type === 'message'`, `content[]` items where `type === 'output_text'`; usage from `usage.input_tokens`/`usage.output_tokens`; citations attempted from `annotations[].type === 'url_citation'` (deduped by URL), empty list on any shape mismatch. Error mapping mirrors `GeminiClient::interpret()` (401/403→Auth, 429→RateLimited, 500/502/503→Overloaded, 504→Timeout, quota/billing substring→Quota, else BadResponse), reading the error message from `error.message`/`error.type`/`error.code` in the decoded body.

#### 3. GPTClientFactory

**File**: `src/Ai/GPTClientFactory.php` (new)

**Intent**: Single construction point, like `GeminiClientFactory`, but takes a `$flavor` argument ('terra'|'luna') to flatten the per-flavor sub-array (`api_key`, `model`) into the shared config before constructing the client — `GPTClient` itself stays flavor-agnostic, same as `ClaudeClient`/`GeminiClient` know nothing about which "product tier" they're serving.

**Contract**: `final class GPTClientFactory { public static function fromConfig(array $config, string $flavor, ?HttpTransport $transport = null): GPTClient {} }` — merges `$config` with `$config[$flavor]`, strips the `terra`/`luna` sub-array keys before passing to `new GPTClient(...)`.

#### 4. GPTCriticalReviewService

**File**: `src/Ai/GPTCriticalReviewService.php` (new)

**Intent**: One shared service for both flavors (see Key Discoveries — Terra/Luna don't warrant separate service classes), mirroring `GeminiCriticalReviewService`'s structure: same `CriticalReviewPrompt`/`AiDivergenceService::buildDataBlock()` reuse, same parameter list, same return type. Does NOT hardcode a `MAX_TOKENS` class constant (see Key Discoveries) — omits `max_tokens` from `options` so `GPTClient` falls through to the user's configured `8000`.

**Contract**: `final class GPTCriticalReviewService { public function __construct(GPTClient $client, AiDivergenceService $divergenceService) {} public function generate(string $ticker, array $cvsResult, array $financials, string $stage1Analysis, ?float $cvsFairPrice = null, ?array $trajectory = null, ?array $execPlan = null): AiResult {} }` — identical signature to `AiCriticalReviewService`/`GeminiCriticalReviewService::generate()`. `tools` option: `[['type' => 'web_search']]`.

#### 5. Tests

**Files**: `tests/Ai/GPTClientTest.php` (new), `tests/Ai/GPTCriticalReviewServiceTest.php` (new)

**Intent**: Mirror `GeminiClientTest.php`/`GeminiCriticalReviewServiceTest.php`'s structure exactly (same `FakeTransport` pattern), adapted to the Responses API request/response shape. Cover: successful parse (text + usage), the `web_search` tool is sent, `Authorization: Bearer` header (not a custom header name), error-status mapping (at minimum 401→Auth, 429→RateLimited), and — for the service test — that `CriticalReviewPrompt`'s shared content (all 4 section headers + the probability-block instruction) reaches the request body, same as `GeminiCriticalReviewServiceTest`'s equivalent assertion.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/Ai/GPTClientTest.php` passes
- `vendor/bin/phpunit tests/Ai/GPTCriticalReviewServiceTest.php` passes
- `composer stan` reports no new errors
- `php -l config/gpt.php src/Ai/GPTClient.php src/Ai/GPTClientFactory.php src/Ai/GPTCriticalReviewService.php` passes

#### Manual Verification:

- A one-off script (or `php -a`/manual CLI call) exercises `GPTClientFactory::fromConfig(require 'config/gpt.php', 'terra')->sendMessage(...)` against the REAL production API key for a small test prompt, confirming: the request succeeds, `output[].content[].text` parsing matches the real response shape, `usage.input_tokens`/`usage.output_tokens` populate correctly, and — the one genuinely unverified detail — whether citations actually appear via `annotations[].type === 'url_citation'` or need a shape adjustment
- Repeat the same smoke check for the `luna` flavor with its own API key

---

## Phase 2: Provider wiring — workers, controller, UI

### Overview

Registers the two new providers everywhere the app currently enumerates
`CriticalReviewProvider::ALL`, adds their independent worker scripts, and
extends the tab strip to 4 tabs.

### Changes Required:

#### 1. Provider registration

**File**: `src/Ai/CriticalReviewProvider.php`

**Intent**: Add the two new provider identifiers to the shared allow-list, following the user's own naming (`gpt_terra`/`gpt_luna`, matching their `.env` var naming convention).

**Contract**: `public const GPT_TERRA = 'gpt_terra'; public const GPT_LUNA = 'gpt_luna';` added to `ALL`.

#### 2. Two new worker scripts

**Files**: `bin/generate_critical_review_gpt_terra.php` (new), `bin/generate_critical_review_gpt_luna.php` (new)

**Intent**: Clone of `bin/generate_critical_review_gemini.php`'s structure (CLI guard, `.env` parsing, stage-1 + CVS re-enrichment, try/catch-with-`markFailed` envelope, `CriticalReviewProbabilityParser::parse()` before `markCompleted()`), wired to `GPTCriticalReviewService`/`GPTClientFactory::fromConfig(require 'config/gpt.php', 'terra'|'luna')` instead. Each script hardcodes its own flavor — provider is implicit in which script runs (same reasoning as the existing Gemini worker: avoids any risk of a caller passing a mismatched provider argument).

**Contract**: `php bin/generate_critical_review_gpt_terra.php <ticker> <userId>` / `php bin/generate_critical_review_gpt_luna.php <ticker> <userId>` — same positional-arg contract as the existing workers.

#### 3. Controller script-selection lookup

**File**: `src/Ai/AiAnalysisController.php` — `criticalReview()`

**Intent**: Replace the current two-way ternary (`provider === GEMINI ? ... : ...`) with a lookup covering all 4 providers, since a ternary can't scale past 2 branches cleanly.

**Contract**: A `match ($provider) { CriticalReviewProvider::GEMINI => ..., CriticalReviewProvider::GPT_TERRA => ..., CriticalReviewProvider::GPT_LUNA => ..., default => <claude worker/log names> }` (or equivalent array lookup) replacing both the `$scriptName` and `$logName` ternaries.

#### 4. UI — fourth and fifth tabs

**File**: `templates/analysis.php`

**Intent**: Extend `$crProviderLabels` (PHP, tab/pane rendering) and `CR_PROVIDERS` (JS, `crState` setup loop) with the two new entries — both arrays already drive fully generic rendering/JS logic (the `foreach`/`.forEach` loops don't special-case provider count), so no other template changes are needed beyond the two array literals.

**Contract**: `$crProviderLabels = ['claude' => 'Claude', 'gemini' => 'Gemini', 'gpt_terra' => 'GPT Terra', 'gpt_luna' => 'GPT Luna'];` and the JS `var CR_PROVIDERS = ['claude', 'gemini', 'gpt_terra', 'gpt_luna'];` — both in the same locations as the existing 2-entry declarations.

#### 5. Tests

**File**: `tests/Ai/AiAnalysisControllerCriticalReviewTest.php`

**Intent**: Update the existing route-count/provider-param regression tests if their assertions hardcode the 2-provider count anywhere; otherwise no changes needed (the file's structural tests don't enumerate providers by name beyond the existing `input()`/`query()` regression guard, which is provider-agnostic).

### Success Criteria:

#### Automated Verification:

- `php -l templates/analysis.php bin/generate_critical_review_gpt_terra.php bin/generate_critical_review_gpt_luna.php src/Ai/AiAnalysisController.php src/Ai/CriticalReviewProvider.php` passes
- `vendor/bin/phpunit` (full suite) passes with zero failures
- `composer stan` reports no new errors

#### Manual Verification:

- On `/analysis/{ticker}` as a PRO user, all 4 tabs render (Claude, Gemini, GPT Terra, GPT Luna)
- Triggering GPT Terra produces a real, web-search-grounded review with a correctly-formatted probability block, using the `gpt-5.6-terra` model and its own API key
- Triggering GPT Luna produces the same, using `gpt-5.6-luna` and its own separate API key
- Triggering GPT Terra and GPT Luna concurrently (while Claude/Gemini may also be running) shows 4 independent modal/tab lifecycles, none blocking or disturbing another
- `ai_critical_reviews` rows for both new providers show realistic, non-zero token usage matching each flavor's actual API response
- No regression to Claude/Gemini's existing behavior

---

## Testing Strategy

### Unit Tests:

- `GPTClient` request/response shape (Responses API), auth header, tool inclusion, error-status mapping — mirrors `GeminiClientTest` coverage.
- `GPTCriticalReviewService` — shared prompt/data-block reuse, `web_search` tool presence — mirrors `GeminiCriticalReviewServiceTest` coverage.

### Integration Tests:

- None new — controller behavior is already covered by the existing structural-only pattern (`Response::json()`'s `exit()` constraint, established in `critical-review-models`).

### Manual Testing Steps:

1. Run the Phase 1 offline smoke check against both real API keys before wiring anything into the UI.
2. After Phase 2, trigger all 4 providers for one ticker (sequentially, then at least two concurrently) and confirm independent, correct results.
3. Spot-check `ai_critical_reviews` rows for both new providers — correct `provider` value, non-null probability fields, realistic token counts.

## Performance Considerations

Same as the existing async pattern — each worker runs detached, never blocking a synchronous request. `GPT_TIMEOUT=180`/`GPT_TOTAL_TIMEOUT=200` (already configured) match Gemini's budget, appropriate for a web-search-enabled call.

## Migration Notes

None — `provider` is already a free-form `VARCHAR(16)` column with
`UNIQUE(ticker, provider)` (migration 041, `critical-review-models`). No schema
change needed for two more provider values.

## References

- Prior art (mirror-image client pattern): `src/Ai/GeminiClient.php`, `src/Ai/GeminiClientFactory.php`
- Prior art (isolated per-provider service + worker): `src/Ai/GeminiCriticalReviewService.php`, `bin/generate_critical_review_gemini.php`
- Shared prompt/parsing (reused unchanged): `src/Ai/CriticalReviewPrompt.php`, `src/Ai/CriticalReviewProbabilityParser.php`
- Provider allow-list: `src/Ai/CriticalReviewProvider.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: GPTClient + GPTCriticalReviewService (backend infrastructure)

#### Automated

- [x] 1.1 `vendor/bin/phpunit tests/Ai/GPTClientTest.php` passes
- [x] 1.2 `vendor/bin/phpunit tests/Ai/GPTCriticalReviewServiceTest.php` passes
- [x] 1.3 `composer stan` reports no new errors
- [x] 1.4 `php -l` passes on all new files

#### Manual

- [ ] 1.5 Offline/live smoke check against the real Terra API key confirms response parsing
- [ ] 1.6 Same smoke check for the Luna API key

### Phase 2: Provider wiring — workers, controller, UI

#### Automated

- [ ] 2.1 `php -l` passes on all new/changed files
- [ ] 2.2 Full `vendor/bin/phpunit` suite passes with zero failures
- [ ] 2.3 `composer stan` reports no new errors

#### Manual

- [ ] 2.4 All 4 tabs render correctly
- [ ] 2.5 GPT Terra produces a correct, web-search-grounded review
- [ ] 2.6 GPT Luna produces a correct, web-search-grounded review
- [ ] 2.7 Concurrent triggering across providers works independently
- [ ] 2.8 `ai_critical_reviews` rows show realistic token usage for both new providers
- [ ] 2.9 No regression to Claude/Gemini
