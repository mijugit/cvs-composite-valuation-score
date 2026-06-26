# F-02: Virtual Portfolio Ledger Contract — Plan Brief

> Full plan: `context/changes/virtual-portfolio-ledger/plan.md`
> PRD: `context/foundation/prd-virtual-portfolio.md`
> Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (F-02)
> F-01 plan: `context/changes/rebalance-scheduler-and-calendar/plan.md`

## What & Why

Create the persistence contract that every downstream virtual portfolio feature depends on: the database schema for portfolio state + audit history, and the PHP service that mutates it atomically. Without this stable ledger, F-03 (LLM decisions), S-01 (read-only view), S-03 (history), and S-05 (stats) have no ground to build on.

## Starting Point

F-01 delivered `rebalance_cycle` (minimal: cycle_date, status, started_at, finished_at) and `CycleRepository`. No other portfolio code exists. This plan extends that table and creates all remaining portfolio persistence.

## Desired End State

Three portfolio tables exist (`portfolio_state`, `portfolio_holdings`, `portfolio_transactions`), seeded with 10 000 USD initial capital. `PortfolioService::executeCycle()` atomically processes a list of BUY/SELL/HOLD/SKIP decisions in a single DB transaction — updating cash, holdings, and logging every action immutably. F-03 can wire its LLM decisions into `executeCycle()` without touching the ledger code.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|----------|--------|------------------|--------|
| State model | Mutable singleton + immutable log | O(1) reads for UI; log provides full audit trail | Plan |
| Holdings model | Dedicated `portfolio_holdings` table (UPSERT on ticker) | Avoids aggregation query on every page load | Plan |
| Portfolio init | SQL seed INSERT in migration 026 + `ensureInitialized()` guard | State always exists after deploy; no manual run step | Plan |
| Atomicity scope | One DB transaction per entire rebalance cycle | Partial writes leave portfolio in inconsistent state | Plan |
| insufficient_cash | Record SKIP transaction, continue cycle | PRD FR-008: cash floor blocks BUY, not whole cycle | PRD |
| Money type | DECIMAL(12,2) for cash, DECIMAL(12,4) for prices | Float arithmetic accumulates precision errors over many cycles | Plan |
| rebalance_cycle columns added by F-02 | cash_before, cash_after, portfolio_value_usd, executed_count, skipped_count, notes | Execution summary needed for S-03 history and S-05 stats | Plan |
| LLM columns on rebalance_cycle | Deferred to F-03 (llm_raw_response, retry_count) | F-02 owns ledger contract, F-03 owns LLM contract | Plan |
| reason TEXT on portfolio_transactions | Column created by F-02, populated by F-03 | F-02 defines schema so F-03 can INSERT without ALTER | Plan |

## Scope

**In scope:**
- `database/migrations/025_extend_rebalance_cycle.sql` — ALTER TABLE, 6 columns
- `database/migrations/026_create_portfolio_tables.sql` — 3 tables + seed
- `src/Portfolio/PortfolioRepository.php` — read model (5 methods)
- `src/Portfolio/PortfolioService.php` — write model with atomic `executeCycle()`
- `src/Portfolio/CycleRepository.php` extension — `updateCycleSummary()`
- Unit tests for both classes

**Out of scope:**
- LLM-specific columns on rebalance_cycle (F-03)
- HTTP routes and views (S-01)
- Price fetching (S-02)
- History cap / archiving (PRD Non-Goal #5)
- Per-user portfolios (PRD Non-Goal #4)

## Architecture / Approach

```
F-03 (LLM engine)
      │ calls
      ▼
PortfolioService::executeCycle(cycleId, $decisions)
      │
      │ beginTransaction()
      ├── UPDATE portfolio_state (cash ± delta)
      ├── UPSERT portfolio_holdings (per ticker)
      ├── INSERT portfolio_transactions (immutable, one row per decision)
      ├── UPDATE rebalance_cycle (summary: cash_before/after, counts)
      └── commit() or rollBack() on exception

PortfolioRepository (read-only, no transactions)
      ├── getCurrentState()    → S-01 view
      ├── getCurrentHoldings() → S-01 view
      ├── getCycleHistory()    → S-03 history
      └── getTransactionsByCycle() → S-03 detail
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
|-------|-----------------|----------|
| 1. DB Migrations | Schema for all 4 tables + 10 000 USD seed | Wrong types (FLOAT instead of DECIMAL) cause rounding drift in long-running portfolio |
| 2. PortfolioRepository | Read model for all UI views | Missing `no LIMIT` on getCycleHistory breaks PRD FR-011 (full history) |
| 3. PortfolioService + CycleRepository ext. | Atomic write model; all mutations go here | ROLLBACK not wired = partial writes silently corrupt state |

**Prerequisites:** F-01 migration 024 applied (`rebalance_cycle` table must exist before migration 025)
**Estimated effort:** ~1-2 sessions across 3 phases

## Open Risks & Assumptions

- `avg_entry_price` is computed in PHP with weighted average math — if the implementation uses SQL `AVG()` instead, it would be wrong for accumulated positions
- Migration 026 seed INSERT must be idempotent-safe (INSERT IGNORE or guard comment) — re-running migration on an already-seeded DB must not create a second portfolio_state row
- F-03 will ALTER TABLE rebalance_cycle to add LLM columns — migration 027+ must not conflict with column names defined here

## Success Criteria (Summary)

- `SELECT * FROM portfolio_state` returns exactly one row with `cash=10000.00` after migration
- `PortfolioService::executeCycle()` with a mixed decision array leaves all four tables consistent and rolls back completely on any exception
- PHPStan level 6 passes with zero errors in `src/Portfolio/`
