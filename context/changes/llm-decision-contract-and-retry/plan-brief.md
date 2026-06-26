# F-03: LLM Decision Contract and Retry Safety — Plan Brief

> Full plan: `context/changes/llm-decision-contract-and-retry/plan.md`
> PRD: `context/foundation/prd-virtual-portfolio.md`
> Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (F-03)
> F-01: `context/changes/rebalance-scheduler-and-calendar/plan.md`
> F-02: `context/changes/virtual-portfolio-ledger/plan.md`

## What & Why

Deliver the strict LLM decision schema and retry safety layer that turns screener signals into validated portfolio actions. Without this, the F-01 engine stub stays a stub and no autonomous cycle can complete. After F-03, the system runs its first real end-to-end rebalance — which is the North Star milestone (S-02) for the entire Virtual Portfolio module.

## Starting Point

F-01 and F-02 delivered the scheduler gate, the portfolio ledger, and a `// F-03: wire engine here` stub in `bin/portfolio-rebalance.php`. Existing `ClaudeClient` (`src/Ai/`) handles transport retries internally; `AiDivergenceService` is the canonical pattern for LLM calls in this codebase. `rebalance_cycle` has financial summary columns but no LLM audit columns yet.

## Desired End State

`bin/portfolio-rebalance.php` runs a full autonomous cycle: fetch screener signals → build prompt → call LLM (max 2 attempts) → validate JSON → execute trades via `PortfolioService::executeCycle()`. On LLM failure the cycle is marked `llm_failed` with a full audit record and no portfolio changes. `rebalance_cycle` accumulates a complete audit trail: raw LLM response, decision JSON, retry count, and failure kind.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|----------|--------|------------------|--------|
| LLM output format | Clean JSON array only (no prose) | Deterministic parsing; avoids regex-based extraction from mixed text | Plan |
| Retry semantics | ClaudeClient max_retries=0 + DecisionService makes 2 service-level attempts | Exactly 2 total attempts as PRD specifies; service owns the policy, transport is fast-fail | Plan |
| Retry delay | 2s flat between attempts | Short window (30 min before close); exponential would waste execution time | Plan |
| Ticker scope | All tickers from ScreenerRepository::findAllLatest() | Full signal picture; user preference | Plan |
| Max tokens | 2048 (same as AI analysis) | Zero risk of JSON truncation for 100+ ticker output | Plan |
| Failed cycle record | retry_count + llm_raw_response + llm_failure_kind + llm_decision_json | Full audit trail for calibration; distinguishes transport failure from parse failure | Plan |
| NO_ACTION format | Single-element array [{action:NO_ACTION, ticker:null}] | Empty array is ambiguous; explicit NO_ACTION enables clean handling in PortfolioService | Plan |
| LLM audit write | Before portfolio DB transaction | Audit persisted even if executeCycle() rolls back | Plan |
| BUY prioritization | LLM self-ranks in prompt (cash constraint stated in user message) | Autonomous; decided in roadmap planning session | Roadmap |

## Scope

**In scope:**
- `database/migrations/027_extend_rebalance_cycle_llm.sql` — 4 LLM columns
- `src/Portfolio/DecisionParser.php` — JSON validation (stateless, unit-testable)
- `src/Portfolio/DecisionService.php` — prompt builder + 2-attempt retry orchestrator
- `config/portfolio.php` extension — `llm` sub-array (max_retries=0, max_tokens=2048, etc.)
- `src/Portfolio/CycleRepository.php` extension — `updateLlmRecord()`
- `bin/portfolio-rebalance.php` — stub replaced with real call chain
- Unit tests for DecisionParser and DecisionService

**Out of scope:**
- Real-time price fetch for LLM input (price_at_snapshot from screener is sufficient)
- Top-N ticker filtering (all tickers sent to LLM)
- Structured output / tool use API feature
- UI views (S-01, S-03)

## Architecture / Approach

```
bin/portfolio-rebalance.php
   │
   ├── PortfolioRepository::getCurrentState() + getCurrentHoldings()
   ├── ScreenerRepository::findAllLatest()  [100+ rows]
   │
   └── DecisionService::generate(cycleId, state, holdings, screenerRows)
           │
           ├── buildSystemPrompt() → CacheableSystem(TTL_5M)
           ├── buildDataBlock()   → user message string
           │
           ├── Attempt 1: ClaudeClient::sendMessage() [max_retries=0]
           │      └── AiResult.ok? → DecisionParser::parse() → decisions ✓
           │      └── failed? → sleep(2s) → Attempt 2
           │
           ├── Attempt 2: same as above
           │      └── failed? → return ok=false
           │
           └── CycleRepository::updateLlmRecord() [always, before portfolio tx]

   ├── ok=true  → PortfolioService::executeCycle(cycleId, decisions)
   └── ok=false → CycleRepository::updateStatus('llm_failed') → exit(1)
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
|-------|-----------------|----------|
| 1. Migration 027 | 4 LLM audit columns on rebalance_cycle | Must be TEXT not VARCHAR — full LLM response can be long |
| 2. DecisionParser | JSON schema enforcement, isolated and unit-testable | NO_ACTION edge case: must explicitly accept [{action:NO_ACTION,ticker:null}] |
| 3. DecisionService + CycleRepository ext. | Full LLM pipeline with 2-attempt retry | LLM audit must be written BEFORE portfolio DB transaction, not inside it |
| 4. Wiring — bin/ script | First real autonomous cycle end-to-end | Database::reconnect() needed after ~20s LLM call before screener/portfolio queries |

**Prerequisites:** Migrations 024 (F-01), 025+026 (F-02) applied; `ANTHROPIC_API_KEY` in `.env`
**Estimated effort:** ~1-2 sessions across 4 phases

## Open Risks & Assumptions

- 100+ screener tickers in prompt ≈ ~10k input tokens — within Claude's context window but worth monitoring token cost per cycle
- `ScreenerRepository::findAllLatest()` filters by `model_version` (existing hotfix) — this means only tickers scored with current model version appear; acceptable and correct
- System prompt uses `CacheableSystem(TTL_5M)` — cache hit rate depends on cron regularity; two daily runs at 20:30/21:30 Warsaw = exactly 1h apart, only one fires, so cache is effectively cold each day (TTL_1H might be better, but TTL_5M matches existing codebase default)

## Success Criteria (Summary)

- `rebalance_cycle` accumulates one completed row per trading day with `llm_decision_json` populated and `portfolio_state.cash` changed from 10 000.00
- LLM failure (both attempts) marks `status=llm_failed`, `portfolio_state.cash` unchanged
- PHPStan level 6 passes with zero errors across all `src/Portfolio/` classes
