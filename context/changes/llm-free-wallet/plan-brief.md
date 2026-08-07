# LLM_Free_Wallet — Plan Brief

> Full plan: `context/changes/llm-free-wallet/plan.md`
> PRD: `context/foundation/prd.md`
> Shape notes: `context/foundation/shape-notes.md`

## What & Why

The existing LLM-driven portfolio ("LLM Bazowy") is functionally a rules engine —
its server-side enforcer overrides the model's decisions regardless of stated
reasoning, so it never gets to disagree with the algorithm on anything that
matters. LLM_Free_Wallet is a second, parallel portfolio with genuine freedom (no
overrides, no fixed caps, explicit right to challenge CVS signals) and a
persistent, self-authored "legenda" it writes each cycle and reads back on the
next — a controlled experiment on whether real LLM judgment + memory beats a
deterministic algorithm.

## Starting Point

`src/Portfolio/*` (tables `portfolio_state`/`portfolio_holdings`/
`portfolio_transactions`/`rebalance_cycle`) runs a global singleton portfolio via
`DecisionService` (Claude call, fixed-threshold prompt) + `DecisionEnforcer`
(server-side override, always wins). Cron `bin/portfolio-rebalance.php` runs it
once daily. It stays completely untouched by this change — renamed "LLM Bazowy" in
navigation only.

## Desired End State

A "Portfele" nav menu with two entries: "LLM Bazowy" (unchanged) and "LLM Free"
(new). The new page shows positions, return, and a readable, chronological history
of the model's investment thesis. A daily cron (~10 min before NYSE close) feeds
the model CVS signals + any fresh existing AI analyses + its last 10 legend
entries, and executes whatever it decides — no server-side second-guessing.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Architecture | New, fully isolated module (`CVS\LlmFree\`, `llm_free_*` tables) | Same precedent as Lab: multiple portfolio variants get their own module, never extend the singleton | Shape |
| Risk caps | None — no `DecisionEnforcer` equivalent | The whole point of the experiment (PRD FR-004) | Shape |
| Legend write path | Direct repository call from the cron process | It's already a CLI process talking to its own DB — an HTTP round-trip would be pure indirection | Plan (questioning) |
| Legend context window | Last 10 entries | User's explicit choice over the recommended 5 | Plan (questioning) |
| Extra context source | App pre-checks freshness (`isFresh()`) on existing analyses first; bounded fresh web-search only for gaps | Predictable, boundable cost vs. a model-controlled search tool | Plan (questioning) |
| Prompt call structure | Single call returns `{decisions, legend}` | Cheaper, matches the sibling module's single-call architecture | Plan (questioning) |
| Background-worker mechanism | None — cron just runs longer | The detached-worker pattern exists only because Recenzja krytyczna is fired from a web request; this cron has no such timeout budget | Plan (research, resolves PRD Open Question #2) |
| Legend storage | `legend TEXT` column on `llm_free_cycle`, not a separate table | Mirrors the sibling module's own precedent (`llm_decision_json` on the cycle row) — 1:1 relationship, a join would be overhead | Plan (research, resolves PRD Open Question #3) |
| UI scope | Minimal — positions, return, legend list | Matches FR-008/US-02 exactly, no scope creep | Plan (questioning) |
| Cost guardrail | Upfront bounding (`max_tokens` + capped search sub-calls) + token logging on the cycle row | Mathematically bounds worst case rather than a runtime kill-switch | Plan (questioning) |

## Scope

**In scope:**
- New isolated data model (state/holdings/transactions/cycle + legend column)
- Free-form decision engine with context gathering (reused analyses + bounded search)
- New cron entrypoint, own schedule slot
- New read-only page + "Portfele" nav dropdown

**Out of scope:**
- Any change to the existing Portfolio module's code, data, or `/portfolio` route
- Admin controls (pause/resume/override) for the new wallet
- A generic multi-portfolio-variant framework
- Increasing rebalance frequency
- Backfilling the baseline wallet's history for a "fairer" comparison

## Architecture / Approach

Mirror the existing Portfolio module's file/class shape 1:1 under a new namespace
and table prefix, swap `DecisionEnforcer` for nothing, and add two genuinely new
pieces: a context gatherer (reuses existing AI-analysis freshness checks, falls
back to bounded fresh search) and a legend read/write loop threaded through the
prompt and the cycle table. UI reuses the existing live-repricing controller logic
and the existing admin-dropdown nav component verbatim.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Data Foundation | New tables + read-model repositories | Schema mismatch with what Phase 2/3 actually need |
| 2. Write Engine | Transactional execution, no enforcement, mark-to-market from day one | Accidentally porting a risk-cap guard that shouldn't be there (or dropping a physical-constraint guard that should) |
| 3. Decision Engine | Free-form prompt, context gathering, response parser | Cost overrun if the search cap isn't actually enforced |
| 4. Scheduler | Cron entrypoint, own time slot | Timing collision with the existing wallet's cron during DST transition weeks |
| 5. UI | New page + nav dropdown | None significant — mostly a mirror of existing, proven components |

**Prerequisites:** None beyond what's already live (Claude API client, screener
data, AI analysis tables all exist today).
**Estimated effort:** Not tracked by week count — user works at their own pace
without a delivery-speed metric (see PRD Timeline acknowledgment).

## Open Risks & Assumptions

- Starting capital assumed identical to the baseline wallet ($10,000) — not
  explicitly re-confirmed in questioning, inferred from the PRD's comparison
  framing throughout.
- The "no detached worker needed" conclusion (Phase 4) is based on code precedent
  reasoning, not yet an empirical test against the hosting environment's actual
  CLI execution limits — Phase 4's manual verification step is the first real
  check.
- A bad early decision (no caps) could put the wallet deep in the red quickly —
  explicitly accepted as part of the experiment, not a plan defect.

## Success Criteria (Summary)

- The daily cron cycle runs autonomously, produces a new legend entry every time,
  and never touches the baseline wallet's data
- `/llm-free` is reachable from a new "Portfele" nav menu and shows a legend
  history that reads like an investor's reasoning, not a log
- Idempotent under cron retry — no duplicate trades or legend entries
