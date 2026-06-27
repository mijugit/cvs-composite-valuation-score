# S-01: Portfolio Read-Only View — Implementation Plan

## Overview

Add a `/portfolio` page that lets every logged-in user observe the global virtual portfolio: current cash, holdings with live-ish market prices, total portfolio value, and the latest rebalance cycle status. No write actions — purely informational. This is the first user-visible surface after F-01/F-02/F-03 and must be ready before the first autonomous cycle runs on Monday 29.06.

## Current State Analysis

In place from F-01/F-02/F-03:
- `PortfolioRepository` with `getCurrentState()`, `getCurrentHoldings()`, `getLatestCycle()`
- `portfolio_state` seeded with 10 000 USD; tables on production
- `MarketCalendar` class with `isMarketDay()` for next-trading-day calculation
- `templates/layout.php:36–55` — nav links (target for new "Portfel" entry)
- `src/Core/routes.php` — route registration file
- `templates/screener.php` + `ScreenerController` — canonical pattern for read-only page

Not in place yet:
- No `PortfolioController`
- No `GET /portfolio` route
- No `portfolio.php` template
- No nav link

## Desired End State

- `https://cvs.timeflow.fun/portfolio` loads for any logged-in user
- Page shows: cash balance, holdings table (ticker, quantity, avg_entry_price, live_price, value), total portfolio value (cash + holdings)
- Latest rebalance cycle block: date, status chip, executed/skipped counts, notes
- Empty state before first cycle: friendly message with next trading day date
- Empty holdings state: "Portfel w 100% gotówkowy"
- "Portfel" nav link appears between Screener and Track Record in nav for all logged-in users
- PHPStan level 6 passes

### Key Discoveries

- `PortfolioRepository` is in `CVS\Portfolio` namespace — `PortfolioController` goes there too (PSR-4 → `src/Portfolio/PortfolioController.php`)
- Live price JOIN: `cvs_snapshots` has `price_at_snapshot` + `scored_at` per ticker; latest row is found via self-join on `MAX(scored_at)` filtered by live `model_version` (lesson: must filter by model_version to avoid shadow rows — `context/foundation/lessons.md`)
- Total portfolio value = `portfolio_state.cash` + `SUM(quantity × live_price)` where live_price falls back to `avg_entry_price` when no snapshot exists for that ticker
- `MarketCalendar::isMarketDay()` takes a `DateTimeImmutable` — loop forward from tomorrow to find next trading day for the empty-cycle message
- `Response::view('portfolio', $data)` auto-extracts array keys as variables in the template

## What We're NOT Doing

- Live price fetching from Yahoo Finance at request time (use screener snapshot prices — acceptable lag)
- Per-user portfolio variants (FR-017: global portfolio only)
- Rebalance history listing (that is S-03)
- Screener comparison panel (that is S-04)
- P&L / stats metrics (that is S-05)
- Manual BUY/SELL actions (PRD Non-Goal #2)

## Implementation Approach

Two-phase build: data layer extension + controller/routing → then template.

**Phase 1** extends `PortfolioRepository` with a price-enriched holdings query, creates `PortfolioController`, registers the route, and adds the nav link. The controller computes next-trading-day using `MarketCalendar` and passes all data to the view.

**Phase 2** implements `templates/portfolio.php` following the `screener.php` visual pattern: `.card` wrappers, `.pillar-table` for the holdings table, status chips using CSS variables, friendly empty states.

## Critical Implementation Details

- **model_version filter on cvs_snapshots JOIN.** The JOIN to get live prices must include `AND s.model_version = ?` (value from `config/cvs-weights.php`) and `AND s.origin = 'RESCORE'` to avoid shadow rows returning duplicate prices — this is the same hotfix applied in `ScreenerRepository::findAllLatest()` (commit 442689d). Omitting it risks wrong prices.
- **Fallback when no snapshot for a holding.** `LEFT JOIN` so positions without a matching snapshot still appear; use `COALESCE(s.price_at_snapshot, h.avg_entry_price)` as the display price and label it "(aprox)" in the template when falling back.

---

## Phase 1: Controller, Route, Nav, Repository Extension

### Overview

Extend `PortfolioRepository` with a price-enriched holdings query, create `PortfolioController`, register `GET /portfolio`, and add the "Portfel" nav link in `layout.php`.

### Changes Required

#### 1. PortfolioRepository::getCurrentHoldingsWithPrice()

**File:** `src/Portfolio/PortfolioRepository.php`

**Intent:** Add a new read method that enriches holdings with the latest screener snapshot price for each ticker, so the controller can display live-ish market value without a separate Yahoo fetch.

**Contract:** New public method:

```
getCurrentHoldingsWithPrice(string $liveModelVersion): array[]
```

Each returned row: `['ticker', 'quantity', 'avg_entry_price', 'live_price', 'price_is_snapshot' (bool), 'value_usd', 'updated_at']`

SQL pattern — LEFT JOIN `portfolio_holdings h` with a subquery that selects `MAX(scored_at)` per ticker from `cvs_snapshots` filtered by `model_version = ?` AND `origin = 'RESCORE'`, then JOIN back to get `price_at_snapshot`. Use `COALESCE(s.price_at_snapshot, h.avg_entry_price)` as `live_price`. Compute `value_usd = h.quantity * live_price` in PHP after fetch (avoids DECIMAL arithmetic mismatch). Return rows ordered by `ticker ASC`.

#### 2. PortfolioController

**File:** `src/Portfolio/PortfolioController.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Single-action controller for the portfolio view. Loads all required data and computes the next trading day for the empty-cycle state message.

**Contract:** Constructor takes no required args (loads config and DB from globals like `ScreenerController`). Public method:

- `index(Request $req): void`
  1. `AuthController::requireAuth()`
  2. Load `config/cvs-weights.php` for `$liveModelVersion`
  3. Load `config/portfolio.php` for `$portfolioConfig` (MarketCalendar needs it)
  4. Instantiate `PortfolioRepository`, `MarketCalendar`
  5. `$state = $portfolioRepo->getCurrentState()` — cash, initial_capital
  6. `$holdings = $portfolioRepo->getCurrentHoldingsWithPrice($liveModelVersion)`
  7. `$latestCycle = $portfolioRepo->getLatestCycle()` — null if no cycle yet
  8. Compute `$totalValue = (float)$state['cash'] + array_sum(array_column($holdings, 'value_usd'))`
  9. Compute `$nextTradingDay`: starting from tomorrow (Warsaw timezone), loop forward using `MarketCalendar::isMarketDay()` until a market day is found (max 7 days look-ahead)
  10. `Response::view('portfolio', compact('state', 'holdings', 'latestCycle', 'totalValue', 'nextTradingDay', 'portfolioConfig'))`

#### 3. Route registration

**File:** `src/Core/routes.php`

**Intent:** Register `GET /portfolio` pointing to `PortfolioController::index()`. Follows the same inline closure pattern used for `/screener`.

**Contract:** Add after the screener route:
```php
$router->get('/portfolio', fn($req) => (new CVS\Portfolio\PortfolioController())->index($req));
```

#### 4. Navigation link

**File:** `templates/layout.php`

**Intent:** Add "Portfel" link in the main nav between the existing "Screener" and "Track Record" entries, visible to all logged-in users.

**Contract:** In the nav block (lines 36–55, inside `if (!empty($_SESSION['user_id']))`), add an `<a>` tag after the Screener link and before the Track Record link. Use the same `<a class="nav-link ...">` class as adjacent items. Mark active if `$_SERVER['REQUEST_URI']` starts with `/portfolio`.

### Success Criteria

#### Automated Verification

- `php -l src/Portfolio/PortfolioController.php` passes
- `php -l src/Portfolio/PortfolioRepository.php` passes
- PHPStan level 6 passes: `./vendor/bin/phpstan analyse src/Portfolio/ --level=6`
- `php -l templates/layout.php` passes (template syntax check)

#### Manual Verification

- `GET /portfolio` as logged-in user → HTTP 200, no PHP errors in logs
- `GET /portfolio` without session → redirects to `/login`
- "Portfel" nav link appears between Screener and Track Record in nav bar
- Active state highlight on "Portfel" when on `/portfolio`

**Implementation Note:** After passing automated checks and manual nav/route verification, pause before Phase 2.

---

## Phase 2: Template templates/portfolio.php

### Overview

Implement the full portfolio view template: summary cards at top, holdings table with live prices, latest cycle status block, and two empty states (no holdings, no cycles).

### Changes Required

#### 1. Portfolio template

**File:** `templates/portfolio.php`

**Intent:** Server-rendered template that turns the controller's data into a clear, scannable portfolio view. Follows `screener.php` visual conventions (`.card`, `.pillar-table`, CSS variable colours).

**Contract:** Template receives these variables from `Response::view()`:
- `$state` — `['cash', 'initial_capital', 'updated_at']`
- `$holdings` — array of enriched holding rows (may be empty)
- `$latestCycle` — latest `rebalance_cycle` row or null
- `$totalValue` — float (cash + holdings value)
- `$nextTradingDay` — `DateTimeImmutable` or null
- `$portfolioConfig` — `['initial_capital_usd', ...]`

**Page structure:**

**Section 1 — Summary cards (always visible):**
Three side-by-side `.stat-card` divs (or equivalent inline blocks):
- "Gotówka": `$state['cash']` formatted as `$X XXX.XX USD`
- "Wycena portfela": `$totalValue` formatted similarly; subtitle "cash + pozycje"
- "Kapitał startowy": `$portfolioConfig['initial_capital_usd']` with P&L delta in green/red (totalValue - initial)

**Section 2 — Holdings table:**
- If `empty($holdings)`: `.info-block` with text "Portfel w 100% gotówkowy. Brak otwartych pozycji."
- If holdings exist: `.card` wrapping `.pillar-table`:
  - Columns: Ticker | Ilość | Cena zakupu | Cena rynkowa | Wartość | % portfela
  - `live_price` marked with `(aprox)` CSS class when `price_is_snapshot === false`
  - `% portfela` = `value_usd / totalValue * 100` formatted to 1 decimal

**Section 3 — Ostatni rebalans:**
- If `$latestCycle === null`:
  ```
  .info-block with:
  "Pierwszy autonomiczny cykl rebalansowania jeszcze nie wystąpił."
  If $nextTradingDay: "Następny planowany: [dzień tygodnia], [data]."
  ```
- If `$latestCycle` exists:
  - Date: `$latestCycle['cycle_date']`
  - Status chip: map status string → CSS class (`completed` → `--c-success`, `llm_failed` → `--c-danger`, `started` → `--c-warn`, default → `--c-muted`)
  - Executed / Skipped count badges
  - Notes field: show `$latestCycle['notes']` if not null, else omit block
  - Timestamp: `$latestCycle['finished_at']`

**Page title:** `<h1>Wirtualny Portfel</h1>` with subtitle "Portfel globalny CVS — zarządzany autonomicznie"

**Disclaimer:** Reuse `.disclaimer-inline` pattern from screener.php at page bottom with standard CVS disclaimer.

### Success Criteria

#### Automated Verification

- `php -l templates/portfolio.php` passes
- PHPStan level 6 still clean after template-side PHP changes

#### Manual Verification

- Page loads with 10 000 USD cash, no holdings, "brak pozycji" message visible
- "Następny planowany: Poniedziałek, 29.06.2026" (or correct next market day) displayed
- After first autonomous cycle (Monday): holdings table populated, latest cycle block shows status=completed, executed_count, notes
- Summary card P&L delta shows 0.00 PLN/USD in neutral colour on initial state
- Holdings with snapshot price show live price; holdings without snapshot show avg_entry_price + "(aprox)"
- No PHP warnings in production error log
- Page renders correctly on mobile (existing responsive CSS handles it)

**Implementation Note:** This is the final phase. Manual verification requires waiting for or simulating a cycle with data. Confirm visual correctness on both empty and populated states before closing S-01.

---

## Testing Strategy

### Unit Tests

- Extend `tests/Portfolio/PortfolioRepositoryTest.php` with `getCurrentHoldingsWithPrice()` tests:
  - Empty holdings → returns `[]`
  - Holding with matching snapshot → `live_price = price_at_snapshot`, `price_is_snapshot = true`
  - Holding without snapshot → `live_price = avg_entry_price`, `price_is_snapshot = false`

### Manual Testing Steps

1. Visit `/portfolio` while logged in — page loads, shows 10 000 USD cash, empty holdings, "brak cyklu" message
2. Verify "Następny planowany" shows correct next trading day
3. Check "Portfel" nav link active state on `/portfolio`
4. Visit `/portfolio` without login → redirect to `/login`
5. After first cycle (Monday): verify holdings table, cycle status block, P&L delta

## References

- F-02 plan + PortfolioRepository: `context/changes/virtual-portfolio-ledger/plan.md`
- Screener pattern: `src/Screener/ScreenerController.php`, `templates/screener.php`
- Auth pattern: `src/Auth/AuthController.php:241–247`
- Layout nav: `templates/layout.php:36–55`
- model_version filter lesson: `context/foundation/lessons.md` (Filtruj shadow model_version)
- PRD: `context/foundation/prd-virtual-portfolio.md` (US-01, FR-012, FR-017)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Controller, Route, Nav, Repository Extension

#### Automated

- [x] 1.1 php -l src/Portfolio/PortfolioController.php passes — 7b13ecc
- [x] 1.2 php -l src/Portfolio/PortfolioRepository.php passes — 7b13ecc
- [x] 1.3 PHPStan level 6 passes for src/Portfolio/ — 7b13ecc
- [x] 1.4 php -l templates/layout.php passes — 7b13ecc

#### Manual

- [x] 1.5 GET /portfolio as logged-in user → HTTP 200, no PHP errors — 7b13ecc
- [x] 1.6 GET /portfolio without session → redirect to /login — 7b13ecc
- [x] 1.7 "Portfel" nav link appears between Screener and Track Record — 7b13ecc
- [x] 1.8 Active state highlight on "Portfel" when on /portfolio — 7b13ecc

### Phase 2: Template templates/portfolio.php

#### Automated

- [x] 2.1 php -l templates/portfolio.php passes
- [x] 2.2 PHPStan level 6 still clean

#### Manual

- [x] 2.3 Empty state: 10 000 USD cash, "brak pozycji", "brak cyklu" message with next trading day
- [x] 2.4 Summary card P&L delta shows 0.00 in neutral colour on initial state
- [x] 2.5 After first cycle: holdings table populated, cycle status block visible
- [x] 2.6 Holdings with snapshot → live price; without → avg_entry_price + (aprox)
- [x] 2.7 No PHP warnings in production error log
- [x] 2.8 Page renders correctly on mobile
