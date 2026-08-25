# Critical Review OpenAI (GPT-5.6 Terra + Luna) — Plan Brief

> Full plan: `context/changes/critical-review-openai/plan.md`

## What & Why

The user has decided the Claude+Gemini MVP is done and wants to grow the
provider roster — starting with OpenAI's GPT-5.6 Terra and Luna, to compare
them head-to-head for cost/quality. Explicit framing: this is a natural
extension of the existing mechanism (same prompt, same data, same async
pattern), not a new feature — reuse what's proven, add only what's new.

## Starting Point

Claude and Gemini already work as fully independent critical-review providers
(isolated service classes + own worker scripts, sharing `CriticalReviewPrompt`/
`CriticalReviewProbabilityParser`), deployed and verified in production. No
OpenAI integration exists yet, but production `.env` is already configured
with real API keys and parameters for both flavors (user-provided ahead of
this plan).

## Desired End State

Four tabs on the critical-review card: Claude, Gemini, GPT Terra, GPT Luna —
each independent, using the identical shared prompt and probability format.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Client/service class count | ONE `GPTClient` + ONE `GPTCriticalReviewService`, not two per flavor | Terra and Luna are the identical REST API shape (verified) — unlike Claude vs Gemini, splitting by flavor would just duplicate code with no isolation benefit | Plan (verified via live docs, not the pasted ChatGPT answer alone) |
| Web search tool name | `"web_search"` (current), not `"web_search_preview"` (legacy) | Corrects one inaccuracy in the pasted ChatGPT answer — verified against current OpenAI docs | Plan (verified) |
| Web search for Luna | Always on, same as Terra | The shared prompt mandates it structurally — disabling it would break the one-prompt-both-providers contract, not save meaningful cost | Plan |
| Max output tokens | `8000` from the user's own `.env`, not a hardcoded `8192` class constant | Respects config the user already deliberately set, rather than silently overriding it | Plan |
| Reasoning effort | `medium` for both flavors, configurable | Keeps the Terra-vs-Luna comparison isolated to model choice, not confounded by different reasoning settings | Plan |
| Provider naming | `gpt_terra` / `gpt_luna`, class `GPTClient` | Matches the user's own `.env` comment and naming convention | User-specified |

## Scope

**In scope:**
- `GPTClient` + `GPTClientFactory` + `config/gpt.php` (Phase 1)
- `GPTCriticalReviewService`, shared by both flavors (Phase 1)
- Two new worker scripts, provider registration, controller lookup, 2 new UI tabs (Phase 2)

**Out of scope:**
- Cost-tracking/comparison dashboard between providers
- Any change to Claude/Gemini's own service/worker logic
- Deriving the UI's provider arrays automatically from `CriticalReviewProvider::ALL`

## Architecture / Approach

One flavor-agnostic client (mirrors `ClaudeClient`/`GeminiClient`'s contract)
constructed twice via a flavor-aware factory; one shared service class calling
the same `CriticalReviewPrompt`/`AiDivergenceService` reuse point as every
other provider. Two independent worker scripts preserve per-provider job
isolation. Everywhere the app currently enumerates 2 providers (controller
lookup, UI tab arrays) gets extended to 4 — no new patterns invented.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Backend infrastructure | `GPTClient`/`GPTCriticalReviewService`, fully offline-tested, nothing wired in yet | Unverified response details (citations shape) — confirmed via live smoke test before Phase 2 |
| 2. Provider wiring | 4 live tabs, 2 new workers, controller/UI extended | Regressing Claude/Gemini while touching shared lookup structures |

**Prerequisites:** Production API keys already configured (done by the user).
**Estimated effort:** Single session, 2 phases.

## Open Risks & Assumptions

- OpenAI's exact error-object field names and citation-annotation shape were
  not independently confirmed against a live response (only the success-path
  text/usage shape was verified) — Phase 1's manual smoke test against the
  real API closes this gap before anything is wired into the UI.

## Success Criteria (Summary)

- GPT Terra and GPT Luna each produce a correct, web-search-grounded review
  with the shared probability format, using their own real API keys.
- All 4 providers work independently, including concurrently.
- Zero regression to Claude/Gemini.
