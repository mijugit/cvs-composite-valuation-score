# Full Rebalance History and Reason Timeline (S-03) — Plan Brief

> Full plan: `context/changes/full-history-and-reason-log/plan.md`

## What & Why

Give users a `/portfolio/history` page to browse the **complete** rebalance
timeline — every autonomous decision the model has made, with its reasoning —
instead of only the latest cycle visible on `/portfolio`. This is the
auditability payoff of the autonomous portfolio (roadmap S-03, PRD US-02/FR-011/FR-013).

## Starting Point

`/portfolio` shows only the latest cycle. `PortfolioRepository` already has
`getCycleHistory()` and `getTransactionsByCycle()` but they are unused, unsplit by
status, and N+1-prone. The DB schema (rebalance_cycle + portfolio_transactions)
already holds everything needed — no migration.

## Desired End State

An authenticated user opens `/portfolio/history` (linked from `/portfolio`) and
browses completed rebalances newest-first as collapsed cards (date, status, counts,
portfolio value + Δ vs previous), each expanding to the per-position BUY/SELL/HOLD
decisions and their reasons. Failed cycles sit in a separate collapsible
"zdarzenia operacyjne" section. ~30 shown at a time with "Pokaż starsze".

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Location | Separate `/portfolio/history` page | Keep `/portfolio` light; history scales independently | Plan (user) |
| Granularity | Card per cycle, expandable transactions | Scannable timeline; details on demand | Plan (user) |
| Failed cycles | Separate "zdarzenia operacyjne" section | Clean decision history; ops noise apart | Plan (user) |
| Pagination | Cumulative window via `?show=N` + "Pokaż starsze" | Light page at any portfolio age, zero JS/API | Plan (user) |
| Default expand | All collapsed | Compact; latest already on `/portfolio` | Plan (user) |
| Card header | Portfolio value + Δ vs previous | Free trend signal; data already present | Plan (user) |
| Collapse mechanism | Native `<details>`/`<summary>` | Zero-JS, matches existing sprinkles | Plan |
| N+1 avoidance | Batched `getTransactionsForCycles(ids)` | One query for all visible cycles | Plan |

## Scope

**In scope:** history route + controller action; status-aware paginated repo
methods + batched transactions + tests; history template with collapsible cards
and operational-events section; link from `/portfolio`; minimal CSS.

**Out of scope:** SPY/performance analytics (S-05); screener-vs-held linkage (S-04);
filtering/search; any schema change; any engine/decision-pipeline change.

## Architecture / Approach

Data → store → controller → view. `?show=N` renders the newest N completed cycles;
controller fetches N+1 to detect overflow and compute the last card's Δ
(Δ[i]=value[i]−value[i+1], newest-first). Transactions for the visible cycles are
fetched in one batched `IN (…)` query and grouped by `cycle_id`. Completed cycles
form the main timeline; everything non-`completed` is an operational event.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Data layer | Paginated completed cycles + count, operational bucket, batched transactions, tests | Status bucketing / off-by-one in pagination window |
| 2. Controller + route | `/portfolio/history` action assembling the view model (Δ, hasMore) | Δ across page boundary; query-param clamp |
| 3. View + link + CSS | Collapsible cards, operational section, "Pokaż starsze", link from `/portfolio` | statusChip reuse; empty/edge states |

**Prerequisites:** S-02 (done) — cycles + transactions exist in DB.
**Estimated effort:** ~1 session across 3 phases.

## Open Risks & Assumptions

- Assumes `'completed'` is the sole success status; all others are operational
  events (verified against the engine's `updateStatus` calls).
- `statusChip` is duplicated as a small local closure in the history template
  (templates are plain PHP; project convention) rather than extracted to a shared partial.

## Success Criteria (Summary)

- User can browse the full rebalance history and read the reasoning behind any past decision.
- Failed cycles never pollute the main timeline; they're available under operational events.
- Page stays light regardless of portfolio age (cumulative window + batched queries).
