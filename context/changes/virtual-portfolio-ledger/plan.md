# F-02: Virtual Portfolio Ledger Contract — Implementation Plan

## Overview

Create the full persistence layer for the virtual portfolio: database schema (migrations 025–026), read repository (`PortfolioRepository`), and atomic write service (`PortfolioService`). This ledger is the source of truth for all downstream features — S-01 (read-only view), F-03 (LLM execution), S-02 (first full rebalance), S-03 (history), and S-05 (stats) all depend on this contract being stable.

## Current State Analysis

F-01 delivered:
- `rebalance_cycle` table (`id`, `cycle_date` UNIQUE, `status`, `started_at`, `finished_at`)
- `CycleRepository` with `findTodayCycle`, `insertCycle`, `updateStatus`
- `bin/portfolio-rebalance.php` with engine stub placeholder

Existing codebase patterns to follow:
- `Database::connection()` PDO singleton; `Database::reconnect()` for long-running scripts
- All repositories use `$db->prepare()`→`execute()`→`fetch()`/`fetchAll()`; no ORM
- `DECIMAL(12,2)` not FLOAT for money — consistent with PHP money handling across the stack
- All tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
- PHPStan level 6 + `declare(strict_types=1)` + explicit type hints on all method boundaries

No virtual portfolio code beyond F-01 exists. This is greenfield.

## Desired End State

After F-02:
- `portfolio_state` has exactly one row (seeded by migration): `cash=10000.00, initial_capital=10000.00`
- `portfolio_holdings` is empty (no positions at start)
- `portfolio_transactions` is empty (no transactions yet)
- `rebalance_cycle` has the F-02 summary columns (`cash_before`, `cash_after`, `executed_count`, `skipped_count`, `notes`, `portfolio_value_usd`)
- `PortfolioRepository::getCurrentState()` returns the seeded state
- `PortfolioService::executeCycle()` can atomically process a list of decisions, update all three tables and the cycle row in a single DB transaction, and roll back everything on any exception
- `PortfolioService::ensureInitialized()` throws on a misconfigured portfolio (≠1 row in `portfolio_state`)

### Key Discoveries

- DECIMAL arithmetic in PHP requires explicit rounding (`round($val, 2)`) before INSERT — PDO passes PHP floats which can accumulate precision errors if not rounded at the service layer
- `portfolio_state` is a singleton (one global portfolio per PRD FR-017) — enforced by `ensureInitialized()` + documentation; no UNIQUE constraint enforces this at DB level beyond the single seed INSERT
- `portfolio_holdings` UNIQUE on `ticker` — allows safe UPSERT pattern (INSERT … ON DUPLICATE KEY UPDATE) when adding to an existing position
- The whole-cycle DB transaction wraps across all three portfolio tables AND the `rebalance_cycle` UPDATE — this is the only safe scope given that a partial write leaves the portfolio in an inconsistent state
- avg_entry_price on holdings uses weighted average: `(old_quantity * old_avg + new_quantity * new_price) / (old_quantity + new_quantity)`

## What We're NOT Doing

- LLM-specific columns on `rebalance_cycle` (`llm_raw_response`, `llm_decision_json`, `retry_count`) — those belong to F-03
- HTTP routes or templates — that is S-01
- Price fetching or screener signal ingestion — that is S-02
- `portfolio_value_usd` using live prices — the cycle-snapshot value uses transaction prices only (good enough for history, live value computed by S-01 read logic)
- Per-user portfolio variants — MVP is global only (FR-017)
- History retention caps — PRD Non-Goal #5 (full history always)

## Implementation Approach

Three-layer build: schema → read model → write model.

**Schema first** (migrations 025 + 026): extend `rebalance_cycle` with F-02 columns, then create the three portfolio tables in one migration with the seed INSERT. Migrations are additive and never touch existing tables beyond the ALTER.

**Read model** (`PortfolioRepository`): pure SELECT methods, no side effects, no transactions. All methods return plain PHP arrays following the pattern of `ScreenerRepository` and `TrackRecordRepository`. Called by S-01 views and stats.

**Write model** (`PortfolioService`): owns all mutations. The key invariant is that `executeCycle()` wraps its entire body in a PDO `beginTransaction()` / `commit()` / `rollBack()`. Individual buy/sell helpers are private and called only from within that transaction scope.

## Critical Implementation Details

- **DECIMAL rounding at service boundary.** Before any INSERT of a cash or price value, call `round((float)$value, 2)` for cash amounts and `round((float)$value, 4)` for prices. Never insert a raw PHP float division result.
- **Weighted avg_entry_price on partial BUY accumulation.** When adding to an existing holding, compute `round(($oldQty * $oldAvg + $newQty * $newPrice) / ($oldQty + $newQty), 4)` at the service layer before the UPSERT. This is not expressible cleanly in SQL without a subquery and is more readable in PHP.
- **SELECT within transaction for cash reads.** `executeCycle()` must SELECT the current cash value *inside* the open transaction (after `beginTransaction()`) to avoid a race with a second concurrent run — even though the lockfile/DB-idempotency from F-01 makes concurrent execution nearly impossible, reading cash inside the transaction is the correct pattern.

---

## Phase 1: DB Migrations

### Overview

Extend `rebalance_cycle` with F-02 execution summary columns, and create the three portfolio tables with the initial 10 000 USD cash seed.

### Changes Required

#### 1. Extend rebalance_cycle

**File:** `database/migrations/025_extend_rebalance_cycle.sql`

**Intent:** Add the portfolio-execution summary columns that F-02 writes at cycle end. LLM-specific columns (llm_raw_response, retry_count) are explicitly absent — those land in F-03's migration.

**Contract:**

```sql
ALTER TABLE rebalance_cycle
    ADD COLUMN cash_before        DECIMAL(12,2)    NULL    AFTER finished_at,
    ADD COLUMN cash_after         DECIMAL(12,2)    NULL    AFTER cash_before,
    ADD COLUMN portfolio_value_usd DECIMAL(12,2)   NULL    AFTER cash_after,
    ADD COLUMN executed_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER portfolio_value_usd,
    ADD COLUMN skipped_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER executed_count,
    ADD COLUMN notes              TEXT             NULL    AFTER skipped_count;
```

#### 2. Create portfolio tables with seed

**File:** `database/migrations/026_create_portfolio_tables.sql`

**Intent:** Create the three portfolio tables representing current state (mutable) and history (immutable). Seed `portfolio_state` with the initial 10 000 USD capital so downstream code never needs to handle an empty state.

**Contract:** Three tables + one seed INSERT:

`portfolio_state` — singleton mutable row:
- `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `cash DECIMAL(12,2) NOT NULL` — available cash after all executed transactions
- `initial_capital DECIMAL(12,2) NOT NULL` — starting capital, never mutated (10000.00)
- `updated_at DATETIME NOT NULL`
- Seed: `INSERT INTO portfolio_state (cash, initial_capital, updated_at) VALUES (10000.00, 10000.00, NOW())`

`portfolio_holdings` — one row per held ticker:
- `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `ticker VARCHAR(20) NOT NULL`
- `quantity INT UNSIGNED NOT NULL DEFAULT 0` — whole shares only
- `avg_entry_price DECIMAL(12,4) NOT NULL` — weighted average across all BUYs for this ticker
- `updated_at DATETIME NOT NULL`
- `UNIQUE KEY uq_ticker (ticker)` — enables safe UPSERT on BUY accumulation

`portfolio_transactions` — immutable audit log, one row per decision per cycle:
- `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `cycle_id INT UNSIGNED NOT NULL` — FK to `rebalance_cycle.id`
- `ticker VARCHAR(20) NOT NULL`
- `action VARCHAR(20) NOT NULL` — `BUY | SELL | HOLD | NO_ACTION | SKIP_INSUFFICIENT_CASH`
- `quantity INT UNSIGNED NULL` — NULL for HOLD/NO_ACTION/SKIP
- `price_usd DECIMAL(12,4) NULL` — NULL for HOLD/NO_ACTION/SKIP
- `cash_before DECIMAL(12,2) NULL` — cash immediately before this transaction
- `cash_after DECIMAL(12,2) NULL` — cash immediately after this transaction
- `status VARCHAR(32) NOT NULL` — `executed | skipped_insufficient_cash | no_action | hold`
- `reason TEXT NULL` — populated by F-03 with LLM reasoning; F-02 inserts NULL
- `executed_at DATETIME NOT NULL`
- `CONSTRAINT fk_tx_cycle FOREIGN KEY (cycle_id) REFERENCES rebalance_cycle(id)`
- `INDEX idx_cycle_id (cycle_id)` — fast lookup by cycle

### Success Criteria

#### Automated Verification

- `mysql cvs_db < database/migrations/025_extend_rebalance_cycle.sql` applies cleanly; `DESCRIBE rebalance_cycle` shows all 6 new columns
- `mysql cvs_db < database/migrations/026_create_portfolio_tables.sql` applies cleanly; all three tables exist
- `SELECT COUNT(*) FROM portfolio_state` returns 1; `SELECT cash FROM portfolio_state` returns 10000.00
- UNIQUE constraint on `portfolio_holdings.ticker` verified: duplicate INSERT errors with `Duplicate entry`
- FK constraint on `portfolio_transactions.cycle_id` verified: INSERT with non-existent cycle_id errors

#### Manual Verification

- `SHOW CREATE TABLE portfolio_transactions` confirms FK definition and all columns with correct types
- `SHOW CREATE TABLE portfolio_holdings` confirms UNIQUE key on ticker
- All column types match plan (DECIMAL not FLOAT, VARCHAR lengths, NULLability)

**Implementation Note:** After phase 1 verification passes, pause for manual schema review before proceeding.

---

## Phase 2: PortfolioRepository (Read Model)

### Overview

Implement the read-only repository that all UI views (S-01, S-03, S-05) and the stats panel will call. No side effects, no transactions, all methods return plain PHP arrays.

### Changes Required

#### 1. PortfolioRepository

**File:** `src/Portfolio/PortfolioRepository.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Pure read access to all portfolio tables. Follows the same structure as `ScreenerRepository` — constructor takes `PDO $db`, all methods are public, return `array` or `array[]`, prepared statements only.

**Contract:** Constructor `__construct(PDO $db)`. Public methods:

- `getCurrentState(): array` — SELECT the single `portfolio_state` row; throws `\RuntimeException` if no row found (means `ensureInitialized()` was never called). Returns: `['cash', 'initial_capital', 'updated_at']`.

- `getCurrentHoldings(): array[]` — SELECT from `portfolio_holdings` WHERE `quantity > 0` ORDER BY `ticker ASC`. Returns array of rows with `['ticker', 'quantity', 'avg_entry_price', 'updated_at']`.

- `getTransactionsByCycle(int $cycleId): array[]` — SELECT from `portfolio_transactions` WHERE `cycle_id = ?` ORDER BY `id ASC`. Returns full transaction rows.

- `getCycleHistory(): array[]` — SELECT from `rebalance_cycle` ORDER BY `cycle_date DESC`. No LIMIT (PRD FR-011: no history cap). Returns all cycle rows including the F-02 summary columns.

- `getLatestCycle(): ?array` — SELECT from `rebalance_cycle` ORDER BY `cycle_date DESC` LIMIT 1. Returns the most recent cycle row or null if none.

- `getCycleById(int $id): ?array` — SELECT `rebalance_cycle` by PK. Returns row or null.

### Success Criteria

#### Automated Verification

- `php -l src/Portfolio/PortfolioRepository.php` passes
- PHPStan level 6 passes: `composer stan`
- Unit test: `getCurrentState()` on a seeded DB returns `['cash' => 10000.00, 'initial_capital' => 10000.00, ...]`
- Unit test: `getCurrentHoldings()` on empty DB returns `[]`
- Unit test: `getCycleHistory()` returns rows in DESC order and has no LIMIT
- Unit test: `getCurrentState()` throws `RuntimeException` when `portfolio_state` is empty

#### Manual Verification

- SQL query `SELECT * FROM portfolio_state` executed by `getCurrentState()` confirmed via query log or debug echo

**Implementation Note:** Pause after phase 2 automated tests pass.

---

## Phase 3: PortfolioService + CycleRepository Extension (Write Model)

### Overview

Implement the atomic write model. `PortfolioService::executeCycle()` is the single entry point for all portfolio mutations during a rebalance — it wraps everything in one PDO transaction. Extend `CycleRepository` from F-01 with `updateCycleSummary()`.

### Changes Required

#### 1. CycleRepository extension

**File:** `src/Portfolio/CycleRepository.php` *(extend existing class from F-01)*

**Intent:** Add one new method that writes the F-02 summary columns at cycle end. The existing `updateStatus()` sets the lifecycle status; `updateCycleSummary()` sets the financial summary. Both may be called in the same cycle.

**Contract:** New public method:

- `updateCycleSummary(int $id, float $cashBefore, float $cashAfter, float $portfolioValueUsd, int $executedCount, int $skippedCount, ?string $notes): void` — UPDATE `rebalance_cycle` SET `cash_before`, `cash_after`, `portfolio_value_usd`, `executed_count`, `skipped_count`, `notes` WHERE `id = ?`. Called inside the open transaction in `PortfolioService::executeCycle()`.

#### 2. PortfolioService

**File:** `src/Portfolio/PortfolioService.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Own all mutations to portfolio tables and the cycle summary. The single public entry point for the rebalance engine (F-03 will call `executeCycle()`). Every mutation happens inside a single DB transaction to guarantee consistency between `portfolio_state`, `portfolio_holdings`, `portfolio_transactions`, and `rebalance_cycle`.

**Contract:** Constructor `__construct(PDO $db, CycleRepository $cycleRepo)`.

**Public methods:**

- `ensureInitialized(): void` — SELECT COUNT(*) from `portfolio_state`; throws `\RuntimeException('Portfolio not initialized')` if count ≠ 1. Called at service boot before any cycle.

- `executeCycle(int $cycleId, array $decisions): void` — Main orchestrator. `$decisions` is an array of `['ticker', 'action', 'quantity', 'price_usd', 'reason']` rows produced by the LLM engine (F-03 will build this). Flow:

  ```
  1. $db->beginTransaction()
  2. SELECT cash FROM portfolio_state (inside transaction)
  3. $cashBefore = current cash; $cashRunning = $cashBefore
  4. For each $decision:
     - BUY: if cash sufficient → executeBuyInternal(); else → recordSkipInternal()
     - SELL: executeSellInternal()
     - HOLD: recordHoldInternal()
     - NO_ACTION: recordNoActionInternal()
  5. Compute $portfolioValueUsd = $cashRunning + sum(quantity * avg_entry_price) over holdings
  6. $cycleRepo->updateCycleSummary(id, cashBefore, cashRunning, portfolioValueUsd, executedCount, skippedCount, $notes)
  7. $cycleRepo->updateStatus(id, 'completed')
  8. $db->commit()
  ```
  On any `\Throwable`: `$db->rollBack()`, rethrow.

**Private helpers (called only from within open transaction):**

- `executeBuyInternal(int $cycleId, string $ticker, int $quantity, float $priceUsd, ?string $reason, float &$cashRunning, int &$executedCount): void`
  - Cost = `round($quantity * $priceUsd, 2)`
  - UPDATE `portfolio_state SET cash = cash - cost`
  - UPSERT `portfolio_holdings`: INSERT … ON DUPLICATE KEY UPDATE `quantity = quantity + ?, avg_entry_price = weighted_avg`
  - INSERT `portfolio_transactions (…, status='executed', cash_before=before, cash_after=cashRunning-cost)`
  - Decrement `$cashRunning`, increment `$executedCount`

- `executeSellInternal(int $cycleId, string $ticker, int $quantity, float $priceUsd, ?string $reason, float &$cashRunning, int &$executedCount): void`
  - Proceeds = `round($quantity * $priceUsd, 2)`
  - UPDATE `portfolio_state SET cash = cash + proceeds`
  - UPDATE/DELETE `portfolio_holdings`: reduce quantity; DELETE row if quantity reaches 0
  - INSERT `portfolio_transactions (…, status='executed')`
  - Increment `$cashRunning`, increment `$executedCount`

- `recordSkipInternal(int $cycleId, string $ticker, ?string $reason, int &$skippedCount): void`
  - INSERT `portfolio_transactions (action='SKIP_INSUFFICIENT_CASH', status='skipped_insufficient_cash', quantity=NULL, price_usd=NULL, cash_before=current, cash_after=current)`
  - Increment `$skippedCount`

- `recordHoldInternal(int $cycleId, string $ticker, ?string $reason): void`
  - INSERT `portfolio_transactions (action='HOLD', status='hold', quantity=NULL, price_usd=NULL)`

- `recordNoActionInternal(int $cycleId, ?string $reason): void`
  - INSERT `portfolio_transactions (action='NO_ACTION', status='no_action', ticker='*', quantity=NULL, price_usd=NULL)`

### Success Criteria

#### Automated Verification

- `php -l src/Portfolio/PortfolioService.php` passes
- PHPStan level 6 passes: `composer stan`
- Unit test (with test DB): `executeCycle()` with one BUY decision:
  - `portfolio_state.cash` reduced by `quantity * price`
  - `portfolio_holdings` has one row with correct ticker and quantity
  - `portfolio_transactions` has one row with `status='executed'`
  - `rebalance_cycle` row has `status='completed'`, correct `cash_before`/`cash_after`
- Unit test: BUY with insufficient cash → `portfolio_transactions` row with `status='skipped_insufficient_cash'`, cash unchanged
- Unit test: exception during BUY → ROLLBACK verified (all tables unchanged after the throw)
- Unit test: two consecutive BUYs for same ticker → `portfolio_holdings.quantity` = sum, `avg_entry_price` = weighted average
- Unit test: full SELL of a holding → `portfolio_holdings` row deleted (or quantity = 0)
- `ensureInitialized()` throws when `portfolio_state` is empty

#### Manual Verification

- Run `executeCycle()` with a mixed array (1 BUY, 1 SELL, 1 HOLD, 1 SKIP) against the real DB; inspect all four tables for consistency
- Verify `portfolio_value_usd` in `rebalance_cycle` matches manual calculation
- Verify ROLLBACK: force an exception mid-cycle (e.g., by passing an invalid ticker); confirm no partial writes in any table

**Implementation Note:** Pause for full manual end-to-end verification before declaring F-02 done and opening F-03.

---

## Testing Strategy

### Unit Tests

- `tests/Portfolio/PortfolioRepositoryTest.php` — use test DB or SQLite; seed known state; assert all read methods return expected shapes
- `tests/Portfolio/PortfolioServiceTest.php` — use test DB; cover: BUY/SELL/HOLD/SKIP execution, ROLLBACK on exception, weighted avg_entry_price accumulation, cash floor (insufficient_cash guard)

### Integration Tests

- Manual: apply migrations 025 + 026 to a test DB; run `executeCycle()` with a real decision array; inspect tables

### Manual Testing Steps

1. Apply migrations; verify `SELECT * FROM portfolio_state` returns one row with `cash=10000.00`
2. Call `executeCycle()` with a synthetic BUY decision (AAPL, 10 shares, $150/share); verify `portfolio_state.cash = 8500.00`, `portfolio_holdings` has AAPL row, `portfolio_transactions` has executed row
3. Call `executeCycle()` again with SELL AAPL 10 shares at $160; verify `portfolio_state.cash = 10100.00`, `portfolio_holdings` AAPL deleted
4. Trigger insufficient_cash: set cash to $10, BUY 100 shares at $200; verify skip logged, cash unchanged
5. Force exception mid-cycle; verify all tables unchanged

## Migration Notes

- Migration 025 depends on `rebalance_cycle` existing (from F-01 migration 024) — apply in order
- Migration 026 seed INSERT runs once; re-running migration 026 on an already-migrated DB would insert a second row — document this in the migration comment and add an `INSERT IGNORE` or `INSERT … IF NOT EXISTS` guard
- F-03 will add its own ALTER TABLE to `rebalance_cycle` (llm_raw_response, llm_decision_json, retry_count) — migration numbering: 027+

## References

- F-01 plan (cycle table + CycleRepository): `context/changes/rebalance-scheduler-and-calendar/plan.md`
- Existing repository pattern: `src/Screener/ScreenerRepository.php`, `src/TrackRecord/CvsSnapshotRepository.php`
- PDO singleton: `src/Core/Database.php`
- PRD: `context/foundation/prd-virtual-portfolio.md` (FR-007, FR-008, FR-011, FR-017)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: DB Migrations

#### Automated

- [ ] 1.1 Migration 025 applies cleanly; all 6 new columns visible in DESCRIBE rebalance_cycle
- [ ] 1.2 Migration 026 applies cleanly; all three portfolio tables exist
- [ ] 1.3 portfolio_state seed row: COUNT=1, cash=10000.00
- [ ] 1.4 UNIQUE constraint on portfolio_holdings.ticker verified (duplicate INSERT errors)
- [ ] 1.5 FK constraint on portfolio_transactions.cycle_id verified

#### Manual

- [ ] 1.6 SHOW CREATE TABLE for all new tables — types, nullability, indexes match plan
- [ ] 1.7 Migration 026 seed INSERT is idempotent-safe (INSERT IGNORE or guard documented)

### Phase 2: PortfolioRepository (Read Model)

#### Automated

- [ ] 2.1 php -l src/Portfolio/PortfolioRepository.php passes
- [ ] 2.2 PHPStan level 6 passes for src/Portfolio/
- [ ] 2.3 getCurrentState() returns seeded row with correct values
- [ ] 2.4 getCurrentHoldings() returns empty array on fresh DB
- [ ] 2.5 getCycleHistory() returns rows in DESC order with no LIMIT
- [ ] 2.6 getCurrentState() throws RuntimeException when portfolio_state empty

#### Manual

- [ ] 2.7 Query log confirms prepared statement pattern (no string interpolation in SQL)

### Phase 3: PortfolioService + CycleRepository Extension (Write Model)

#### Automated

- [ ] 3.1 php -l src/Portfolio/PortfolioService.php passes
- [ ] 3.2 PHPStan level 6 passes
- [ ] 3.3 executeCycle() BUY: cash reduced, holding created, transaction logged, cycle updated
- [ ] 3.4 executeCycle() BUY with insufficient cash: skip logged, cash unchanged
- [ ] 3.5 executeCycle() exception mid-cycle: ROLLBACK verified (all tables unchanged)
- [ ] 3.6 Two BUYs same ticker: quantity summed, avg_entry_price is weighted average
- [ ] 3.7 Full SELL: portfolio_holdings row deleted or quantity=0
- [ ] 3.8 ensureInitialized() throws when portfolio_state empty

#### Manual

- [ ] 3.9 Mixed cycle (BUY + SELL + HOLD + SKIP): all four tables consistent after execution
- [ ] 3.10 portfolio_value_usd in rebalance_cycle matches manual calculation
- [ ] 3.11 Forced exception mid-cycle: no partial writes in any table
