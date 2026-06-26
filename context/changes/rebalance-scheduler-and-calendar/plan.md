# F-01: Rebalance Scheduler and Market Calendar Gate — Implementation Plan

## Overview

Deliver the deterministic daily scheduler gate for the virtual portfolio rebalance cycle. This is the entry point (`bin/portfolio-rebalance.php`) and the two supporting classes (`MarketCalendar`, `CycleRepository`) that together answer: "Should the rebalance engine run right now?" If yes — hand off to the engine stub. If no — log why and exit cleanly.

## Current State Analysis

No virtual portfolio code exists. Greenfield namespace `CVS\Portfolio\` within a mature brownfield PHP 8.2 app.

Existing patterns to follow:
- `bin/rescore.php`, `bin/check_price_alerts.php`, `bin/refresh_peer_medians.php` — identical CLI scaffolding (PHP_SAPI guard, ROOT_PATH, log closure, .env loader, autoload)
- `Database::connection()` PDO singleton with `Database::reconnect()` for long-running scripts
- `config/cvs-weights.php` — PHP array returned, read from `$_ENV`, never hardcoded values
- `database/migrations/NNN_*.sql` — additive SQL files, numbered sequentially (latest: 023)
- `DateTimeImmutable` with explicit `DateTimeZone` objects — never rely on system timezone default

## Desired End State

- `bin/portfolio-rebalance.php` can be registered as two CyberFolks cron entries (20:30 and 21:30 Warsaw, Mon–Fri) and self-selects the correct one per day via a runtime ET-window check.
- Script correctly skips non-trading days (weekends + NYSE holidays) with a `market_closed` log entry.
- Script skips duplicate runs within the same day via a `rebalance_cycle` DB row, logging `already_started`.
- Script skips runs outside the rebalance window with `outside_rebalance_window` log entry.
- On a valid run: inserts a `rebalance_cycle` row with `status = started`, calls the engine stub (placeholder for F-03), updates status to `completed` or `failed`.
- `MarketCalendar` is unit-testable (injected `DateTimeImmutable`, no side effects).

### Key Discoveries

- No timezone config exists anywhere — must use explicit `DateTimeZone` objects, never `date_default_timezone_set()` globally
- CyberFolks drops idle MySQL connections after 60–120s; `Database::reconnect()` is available for long-running scripts but not needed here (gate is fast)
- PHP 8.2 binary on CF: `/usr/local/bin/php84` (must appear in cron comment)
- `config/cvs-weights.php` has existing `batch_schedule` (day-of-week keyed array) — portfolio config follows same return-array pattern
- Next migration number: 024

## What We're NOT Doing

- LLM decision logic — that is F-03
- Portfolio positions / cash ledger — that is F-02 (extends the minimal cycle table created here)
- HTTP routes for portfolio views — that is S-01
- Price fetching or screener signal ingestion — that is S-02
- Half-day / early-close NYSE sessions (treat them as full trading days in MVP)
- Per-user portfolio state — MVP is global only

## Implementation Approach

Three-layer foundation: config + migration → service classes → CLI entry point.

**DST strategy:** All market-time checks convert to `America/New_York` via PHP's built-in `DateTimeZone`. Warsaw time is never used for comparisons — only for the cron schedule comment. PHP handles all US and EU DST transitions automatically; no manual offset math.

**Dual-cron strategy:** Two cron entries (20:30 + 21:30 Warsaw) fire every weekday. The script's window check (`isInRebalanceWindow`) passes for exactly one of them per day regardless of DST offset. The other silently exits with `outside_rebalance_window`.

**Idempotency:** A `rebalance_cycle` row with `cycle_date = today` is inserted at script start. If the row already exists (UNIQUE constraint on `cycle_date`), a second run detects this and exits with `already_started`. F-02 will `ALTER TABLE` this table to add position, cash, and history columns.

## Critical Implementation Details

- **Timezone objects must be explicit everywhere.** `new DateTimeImmutable('now', new DateTimeZone('America/New_York'))` — never bare `new DateTimeImmutable()` in this module, because the server timezone is unknown and may not be Warsaw.
- **UNIQUE constraint on `rebalance_cycle.cycle_date`** is the idempotency mechanism. Use `INSERT IGNORE` (not UPSERT) so a second run detects the existing row by checking affected rows === 0.
- **The engine stub in Phase 3 must be a named placeholder** (`$this->runEngine()` or a static call to a non-existent `RebalanceEngine::run()` wrapped in a check) so F-03 can wire it without touching the bin script's scaffold.

---

## Phase 1: Config file and DB migration

### Overview

Create `config/portfolio.php` with all business parameters for the scheduler gate, and `database/migrations/024_create_rebalance_cycle.sql` with the minimal cycle table that enables DB idempotency and will be extended by F-02.

### Changes Required

#### 1. Portfolio config file

**File:** `config/portfolio.php`

**Intent:** Centralise all scheduler and market-calendar parameters so they can be changed without code edits. Follows the exact same pattern as `config/cvs-weights.php` (returns a PHP array, reads from `$_ENV` for secrets, hardcodes stable constants directly).

**Contract:** Returns an associative array with keys:

- `initial_capital_usd` — float, 10000.0
- `market` — array with `open_time` ('09:30'), `close_time` ('16:00'), `timezone` ('America/New_York')
- `rebalance_window_minutes` — int, 30 (window before close when rebalance is allowed)
- `holidays` — array of ISO date strings for NYSE non-trading days 2025–2027 (9 dates/year × 3 years ≈ 27 entries); include all 9 standard NYSE holidays for each year

**NYSE holidays to include (standard list):**
- New Year's Day (Jan 1, or observed Friday/Monday)
- Martin Luther King Jr. Day (3rd Monday January)
- Presidents' Day (3rd Monday February)
- Good Friday
- Memorial Day (last Monday May)
- Juneteenth (June 19, or observed)
- Independence Day (July 4, or observed)
- Labor Day (1st Monday September)
- Thanksgiving Day (4th Thursday November)
- Christmas Day (Dec 25, or observed)

#### 2. Rebalance cycle migration

**File:** `database/migrations/024_create_rebalance_cycle.sql`

**Intent:** Create the minimal `rebalance_cycle` table that provides DB-level idempotency (UNIQUE on `cycle_date`) and a status audit trail. F-02 will extend this table with `ALTER TABLE` to add positions, cash snapshots, and LLM reasoning columns.

**Contract:**

```sql
CREATE TABLE rebalance_cycle (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_date    DATE         NOT NULL,
    status        VARCHAR(32)  NOT NULL DEFAULT 'started',
    started_at    DATETIME     NOT NULL,
    finished_at   DATETIME     NULL,
    UNIQUE KEY uq_cycle_date (cycle_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`status` values used by F-01: `started`, `completed`, `failed`, `market_closed`, `outside_window`. F-02/F-03 will add: `llm_failed`, `no_action`, `insufficient_cash`.

### Success Criteria

#### Automated Verification

- Migration applies cleanly on a fresh schema: `mysql -u root cvs_db < database/migrations/024_create_rebalance_cycle.sql`
- `config/portfolio.php` is syntactically valid: `php -l config/portfolio.php`
- Config returns correct structure: `php -r "var_dump(require 'config/portfolio.php');"` shows all required keys
- Duplicate `cycle_date` insert is rejected: `INSERT INTO rebalance_cycle (cycle_date, status, started_at) VALUES ('2026-01-01','started',NOW()); INSERT INTO rebalance_cycle (cycle_date, status, started_at) VALUES ('2026-01-01','started',NOW());` — second insert errors with duplicate key

#### Manual Verification

- All 2025, 2026, 2027 NYSE holidays present in `config/portfolio.php` holiday array, cross-checked against official NYSE calendar
- `rebalance_window_minutes` present and set to 30
- `initial_capital_usd` present and set to 10000.0

**Implementation Note:** After completing this phase and all automated verification passes, pause for manual confirmation before proceeding to Phase 2.

---

## Phase 2: MarketCalendar service and CycleRepository

### Overview

Implement the two service classes that the CLI script will call. `MarketCalendar` answers market-state questions; `CycleRepository` manages the `rebalance_cycle` table. Both are testable in isolation.

### Changes Required

#### 1. MarketCalendar service

**File:** `src/Portfolio/MarketCalendar.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Encapsulate all NYSE-calendar and rebalance-window logic. Receives a `DateTimeImmutable` as the "current time" parameter (never calls `new DateTimeImmutable()` internally) so it is deterministic and unit-testable.

**Contract:** Constructor takes `array $config` (the `market` and `holidays` and `rebalance_window_minutes` keys from `config/portfolio.php`). Public methods:

- `isMarketDay(DateTimeImmutable $now): bool` — returns false for Saturday/Sunday and for dates in `$config['holidays']`; converts `$now` to `America/New_York` timezone before date comparison
- `isInRebalanceWindow(DateTimeImmutable $now): bool` — converts `$now` to `America/New_York`, checks if current time is within `[close_time - rebalance_window_minutes, close_time]`; returns false outside that range
- `getStatus(DateTimeImmutable $now): string` — convenience method returning one of: `'market_closed'` (non-trading day), `'outside_rebalance_window'` (trading day but wrong time), `'ready'` (trading day, within window)

#### 2. CycleRepository

**File:** `src/Portfolio/CycleRepository.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Thin data-access layer for `rebalance_cycle`. Only the four operations needed by the CLI gate. F-02 will extend this class with holdings, cash, and decision columns.

**Contract:** Constructor takes `PDO $db`. Methods:

- `findTodayCycle(string $cycleDate): ?array` — SELECT by `cycle_date`, returns row as associative array or null
- `insertCycle(string $cycleDate): ?int` — `INSERT IGNORE INTO rebalance_cycle (cycle_date, status, started_at) VALUES (?, 'started', NOW())`; returns `lastInsertId()` as int on success, null if row already existed (affected rows = 0); null signals "already started today"
- `updateStatus(int $id, string $status): void` — UPDATE `status` and `finished_at = NOW()` by `id`

### Success Criteria

#### Automated Verification

- `php -l src/Portfolio/MarketCalendar.php` — no syntax errors
- `php -l src/Portfolio/CycleRepository.php` — no syntax errors
- PHPStan passes at level 6: `composer stan` — zero errors in `src/Portfolio/`
- Unit tests for `MarketCalendar`:
  - Saturday → `isMarketDay()` returns false
  - NYSE holiday (e.g., 2026-01-01) → `isMarketDay()` returns false
  - Regular Wednesday → `isMarketDay()` returns true
  - 15:35 ET on trading day → `isInRebalanceWindow()` returns true
  - 15:25 ET on trading day → `isInRebalanceWindow()` returns false (outside 30-min window)
  - 16:05 ET on trading day → `isInRebalanceWindow()` returns false (after close)
  - `getStatus()` returns correct string for each scenario

#### Manual Verification

- DST transition dates verify correctly: e.g., 2026-03-10 (US spring forward) at 20:35 Warsaw → should be 15:35 ET → `isInRebalanceWindow()` returns true

**Implementation Note:** Pause here for manual DST verification before proceeding to Phase 3.

---

## Phase 3: bin/portfolio-rebalance.php CLI entry point

### Overview

Wire the CLI script that CyberFolks cron will invoke. Follows the identical scaffold of `bin/rescore.php`. Calls `MarketCalendar` and `CycleRepository`, handles all exit paths (market_closed, outside_window, already_started, engine call), and logs every outcome.

### Changes Required

#### 1. CLI entry point

**File:** `bin/portfolio-rebalance.php`

**Intent:** Top-level orchestrator for the rebalance gate. Follows the exact existing `bin/` scaffold pattern. Must handle all five exit paths and leave the codebase ready for F-03 to wire in the real engine.

**Contract:** Script structure:

```
1. PHP_SAPI !== 'cli' guard (HTTP → 403 + exit)
2. ROOT_PATH define from __DIR__
3. Log file: ROOT_PATH . '/logs/portfolio-rebalance.log'
4. $log closure: [YYYY-MM-DD HH:MM:SS] format, FILE_APPEND | LOCK_EX
5. .env loader (identical to rescore.php — copy verbatim)
6. require autoload
7. $config = require ROOT_PATH . '/config/portfolio.php'
8. $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'))
9. $calendar = new CVS\Portfolio\MarketCalendar($config)
10. $status = $calendar->getStatus($now)

Exit path A — outside_rebalance_window:
  $log("outside_rebalance_window: " . $now->format('H:i T')); exit(0)

Exit path B — market_closed:
  $log("market_closed: " . $now->format('Y-m-d')); exit(0)

11. DB connect + CycleRepository
12. $cycleDate = $now->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d')
13. $id = $cycleRepository->insertCycle($cycleDate)

Exit path C — already_started (insertCycle returned null):
  $log("already_started: cycle $cycleDate already exists"); exit(0)

14. $log("cycle $cycleDate started (id=$id)")

Exit path D — engine call (stub for F-03):
  try {
      // F-03: wire CVS\Portfolio\RebalanceEngine::run($id, $config) here
      $log("engine stub: no-op (F-03 not yet implemented)");
      $cycleRepository->updateStatus($id, 'completed');
      $log("cycle $cycleDate completed");
  } catch (Throwable $e) {
      $cycleRepository->updateStatus($id, 'failed');
      $log("cycle $cycleDate FAILED: " . $e->getMessage());
      exit(1);
  }
exit(0)
```

The engine stub comment must be a clear F-03 hook, not an empty block, so future implementers see exactly where to wire.

#### 2. Cron entry comment block

**In:** `bin/portfolio-rebalance.php` header docblock (lines 10–20)

**Intent:** Document the two required CyberFolks cron entries (type "Ścieżka") so the operator can register them without reverse-engineering the timing logic.

**Contract:** Comment block:

```
 * Cron entries (CyberFolks panel → "Ścieżka" type, explicit PHP 8.2 path):
 *
 *   30 20 * * 1-5  /usr/local/bin/php84 /home/.../bin/portfolio-rebalance.php
 *   30 21 * * 1-5  /usr/local/bin/php84 /home/.../bin/portfolio-rebalance.php
 *
 * Two entries handle the DST offset shift between Europe/Warsaw and America/New_York.
 * The script's window check ensures only the correct one runs per day.
```

### Success Criteria

#### Automated Verification

- `php -l bin/portfolio-rebalance.php` — no syntax errors
- `php bin/portfolio-rebalance.php` on a Saturday → exits 0, logs `market_closed`
- `php bin/portfolio-rebalance.php` at an out-of-window time on a weekday → exits 0, logs `outside_rebalance_window`
- Second invocation within same cycle window → exits 0, logs `already_started`
- PHPStan at level 6 passes for the new Portfolio namespace: `composer stan`

#### Manual Verification

- Run script manually during a valid NYSE trading day within 15:30–16:00 ET; verify log shows `cycle YYYY-MM-DD started` and `cycle YYYY-MM-DD completed`
- Verify `rebalance_cycle` table has one row with `status = completed` after the above run
- Verify log file at `logs/portfolio-rebalance.log` follows `[YYYY-MM-DD HH:MM:SS]` format
- Second manual run within same day → `already_started` in log, no new DB row
- Next day re-run → new row created, script completes normally

**Implementation Note:** Pause for manual verification before marking F-01 done and proceeding to F-02/F-03.

---

## Testing Strategy

### Unit Tests

- `tests/Portfolio/MarketCalendarTest.php` — inject synthetic `DateTimeImmutable` values; test weekend, holiday, in-window, out-of-window, both DST offset scenarios (5h and 6h gap)
- `tests/Portfolio/CycleRepositoryTest.php` — use in-memory SQLite or test DB; test INSERT IGNORE dedup, status update

### Integration Tests

- Manual: run `bin/portfolio-rebalance.php` at the correct time on a trading day; inspect DB row and log file

### Manual Testing Steps

1. Apply migration 024; verify table exists with correct schema
2. Run script on a Saturday; confirm `market_closed` in log, no DB row
3. Run script on a weekday outside the window; confirm `outside_rebalance_window` in log
4. Mock or wait for the correct window; confirm `started` → `completed` in log and DB
5. Run again immediately; confirm `already_started` in log, no additional DB row
6. Run on a 2026 NYSE holiday (e.g., 2026-01-01); confirm `market_closed` in log

## Migration Notes

- Migration 024 must be applied before first cron run; additive only, no existing table touched
- F-02 will extend `rebalance_cycle` via `ALTER TABLE`; plan F-02 migration as 025

## References

- PRD: `context/foundation/prd-virtual-portfolio.md`
- Roadmap F-01: `context/foundation/roadmap-virtual-portfolio.md`
- Existing cron scaffold: `bin/rescore.php`
- Config pattern: `config/cvs-weights.php`
- Timezone-aware DateTime: PHP built-in `DateTimeZone('America/New_York')` handles all DST

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Config file and DB migration

#### Automated

- [ ] 1.1 Migration 024 applies cleanly on fresh schema
- [x] 1.2 `php -l config/portfolio.php` passes — d405663
- [x] 1.3 Config returns all required keys (initial_capital_usd, market, rebalance_window_minutes, holidays) — d405663
- [ ] 1.4 Duplicate cycle_date insert rejected with unique key error

#### Manual

- [x] 1.5 All 2025–2027 NYSE holidays verified against official NYSE calendar — d405663
- [x] 1.6 rebalance_window_minutes = 30 and initial_capital_usd = 10000.0 confirmed in config — d405663

### Phase 2: MarketCalendar service and CycleRepository

#### Automated

- [x] 2.1 `php -l src/Portfolio/MarketCalendar.php` passes
- [x] 2.2 `php -l src/Portfolio/CycleRepository.php` passes
- [x] 2.3 PHPStan level 6 passes for src/Portfolio/
- [x] 2.4 MarketCalendar unit tests pass (Saturday, holiday, in-window, out-of-window, both DST offsets)

#### Manual

- [x] 2.5 DST transition date 2026-03-10 verified: 20:35 Warsaw → 15:35 ET → isInRebalanceWindow true

### Phase 3: bin/portfolio-rebalance.php CLI entry point

#### Automated

- [ ] 3.1 `php -l bin/portfolio-rebalance.php` passes
- [ ] 3.2 Script run on Saturday exits 0, logs market_closed
- [ ] 3.3 Script run outside window exits 0, logs outside_rebalance_window
- [ ] 3.4 Second invocation same day exits 0, logs already_started
- [ ] 3.5 PHPStan level 6 passes for Portfolio namespace

#### Manual

- [ ] 3.6 Valid trading day in-window run: log shows started + completed, DB row status = completed
- [ ] 3.7 Log file format matches [YYYY-MM-DD HH:MM:SS] pattern
- [ ] 3.8 Second same-day run: already_started in log, no new DB row
- [ ] 3.9 Next-day run: new DB row created
- [ ] 3.10 NYSE holiday (2026-01-01): market_closed in log, no DB row
