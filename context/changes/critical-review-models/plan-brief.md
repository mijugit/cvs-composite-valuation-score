# Critical Review Models — Plan Brief

> Full plan: `context/changes/critical-review-models/plan.md`
> PRD: `context/foundation/prd-critical-review-models.md`
> Shape notes: `context/foundation/shape-notes-critical-review-models.md`

## What & Why

The analysis card's "Recenzja krytyczna" (critical review) today produces one
AI opinion (Claude). Gemini is already integrated elsewhere in the system and
proven cheap in practice (`fundamentals-validation`), so this change lets a PRO
user get a second, independent perspective on the same ticker — plus a new
quantitative signal both providers must supply: bull/bear scenario probability
(%) with a short justification, not a bare number.

## Starting Point

`AiCriticalReviewController`'s trigger/poll actions, `ai_critical_reviews`
(one row per ticker), `AiCriticalReviewService` (Claude-only), and
`bin/generate_critical_review.php` (the detached-worker pattern) all work in
production today, single-provider. `GeminiClient`/`GeminiClientFactory` already
exist and mirror `ClaudeClient`'s call contract exactly.

## Desired End State

A tab strip ("Claude" | "Gemini") inside the existing critical-review card.
Each tab triggers, polls, and displays its own narrative + sources + a new
probability box, fully independent of the other tab — triggering one never
blocks the other, switching tabs never loses in-flight polling, and both share
the same PRO usage limit unchanged.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Service/worker architecture | Separate classes per provider (`GeminiCriticalReviewService` + own worker), not a branch inside the Claude classes | Zero regression risk on the proven Claude path | Plan (user-selected, non-default) |
| Prompt content identity | Shared static `CriticalReviewPrompt` builder used by both isolated services | Reconciles isolation with FR-004/FR-006's "identical prompt" requirement — one place, not two, that could drift | Plan |
| Probability format | Trailing fenced JSON block after the narrative, parsed like `FundamentalsValidationService` | Reliable parsing regardless of narrative style; proven technique already in this codebase | Plan (user-selected, recommended) |
| Parse-failure handling | Graceful degradation — narrative still shown, probability box just omitted | A formatting hiccup must never cost the user the narrative they paid a PRO-usage unit for | Plan |
| Data storage for probabilities | 3 new columns on `ai_critical_reviews` (`bull_probability`, `bear_probability`, `probability_rationale`), not a JSON blob | Simple scalar+text fields the UI reads directly, unlike `sources` which is naturally a list | Plan |
| UI layout | One card, tab strip, two independently-stateful panes toggled via the existing `hidden`-attribute convention | Compact, consistent with the rest of the page; reuses an existing visibility mechanism instead of inventing one | Plan (user-selected, recommended) |
| Data model (provider dimension) | Additive migration: `provider` column + widened `UNIQUE(ticker, provider)` | One read location, matches project's established additive-migration convention | PRD |
| PRO usage limit | Unchanged, shared across providers | `ai_usage_log` is already `COUNT(*)`-based with no provider column — zero code changes needed | PRD / Plan (verified in research) |

## Scope

**In scope:**
- Migration 041 (`provider` + 3 probability columns + widened unique key)
- New `GeminiCriticalReviewService` + `bin/generate_critical_review_gemini.php`
- Shared `CriticalReviewPrompt` + `CriticalReviewProbabilityParser` helpers
- Controller/status endpoint `provider` param (backward-compatible, defaults to `claude`)
- Tab-strip UI, dual panes, provider-parametrized JS, probability box, CSS

**Out of scope:**
- More than 2 providers
- Any effect of probabilities on CVS score/alerts/screener
- Version history per provider (re-trigger overwrites, same as today)
- Separate/expanded PRO limit for a "second opinion"
- Any change to `ProGate`/`AiUsageRepository` or the stage-1 AI flow

## Architecture / Approach

Two isolated service+worker pairs (Claude unchanged in structure, Gemini new)
both depend on two shared, static, provider-agnostic helpers — a prompt
builder and a probability parser — so prompt content and parsing behavior
can't drift between providers despite the classes themselves being separate.
The repository gains a `provider` dimension end-to-end (every method scoped),
fixing a latent bug where triggering a second provider would have been
incorrectly blocked by the old ticker-only `isPending` check. The UI adds the
codebase's first tab pattern, built from existing primitives (`hidden`
attribute toggling, the `.ai-analysis-card` chrome) rather than a new library.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Migration + repository | `provider`-scoped `ai_critical_reviews`, all repo methods updated | Getting the widened unique key + backfill right on prod |
| 2. Prompt/parser + Gemini service/worker | Isolated Gemini path, shared prompt/parsing helpers | Prompt content drifting between providers if the shared builder is bypassed |
| 3. Controller + page-load | `provider` param end-to-end, both providers' state available on load | Missing the "resume polling regardless of active tab" data-layer requirement |
| 4. UI — tabs + JS + CSS | Tab strip, dual panes, probability box | First tab pattern in the codebase — no existing convention to lean on |
| 5. Test suite completion | Full coverage, green `phpunit`/`phpstan` | None significant — mechanical |

**Prerequisites:** None — all supporting infrastructure (`GeminiClient`, PRO gate, async-job skeleton) already exists and is proven in production.
**Estimated effort:** ~1 week after-hours (per shape-notes estimate), 5 phases.

## Open Risks & Assumptions

- Neither Claude nor Gemini reliably emits well-formed trailing JSON on every
  call — mitigated by graceful degradation (narrative always shown; probability
  box just omitted on parse failure), not a blocker.
- Bull/bear percentages are independent scenario-confidence estimates, not a
  forced 100%-sum split — the prompt does not require them to add to 100.

## Success Criteria (Summary)

- On at least one ticker, a PRO user can trigger and see Claude and Gemini
  reviews independently, each with narrative + probabilities + sources, without
  losing the other.
- Existing pre-migration Claude reviews remain visible unchanged.
- The shared PRO limit and CVS determinism guardrail are unaffected.
