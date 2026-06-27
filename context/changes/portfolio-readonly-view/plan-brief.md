# S-01: Portfolio Read-Only View — Plan Brief

> Full plan: `context/changes/portfolio-readonly-view/plan.md`
> PRD: `context/foundation/prd-virtual-portfolio.md` (US-01, FR-012, FR-017)
> Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (S-01)

## What & Why

Add a `/portfolio` page so logged-in users can observe the global virtual portfolio in real time. Without this view the autonomous rebalancing runs silently — decisions are made and executed, but nobody can see the result. S-01 is the "show your work" surface for the entire F-01/F-02/F-03 backend.

## Starting Point

F-02 delivered `PortfolioRepository` (getCurrentState, getCurrentHoldings, getLatestCycle) and the fully seeded portfolio (10 000 USD). F-01/F-03 run the daily cron. There is no controller, no route, and no template for the portfolio yet.

## Desired End State

Any logged-in user can open `https://cvs.timeflow.fun/portfolio` and see: current cash, holdings table with live-ish screener prices, total portfolio value, and the status of the last rebalance cycle. Before the first cycle runs, a friendly message names the next trading day. A "Portfel" nav link appears between Screener and Track Record for all logged-in users.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
|----------|--------|-----------------|
| Holdings price source | JOIN cvs_snapshots (price_at_snapshot) | Closest to market price without a live Yahoo fetch; falls back to avg_entry_price when no snapshot |
| Model version filter on JOIN | Required (filter shadow rows by live model_version + origin=RESCORE) | Lesson from screener hotfix — without it duplicate snapshot rows corrupt prices |
| Empty cycle message | Friendly text with next trading day computed by MarketCalendar | User understands the system is running, not broken |
| LLM reason on main view | Status + executed_count + notes only (no JSON parse) | Full per-ticker reasons belong in S-03 history page |
| Nav access | All logged-in users (no PRO gate) | PRD FR-017: portfolio is global and educational |

## Scope

**In scope:**
- `PortfolioRepository::getCurrentHoldingsWithPrice()` (price-enriched query)
- `src/Portfolio/PortfolioController.php` + `GET /portfolio` route
- `templates/portfolio.php` (summary cards, holdings table, latest cycle block)
- "Portfel" nav link in `templates/layout.php`
- Unit tests for new repository method

**Out of scope:**
- Rebalance history list (S-03)
- Screener ↔ portfolio comparison (S-04)
- P&L stats / benchmark (S-05)
- Live Yahoo price fetch
- Per-user portfolio views

## Architecture / Approach

```
GET /portfolio
   → PortfolioController::index()
        ├── PortfolioRepository::getCurrentState()         cash, initial_capital
        ├── PortfolioRepository::getCurrentHoldingsWithPrice(liveModelVersion)
        │        LEFT JOIN cvs_snapshots ON ticker (latest, RESCORE only)
        │        COALESCE(price_at_snapshot, avg_entry_price) as live_price
        ├── PortfolioRepository::getLatestCycle()          status, counts, notes
        ├── compute totalValue, nextTradingDay (MarketCalendar)
        └── Response::view('portfolio', $data)
                → templates/portfolio.php
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
|-------|-----------------|----------|
| 1. Controller + Route + Nav + Repo ext. | `/portfolio` accessible, data loaded, nav visible | Missing model_version filter on JOIN → wrong prices from shadow rows |
| 2. Template portfolio.php | Full visual page: cards, table, cycle block, empty states | Empty state before first cycle must be informative, not confusing |

**Prerequisites:** F-02 migrations applied on production (✅ done), portfolio_state seeded (✅ done)
**Estimated effort:** ~1 session across 2 phases

## Open Risks & Assumptions

- `price_at_snapshot` in cvs_snapshots may lag behind real market price by up to 24h — acceptable for MVP educational use
- If cvs_snapshots has no row for a ticker held in portfolio (edge case if ticker was added after last rescore), the page uses avg_entry_price with "(aprox)" label — graceful degradation

## Success Criteria (Summary)

- `/portfolio` returns HTTP 200 for logged-in user with cash=10 000 USD and "brak pozycji" on initial state
- After first Monday cycle: holdings table shows positions, latest cycle block shows status=completed
- "Portfel" appears in nav between Screener and Track Record
