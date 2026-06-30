# Full Rebalance History and Reason Timeline (S-03) — Implementation Plan

## Overview

Add a dedicated `/portfolio/history` page that shows the complete rebalance
timeline. Successful cycles appear as a paginated, chronological list of
collapsible cards (date, status, transaction counts, portfolio value + Δ vs the
previous cycle); each card expands to the full BUY/SELL/HOLD/SKIP/NO_ACTION list
with the LLM reasoning. Failed/operational cycles (timeout / llm_failed / failed /
stuck started) live in a separate collapsible "zdarzenia operacyjne" section.
Read-only; builds on existing repository groundwork.

## Current State Analysis

- `/portfolio` (S-01) shows only the **latest** cycle. There is no way to browse
  history. `templates/portfolio.php` already renders a single latest-cycle card
  via a `statusChip` closure and the project's card styling.
- `PortfolioRepository` already has `getCycleHistory()` (all cycles, newest first)
  and `getTransactionsByCycle(int $cycleId)` — both currently **unused** (no route,
  no view). They are not split by status and would cause N+1 if used per-cycle.
- Schema is sufficient (no migration needed):
  - `rebalance_cycle`: `cycle_date`, `status`, `executed_count`, `skipped_count`,
    `portfolio_value_usd`, `cash_before/after`, `notes`, `llm_failure_kind`,
    `attempt_count`, `finished_at`.
  - `portfolio_transactions`: `cycle_id`, `ticker`, `action`
    (BUY|SELL|HOLD|NO_ACTION|SKIP_INSUFFICIENT_CASH), `quantity`, `price_usd`,
    `status`, `reason` (TEXT), `executed_at`. Indexed on `cycle_id`.
- Patterns to follow: `PortfolioController::index()` (auth guard + `Response::view`),
  `src/Core/routes.php` portfolio route, `templates/portfolio.php` (statusChip,
  card markup), native `<details>`/`<summary>` already idiomatic for zero-JS collapse.

## Desired End State

A logged-in user opens `/portfolio/history` (linked from `/portfolio`) and sees:
- the full list of **completed** rebalances, newest first, ~30 at a time with a
  "Pokaż starsze" control to reveal older ones;
- each cycle as a collapsed card showing date, status, executed/skipped counts,
  portfolio value and the change vs the previous completed cycle; clicking expands
  the per-position decisions with reasons;
- a separate collapsible "zdarzenia operacyjne" section listing failed cycles
  (date, failure kind, attempt count, notes).

Verify: page returns 200 when authenticated (302 to /login otherwise); cards are
collapsed by default and expand on click; Δ is green/red; "Pokaż starsze" reveals
older cycles; failed cycles never appear in the main timeline.

### Key Discoveries:
- Repository methods exist but are unsplit and N+1-prone — `src/Portfolio/PortfolioRepository.php:118,136`.
- `statusChip` closure lives inside `templates/portfolio.php:21` — reuse by redefining the small closure in the history template (project convention; templates are plain PHP).
- `'completed'` is the only success status; everything else (`failed`, `llm_failed`, `started`) is an operational event.
- Native `<details>` gives "all collapsed, click to expand" with zero JS (already used for popovers/clock sprinkles elsewhere).

## What We're NOT Doing

- No schema/migration changes.
- No performance/SPY analytics on the cards beyond portfolio value + Δ (that is S-05).
- No screener-vs-held comparison (that is S-04).
- No editing/replay of cycles — strictly read-only.
- No per-transaction deep links or filtering UI (search/filter by ticker) in this slice.
- No changes to the rebalance engine, decision pipeline, or `/portfolio` data model.

## Implementation Approach

Three phases following the project's data → store → controller → view flow.
Pagination is **cumulative window** via a single `?show=N` query param (no API
endpoint, no client state): the page renders the newest `N` completed cycles and,
if more exist, a "Pokaż starsze" link to `?show=N+30`. The controller fetches
`N+1` completed cycles so it can both detect "more exist" and compute the Δ of the
last visible card (Δ[i] = value[i] − value[i+1], newest-first). Transactions for
the visible completed cycles are fetched in one batched query and grouped by
`cycle_id` to avoid N+1.

## Phase 1: Data layer — split, paginate, batch

### Overview
Add targeted, status-aware repository methods that the history view needs, with
SQLite-backed unit tests mirroring existing `tests/Portfolio` patterns.

### Changes Required:

#### 1. Completed-cycle pagination + count
**File**: `src/Portfolio/PortfolioRepository.php`
**Intent**: Provide the main timeline's data: completed cycles newest-first with
limit/offset, plus a total count so the view can decide whether to show "Pokaż starsze".
**Contract**: `getCompletedCyclesPage(int $limit, int $offset = 0): array` —
`WHERE status = 'completed' ORDER BY cycle_date DESC, id DESC LIMIT ? OFFSET ?`.
`countCompletedCycles(): int`. Returns raw cycle rows (assoc).

#### 2. Operational (failed) cycles
**File**: `src/Portfolio/PortfolioRepository.php`
**Intent**: Feed the separate "zdarzenia operacyjne" section — every non-completed cycle.
**Contract**: `getOperationalCycles(): array` — `WHERE status <> 'completed' ORDER BY cycle_date DESC, id DESC`. Few rows; no pagination.

#### 3. Batched transactions (anti-N+1)
**File**: `src/Portfolio/PortfolioRepository.php`
**Intent**: Fetch all transactions for the visible completed cycles in one query, grouped per cycle, preserving insertion order.
**Contract**: `getTransactionsForCycles(array $cycleIds): array` → `array<int, list<array>>` keyed by `cycle_id`. Empty input → `[]`. Query: `WHERE cycle_id IN (…) ORDER BY cycle_id ASC, id ASC` with placeholders; group in PHP. (Snippet only because the dynamic `IN (…)` placeholder build is the one non-obvious bit.)
```php
$in = implode(',', array_fill(0, count($cycleIds), '?'));
$stmt = $this->db->prepare("SELECT * FROM portfolio_transactions WHERE cycle_id IN ($in) ORDER BY cycle_id ASC, id ASC");
```

#### 4. Unit tests
**File**: `tests/Portfolio/PortfolioHistoryRepositoryTest.php` (new)
**Intent**: Lock the contracts with an in-memory SQLite schema, as in `CycleRepositoryTest`.
**Contract**: cover (a) completed page limit/offset ordering, (b) count, (c) operational bucket excludes `completed`, (d) batched transactions grouped by cycle preserving id order, (e) empty `cycleIds` → `[]`.

### Success Criteria:

#### Automated Verification:
- Linting/syntax passes: `php -l src/Portfolio/PortfolioRepository.php`
- New tests pass: `vendor/bin/phpunit tests/Portfolio/PortfolioHistoryRepositoryTest.php`
- Full suite green: `vendor/bin/phpunit`
- Static analysis clean: `vendor/bin/phpstan analyse`

#### Manual Verification:
- (none — pure data layer, covered by tests)

**Implementation Note**: After automated verification passes, proceed (no manual step for this phase).

---

## Phase 2: Controller + route

### Overview
Add `GET /portfolio/history` and a `PortfolioController::history()` action that
assembles the view model: paginated completed cards with Δ, operational events,
and the "more exist" flag.

### Changes Required:

#### 1. Route
**File**: `src/Core/routes.php`
**Intent**: Register the authenticated history page next to `/portfolio`.
**Contract**: `$router->get('/portfolio/history', fn($req) => $portfolio->history($req));` (reuse the existing `$portfolio` instance).

#### 2. Controller action
**File**: `src/Portfolio/PortfolioController.php`
**Intent**: Build the history view model. Read the cumulative window size from the
query string (`?show`, default 30, sane upper clamp); fetch `show+1` completed
cycles to detect overflow and compute the last card's Δ; group their transactions;
fetch operational cycles; pass everything to the template.
**Contract**: `history(Request $req): void`. Calls `AuthController::requireAuth()`.
Reads `show` via the existing query accessor on `Request` (fallback `$_GET`),
clamps to `[30, e.g. 3000]`. Computes per-card `pnl_delta = value[i] − value[i+1]`
over the completed list (newest-first); the `show+1`-th row supplies the delta for
the last visible card and the `hasMore` flag, then is dropped from display.
`Response::view('portfolio-history', compact('completed', 'transactionsByCycle', 'operational', 'hasMore', 'nextShow'))`.

### Success Criteria:

#### Automated Verification:
- Syntax passes: `php -l src/Portfolio/PortfolioController.php src/Core/routes.php`
- Static analysis clean: `vendor/bin/phpstan analyse`
- Full suite green: `vendor/bin/phpunit`

#### Manual Verification:
- `GET /portfolio/history` returns 302 when logged out, 200 when authenticated.
- View model is correct: completed list excludes failed cycles; Δ matches value differences; `hasMore`/`nextShow` correct around the 30-cycle boundary.

**Implementation Note**: After automated verification passes, pause for manual confirmation before Phase 3.

---

## Phase 3: View, link, and styling

### Overview
Render the history template and link it from `/portfolio`.

### Changes Required:

#### 1. History template
**File**: `templates/portfolio-history.php` (new)
**Intent**: Render the timeline. Header + back-link to `/portfolio`. Each completed
cycle is a collapsed `<details>` card (summary: date, status chip, executed/skipped,
portfolio value + colored Δ; body: transaction table with ticker/action/qty/price/reason).
A "Pokaż starsze" link to `?show=<nextShow>` when `hasMore`. A separate `<details>`
"zdarzenia operacyjne" section listing operational cycles (date, failure-kind chip,
attempt count, notes). Empty state when there is no history.
**Contract**: consumes `completed`, `transactionsByCycle`, `operational`, `hasMore`,
`nextShow`. Redefine the small `statusChip` closure locally (mirrors `portfolio.php`).
Money/`%` formatting consistent with `portfolio.php` helpers. All cards collapsed by default.

#### 2. Link from portfolio page
**File**: `templates/portfolio.php`
**Intent**: Add a "Zobacz pełną historię →" link near the "Ostatni rebalans" heading.
**Contract**: anchor to `/portfolio/history`; no logic change.

#### 3. Styles
**File**: `public/css/app.css`
**Intent**: Minimal styling for the `<details>` summary (pointer cursor, spacing),
transaction rows, and Δ up/down colors — reusing existing `card`, `signal-pill`, and
color variables.
**Contract**: new small rule block (e.g. `.cycle-card`, `.cycle-delta--pos/--neg`);
no changes to existing selectors.

### Success Criteria:

#### Automated Verification:
- Templates parse: `php -l templates/portfolio-history.php templates/portfolio.php`
- Static analysis clean: `vendor/bin/phpstan analyse`
- Full suite green: `vendor/bin/phpunit`

#### Manual Verification:
- `/portfolio` shows the "Zobacz pełną historię →" link; it navigates to the page.
- Cards are collapsed by default; clicking a card expands the transactions + reasons.
- Δ is green for gains, red for losses; first-ever cycle shows no Δ (or "—").
- "Pokaż starsze" reveals older cycles; disappears when all are shown.
- Failed cycles appear ONLY in "zdarzenia operacyjne", never in the main timeline.
- Empty state renders when the portfolio has no cycles yet.

**Implementation Note**: After automated verification passes, pause for manual confirmation that the page looks and behaves correctly.

---

## Testing Strategy

### Unit Tests:
- `PortfolioHistoryRepositoryTest` (SQLite in-memory): completed pagination + ordering,
  count, operational bucket exclusion, batched-transaction grouping/order, empty input.

### Integration / Manual Testing Steps:
1. Log in; open `/portfolio`, click "Zobacz pełną historię →".
2. Confirm completed cycles listed newest-first, collapsed; expand one and verify
   transactions + reasons match the DB for that cycle.
3. Verify portfolio value + Δ against two consecutive cycles.
4. Force >30 completed cycles (or temporarily lower the default window) and verify
   "Pokaż starsze" paging.
5. Confirm a failed cycle (e.g. an existing `llm_failed` row) shows only under
   "zdarzenia operacyjne".

## Performance Considerations

Batched transaction fetch (one `IN (…)` query) avoids N+1. The cumulative window
caps rows rendered; the upper clamp on `?show` bounds worst-case query size. Indexes
exist on `portfolio_transactions.cycle_id`; cycle queries filter/order on `status` +
`cycle_date` (small table, one row/day).

## Migration Notes

None — no schema changes.

## References

- Change identity: `context/changes/full-history-and-reason-log/change.md`
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (S-03)
- Reuse: `src/Portfolio/PortfolioController.php`, `templates/portfolio.php`, `src/Portfolio/PortfolioRepository.php:118`
- Test pattern: `tests/Portfolio/CycleRepositoryTest.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Data layer — split, paginate, batch

#### Automated
- [x] 1.1 Linting/syntax passes: `php -l src/Portfolio/PortfolioRepository.php` — cd9e542
- [x] 1.2 New tests pass: `vendor/bin/phpunit tests/Portfolio/PortfolioHistoryRepositoryTest.php` — cd9e542
- [x] 1.3 Full suite green: `vendor/bin/phpunit` — cd9e542
- [x] 1.4 Static analysis clean: `vendor/bin/phpstan analyse` — cd9e542

### Phase 2: Controller + route

#### Automated
- [x] 2.1 Syntax passes: `php -l src/Portfolio/PortfolioController.php src/Core/routes.php`
- [x] 2.2 Static analysis clean: `vendor/bin/phpstan analyse`
- [x] 2.3 Full suite green: `vendor/bin/phpunit`

#### Manual
- [ ] 2.4 `/portfolio/history` returns 302 logged out, 200 authenticated
- [ ] 2.5 View model correct: completed excludes failed; Δ matches; hasMore/nextShow correct at the 30-cycle boundary

### Phase 3: View, link, and styling

#### Automated
- [ ] 3.1 Templates parse: `php -l templates/portfolio-history.php templates/portfolio.php`
- [ ] 3.2 Static analysis clean: `vendor/bin/phpstan analyse`
- [ ] 3.3 Full suite green: `vendor/bin/phpunit`

#### Manual
- [ ] 3.4 Link from `/portfolio` navigates to history page
- [ ] 3.5 Cards collapsed by default; expand shows transactions + reasons
- [ ] 3.6 Δ colored correctly; first cycle shows no Δ
- [ ] 3.7 "Pokaż starsze" reveals older cycles; hides when all shown
- [ ] 3.8 Failed cycles appear only under "zdarzenia operacyjne"
- [ ] 3.9 Empty state renders with no cycles
