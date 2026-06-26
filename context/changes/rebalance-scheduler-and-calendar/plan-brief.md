# F-01: Rebalance Scheduler and Market Calendar Gate — Plan Brief

> Full plan: `context/changes/rebalance-scheduler-and-calendar/plan.md`
> PRD: `context/foundation/prd-virtual-portfolio.md`
> Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (F-01)

## What & Why

Deliver the deterministic daily gate that decides whether the rebalance engine should fire. The virtual portfolio module needs a reliable, DST-aware trigger that fires 30 minutes before NYSE close, skips non-trading days, and prevents duplicate runs. This is the first executed piece of the Virtual Portfolio feature and unlocks F-03 (LLM decision contract) and S-02 (first autonomous rebalance).

## Starting Point

No virtual portfolio code exists. The project has three production cron scripts in `bin/` (rescore, check_price_alerts, refresh_peer_medians) that establish a clear scaffold pattern. No timezone configuration exists anywhere in the codebase — all DateTime usage is implicit system timezone.

## Desired End State

`bin/portfolio-rebalance.php` is registered as two CyberFolks cron entries (20:30 + 21:30 Warsaw Mon–Fri). Each day exactly one entry fires in the correct window, the script verifies the NYSE calendar, prevents duplicate execution via a DB row, and either exits cleanly (market_closed, outside_window, already_started) or writes a `started` → `completed` cycle record and calls the engine stub placeholder for F-03.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|----------|--------|------------------|--------|
| Market calendar source | Local PHP config table (holidays 2025–2027) | No external runtime dependency; 9 holidays/year × 3 years is maintainable manually | Plan |
| DST handling | PHP `DateTimeZone('America/New_York')` for all market checks | PHP handles US + EU DST transitions automatically; no manual offset math needed | Plan |
| Out-of-window behavior | Log `outside_rebalance_window` + exit(0) | Silent skip consistent with existing cron scripts; no CF alarm noise from DST slip | Plan |
| Idempotency mechanism | `INSERT IGNORE` on UNIQUE `cycle_date` in DB | DB-persistent, survives server restart unlike lockfile; chosen by owner | Plan |
| F-01/F-02 boundary | F-01 creates minimal `rebalance_cycle` migration, F-02 extends via ALTER | Keeps F-01 self-contained with working idempotency before F-02 is implemented | Plan |
| Cron entries | Two entries: 20:30 + 21:30 Warsaw | Covers both DST offset values (5h and 6h gap) without relying on manual calendar adjustment | Plan |
| Config scope | 4 keys: holidays, minutes_before_close, initial_capital_usd, NYSE hours | All scheduler business parameters must be changeable without code edits (FR requirement) | Plan |

## Scope

**In scope:**
- `config/portfolio.php` with NYSE holidays 2025–2027 and scheduler parameters
- `database/migrations/024_create_rebalance_cycle.sql` (minimal cycle table)
- `src/Portfolio/MarketCalendar.php` (isMarketDay, isInRebalanceWindow, getStatus)
- `src/Portfolio/CycleRepository.php` (findTodayCycle, insertCycle, updateStatus)
- `bin/portfolio-rebalance.php` with all five exit paths and F-03 engine stub hook
- Unit tests for `MarketCalendar`

**Out of scope:**
- LLM decision logic (F-03)
- Portfolio positions / cash ledger (F-02)
- HTTP routes (S-01)
- Price fetching or screener ingestion (S-02)
- Half-day / early-close NYSE sessions
- Per-user portfolios

## Architecture / Approach

```
CyberFolks cron (20:30 Warsaw)  ─┐
CyberFolks cron (21:30 Warsaw)  ─┴─▶ bin/portfolio-rebalance.php
                                            │
                         ┌──────────────────┤
                         ▼                  ▼
                  MarketCalendar     CycleRepository
                  (gate check)       (idempotency)
                         │
              ┌──────────┼──────────┐
              ▼          ▼          ▼
        market_closed  outside   already_started
         (exit 0)     _window      (exit 0)
                      (exit 0)
                                   │
                                   ▼
                         [engine stub → F-03]
                         updateStatus(completed)
```

All market-time comparisons happen in `America/New_York` timezone. Warsaw time is used only for the cron schedule.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|-------|-----------------|----------|
| 1. Config + migration | `config/portfolio.php` with holiday table; `024_create_rebalance_cycle.sql` | Missing or wrong NYSE holiday dates cause silent market_closed misfire |
| 2. MarketCalendar + CycleRepository | Testable service classes for gate logic and DB idempotency | DST edge case in March/November transition weeks |
| 3. bin/portfolio-rebalance.php | Working CLI entry point with all exit paths and F-03 stub hook | Wrong `.env` loader or autoload path breaks silently in CF environment |

**Prerequisites:** MySQL credentials in `.env`; migration 024 applied before first cron run  
**Estimated effort:** ~1-2 sessions across 3 phases

## Open Risks & Assumptions

- CyberFolks server timezone is unknown — mitigated by always using explicit `DateTimeZone` objects, never relying on system default
- NYSE occasionally announces special early-close days (e.g., day before Thanksgiving) — treated as full trading days in MVP; acceptable for educational/calibration use case
- F-02 `ALTER TABLE` on `rebalance_cycle` must not break the UNIQUE constraint on `cycle_date`

## Success Criteria (Summary)

- Script correctly fires exactly once per trading day within the 30-min window and skips on all other conditions
- `rebalance_cycle` table accumulates one row per trading day with correct status transitions
- All MarketCalendar unit tests pass including both DST offset scenarios (5h and 6h Warsaw↔ET gap)
