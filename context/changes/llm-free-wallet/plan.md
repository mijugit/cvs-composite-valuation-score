# LLM_Free_Wallet Implementation Plan

## Overview

A second, fully autonomous LLM-driven paper portfolio, isolated from the existing
Portfolio module. Where the existing module (`CVS\Portfolio\*`, renamed "LLM Bazowy"
in navigation) constrains the model with fixed quantitative rules and a server-side
enforcer that overrides its decisions regardless of reasoning, LLM_Free_Wallet gives
the model genuine interpretive freedom — including the right to disagree with CVS
signals — and a persistent, self-authored "legenda" (investment thesis) it writes
each cycle and reads back on the next. The whole point is a controlled comparison:
does real LLM judgment + memory beat a deterministic algorithm, or does removing the
guardrails just produce worse (or equally unremarkable) results? Both outcomes are
useful.

## Current State Analysis

- **Existing Portfolio module** (`src/Portfolio/*`, tables `portfolio_state` /
  `portfolio_holdings` / `portfolio_transactions` / `rebalance_cycle`): a global
  singleton portfolio. `DecisionService::buildSystemPrompt()` encodes fixed
  thresholds (stop-loss/take-profit/position-weight/sector-weight) as prose;
  `DecisionEnforcer::apply()` then force-sells stop-loss breaches and trims BUY
  sizing to the same caps **regardless of what the model returned** — the model's
  stated reasoning has no effect on the outcome. `PortfolioService::executeCycle()`
  runs the whole mutation in one transaction and now (as of the 2026-08-06
  mark-to-market fix) values holdings at the day's snapshot price, not cost basis.
  `bin/portfolio-rebalance.php` is the cron entrypoint: market-calendar gate →
  `CycleRepository::claimForRun()` (idempotent per `cycle_date`, bounded retries) →
  gather screener candidates → call `DecisionService::generate()` → inject
  execution prices → `PortfolioService::executeCycle()`.
- **Lab module** (`src/Lab/*`, migration `029_create_lab_tables.sql`) is the direct
  precedent for "new portfolio variant gets its own module": fully separate
  `lab_*` tables, only ever reads `rebalance_cycle.portfolio_value_usd` read-only
  (no FK, no writes) for its comparison chart. Same isolation shape this plan uses.
- **Stage-2 "Recenzja krytyczna"** (`bin/generate_critical_review.php`,
  `AiCriticalReviewService`, table `ai_critical_reviews`) is the only place in the
  codebase that already wires Claude's `web_search_20260209` tool
  (`ClaudeClient`'s generic `tools` option). It runs as a **detached background
  process** (`exec($cmd . ' &')`) specifically because it's fired from
  `AiAnalysisController` — a synchronous **web request** with a tight NFR. That
  constraint does not apply here: `bin/portfolio-rebalance.php` is already a CLI
  cron process with no such budget (mirrors `bin/rescore.php`, which CLAUDE.md
  notes runs "no `max_execution_time` limit for a multi-ticker batch"). **This
  resolves PRD Open Question #2**: no detached-worker indirection is needed: the
  new cron script can simply take longer.
- **Freshness checks already exist and are reusable read-only**:
  `AiAnalysisRepository::isFresh($ticker, $days = 7)` and
  `AiCriticalReviewRepository::isFresh($ticker, $hours = 48)`. FR-003's "reuse
  existing analyses if fresh" needs zero new freshness logic.
- **Navigation** (`templates/layout.php`) already has a proven dropdown pattern —
  `.admin-menu` / `.admin-menu__dropdown` (CSS-only hover/focus-within, with an
  existing mobile-responsive override at ≤768px). FR-009's new "Portfele" menu
  reuses this exact component instead of inventing a new one.
- **Testing pattern**: `tests/Portfolio/DecisionServiceTest.php` has a
  `FakeTransport implements HttpTransport` that returns canned HTTP responses
  without hitting the network — the mechanism this plan reuses to test the new
  decision engine's prompt-building and response-parsing deterministically, even
  though the feature it drives is deliberately non-deterministic in production.

## Desired End State

A user visiting the app sees a new "Portfele" menu with two entries: "LLM Bazowy"
(today's `/portfolio`, unchanged) and "LLM Free" (new). The LLM Free page shows
current positions, total return, and a chronological list of the model's legend
entries — in plain language, not CVS jargon. Once a day, ~10 minutes before NYSE
close, a cron cycle feeds the model CVS signals, any fresh existing analyses (stage-1
divergence / stage-2 critical review) for the candidates, and its own last 10 legend
entries; the model returns trade decisions (executed exactly as returned, no
server-side override) plus a new legend entry, both persisted to a wallet-specific
set of tables that never touch the existing Portfolio module's data.

### Key Discoveries:
- `src/Portfolio/PortfolioService.php:269-278` — `computeHoldingsValue()` must be
  built mark-to-market from day one here (the bug just fixed in the baseline
  wallet: cost-basis valuation silently drifts from reality and breaks any
  historical comparison).
- `src/Portfolio/CycleRepository.php:74-116` — `claimForRun()` is the exact
  idempotency contract to mirror: `INSERT ... WHERE NOT EXISTS` fast path +
  bounded-retry `UPDATE ... WHERE status IN ('failed','llm_failed')` path.
- `src/Ai/ClaudeClient.php` already accepts an arbitrary `tools` array in its
  `$options` — no client changes needed to add web-search-tool calls elsewhere.
- `database/migrations/030_create_ai_critical_reviews.sql`'s own comment explains
  exactly why its background-worker pattern exists ("far beyond a synchronous PHP
  request on CF") — confirms this constraint is web-request-specific, not
  cron-specific.

## What We're NOT Doing

- Touching any file, table, or route belonging to the existing Portfolio module
  (`portfolio_state`/`portfolio_holdings`/`portfolio_transactions`/
  `rebalance_cycle`, `src/Portfolio/*`, `/portfolio`). It is renamed to "LLM
  Bazowy" in navigation only — zero logic or data changes.
- Any server-side override of the model's trade decisions (no `DecisionEnforcer`
  equivalent) — this is the whole point of the experiment, decided explicitly in
  PRD FR-004.
- Any admin pause/resume/override control for the new wallet (PRD Access Control
  Changes / Non-Goals).
- A generic "N portfolios, any ruleset" framework — this plan builds exactly one
  new, hardcoded wallet type (PRD Non-Goals).
- Increasing rebalance frequency beyond once/day (PRD Non-Goals).
- A model-driven web-search **tool** during the decision call itself — search (when
  used) is app-controlled and pre-fetched as static context, decided in questioning
  round 2 for predictable cost.
- Backfilling or otherwise adjusting the existing wallet's historical data for a
  "fairer" comparison (PRD FR-010 Socratic resolution — explicitly rejected).

## Implementation Approach

Build bottom-up, same order as the original Portfolio module's own phased history
(schema → read model → write model → decision engine → scheduler → UI), consolidated
into one migration instead of the original's incremental five, since there's no
"ship F-01 alone" milestone here — this is one self-contained module. Every new
class/table is named `LlmFree*` / `llm_free_*` and lives under `CVS\LlmFree\`,
mirroring `CVS\Portfolio\*` structurally so an implementer already familiar with the
existing module can navigate the new one by analogy.

## Critical Implementation Details

**"Freedom" removes risk caps, not physical constraints.** FR-004 says no
`DecisionEnforcer`-equivalent — that means no stop-loss/take-profit/position-weight/
sector-weight overrides. It does **not** mean removing the basic physical guards
`PortfolioService` already has for any paper portfolio: a BUY that costs more than
available cash still gets skipped (you cannot spend money that doesn't exist), and a
SELL still caps at the quantity actually held. Port those specific guards from
`handleBuy()`/`executeSellInternal()`; do not port the sizing-cap/stop-loss logic
from `DecisionEnforcer` itself.

**Mark-to-market from the first line of code.** `computeHoldingsValue()` for
`llm_free_cycle.portfolio_value_usd` must value holdings at the day's resolved price
(same price map already built for execution), never at `avg_entry_price`. This is
not a judgment call — it's a bug class already found and fixed once in the sibling
module; do not reintroduce it here.

**Legend is a column, not a table.** PRD Open Question #3 resolved by precedent: the
existing module already extends its cycle table with LLM-specific columns
(`llm_decision_json`, `llm_raw_response` — migration 027) rather than a side table.
`llm_free_cycle.legend TEXT NULL` follows the same shape — one legend entry is
always 1:1 with one cycle, so a join is pure overhead.

## Phase 1: Data Foundation

### Overview

New, fully isolated tables for the wallet's state/holdings/transactions/cycles, and
the read-model repositories over them. Nothing in this phase executes a trade or
calls Claude — it only creates the place trades and legend entries will later land.

### Changes Required:

#### 1. Migration

**File**: `database/migrations/035_create_llm_free_wallet_tables.sql`

**Intent**: Create the four tables backing the new wallet, consolidating what the
original Portfolio module built incrementally (migrations 024–028) into one file,
plus the legend/token-usage columns this module needs from day one that the
original only grew later (027).

**Contract**:
- `llm_free_state` — singleton row (`id`, `cash DECIMAL(12,2)`,
  `initial_capital DECIMAL(12,2)`, `updated_at`), seeded `10000.00`/`10000.00` via
  `INSERT IGNORE` — same starting capital as the baseline wallet; a different
  amount would break the comparison the whole experiment exists to make.
- `llm_free_holdings` — `id`, `ticker VARCHAR(20) UNIQUE`,
  `quantity INT UNSIGNED DEFAULT 0`, `avg_entry_price DECIMAL(12,4)`, `updated_at`.
- `llm_free_transactions` — `id`, `cycle_id INT UNSIGNED` (FK →
  `llm_free_cycle.id`), `ticker`, `action VARCHAR(20)`,
  `quantity INT UNSIGNED NULL`, `price_usd DECIMAL(12,4) NULL`,
  `cash_before`/`cash_after DECIMAL(12,2) NULL`, `status VARCHAR(32)`,
  `reason TEXT NULL`, `executed_at`.
- `llm_free_cycle` — `id`, `cycle_date DATE UNIQUE`, `status VARCHAR(32)`,
  `attempt_count TINYINT UNSIGNED DEFAULT 1`, `started_at`, `finished_at NULL`,
  `cash_before`/`cash_after`/`portfolio_value_usd DECIMAL(12,2) NULL`,
  `executed_count`/`skipped_count SMALLINT UNSIGNED DEFAULT 0`, `notes TEXT NULL`,
  `retry_count TINYINT UNSIGNED DEFAULT 0`, `llm_raw_response TEXT NULL`,
  `llm_failure_kind VARCHAR(32) NULL`, `llm_decision_json TEXT NULL`,
  `legend TEXT NULL`, `tokens_input`/`tokens_output INT UNSIGNED DEFAULT 0`.
- All four tables `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  matching every other migration in this repo.
- No foreign key from `llm_free_*` to any `portfolio_*`/`rebalance_cycle`/
  `ai_analyses`/`ai_critical_reviews` table — read access to those (Phase 3) is
  through their existing repository classes, never a join.

#### 2. Namespace scaffold

**File**: `src/LlmFree/` (new directory, PSR-4 `CVS\LlmFree\`)

**Intent**: Establish the new sub-namespace per CLAUDE.md's "New namespaces &
migrations" convention, mirroring `CVS\Portfolio\`'s directory shape.

**Contract**: No composer.json change needed — PSR-4 root `CVS\` → `src/` already
covers any new sub-namespace directory.

#### 3. Read-model repository

**File**: `src/LlmFree/LlmFreeRepository.php`

**Intent**: Pure SELECT methods, no side effects — the read model consumed by both
the write engine (Phase 2, needs current state/holdings) and the UI (Phase 5).

**Contract**: Mirrors `PortfolioRepository`'s method surface:
`getCurrentState(): array`, `getCurrentHoldings(): array`,
`getCurrentHoldingsWithPrice(string $liveModelVersion): array` (same
`LEFT JOIN cvs_snapshots` shape as `PortfolioRepository::
getCurrentHoldingsWithPrice()`), `getLatestCycle(): ?array`. New method not present
on the sibling: `getLegendHistory(int $limit): array` — returns the last `$limit`
cycles that have a non-null `legend`, newest first, `{cycle_date, legend}` shape
only (callers needing more already have `getLatestCycle()`/history pagination if
ever added later — out of scope here per Non-Goals).

#### 4. Cycle repository

**File**: `src/LlmFree/LlmFreeCycleRepository.php`

**Intent**: Owns `llm_free_cycle` row lifecycle — claiming, status transitions, and
the audit-column writes (LLM record + cycle summary + legend).

**Contract**: Mirrors `CycleRepository` exactly for `claimForRun(string $cycleDate,
int $maxAttempts): ?int`, `updateStatus(int $id, string $status): void`,
`updateCycleSummary(...)`. One additive method beyond the sibling:
`updateLlmRecord(int $id, int $retryCount, string $rawResponse, ?string
$failureKind, ?string $decisionJson, ?string $legend, int $tokensInput, int
$tokensOutput): void` — folds legend + token counters into the same audit write
instead of a second UPDATE.

### Success Criteria:

#### Automated Verification:
- Migration applies cleanly on a local/staging check: `php -l database/migrations/035_create_llm_free_wallet_tables.sql` is not meaningful for SQL — verify instead via a fresh test run once repository tests exist (Phase 1 has no PHP logic to unit test beyond the repositories, covered by Phase 1's own tests below)
- `vendor/bin/phpunit tests/LlmFree/LlmFreeRepositoryTest.php tests/LlmFree/LlmFreeCycleRepositoryTest.php` passes (SQLite-backed, same builder pattern as `PortfolioRepositoryTest`/`CycleRepositoryTest`)
- `composer stan` clean on `src/LlmFree/`

#### Manual Verification:
- Migration runs successfully against the production database via SSH (mirrors the project's established migration workflow) and `SHOW CREATE TABLE llm_free_cycle` confirms all columns
- Seed row exists in `llm_free_state` with cash = 10000.00

---

## Phase 2: Write Engine

### Overview

The transactional service that turns a list of decisions into actual holdings/cash
mutations — without any risk-cap enforcement layer, but with the same physical
sanity guards (can't overspend cash, can't oversell a position) and the
mark-to-market valuation the baseline wallet just learned it needed.

### Changes Required:

#### 1. Write-model service

**File**: `src/LlmFree/LlmFreeService.php`

**Intent**: Execute one cycle's decisions atomically, exactly as `PortfolioService::
executeCycle()` does, minus the `DecisionEnforcer` pass.

**Contract**: `executeCycle(int $cycleId, array $decisions, array $priceMap): void`
— same transaction-per-cycle shape (`beginTransaction()`/`commit()`/`rollBack()` on
any `Throwable`) and the same four decision-action handlers (BUY/SELL/HOLD/
NO_ACTION) as `PortfolioService`, with BUY/SELL keeping their existing
insufficient-cash / insufficient-quantity skip guards (see Critical Implementation
Details) but with **no trimming step between "decisions received" and "decisions
applied"** — whatever quantity a BUY/SELL decision carries is what executes,
capped only by cash/holdings actually available. `computeHoldingsValue(array
$priceMap): float` is copied from the already-fixed baseline version (mark-to-market
via `$priceMap[$ticker] ?? avg_entry_price` fallback), not the pre-fix cost-basis
version.

### Success Criteria:

#### Automated Verification:
- `vendor/bin/phpunit tests/LlmFree/LlmFreeServiceTest.php` passes — mirrors
  `PortfolioServiceTest.php`'s coverage (BUY reduces cash/creates holding,
  insufficient-cash skip, SELL increases cash/removes holding, partial SELL,
  weighted avg_entry_price on repeated BUYs, rollback on mid-cycle exception,
  mark-to-market valuation via price map, fallback to avg_entry_price for a
  ticker missing from the price map) plus one new case: a BUY sized well beyond
  any baseline-wallet cap executes in full (proves no enforcement layer exists)
- `composer stan` clean

#### Manual Verification:
- None required for this phase in isolation — exercised end-to-end once Phase 4 runs a real cycle

---

## Phase 3: Decision Engine

### Overview

The prompt, the context-gathering step (existing analyses + bounded fresh search),
and the response parser. This is where FR-002/003/005/006 actually live — the part
that makes this module philosophically different from its sibling, not just a
copy with a different table prefix.

### Changes Required:

#### 1. Context gatherer

**File**: `src/LlmFree/LlmFreeContextGatherer.php`

**Intent**: For each candidate ticker the decision prompt will consider, resolve
what additional context (beyond raw CVS numbers) is available: reuse an existing
stage-1/stage-2 analysis if fresh, otherwise optionally fetch a bounded number of
fresh web-search summaries.

**Contract**: `gather(array $candidateTickers): array<string, string>` (ticker →
context text, only for tickers that have *something* beyond CVS numbers — absence
from the map means "no extra context available", not an error). Internally: check
`AiAnalysisRepository::isFresh()` / `AiCriticalReviewRepository::isFresh()` per
ticker first (zero-cost — table reads only); for the subset still missing fresh
context, issue a capped number of Claude calls with the `web_search_20260209` tool
(same `tools` option `AiCriticalReviewService` already uses), reusing
`ClaudeClientFactory::fromConfig()` with a config profile analogous to
`config/ai.php['critical_review']`'s longer timeout. The cap (config-driven, e.g.
top 3 candidates by CVS Swing lacking fresh context) is what keeps the ~$0.50/cycle
guardrail mathematically bounded — see Phase 3 item 4.

#### 2. Prompt builder + orchestrator

**File**: `src/LlmFree/LlmFreeDecisionService.php`

**Intent**: Build the free-form system prompt and per-cycle data block, call
Claude once for the combined decisions+legend response, retry on transient
failure/parse error exactly like the sibling's two-attempt policy, write the audit
record.

**Contract**: `generate(int $cycleId, array $portfolioState, array $holdings, array
$screenerRows, array $legendHistory): array{ok: bool, decisions: array, legend:
?string, retryCount: int, rawResponse: string, failureKind: ?string}` — same
two-attempt retry shape as `DecisionService::generate()`. System prompt content
(no code snippet — this is prose, not a contract): states the model has full
discretion over CVS interpretation including disagreeing with its recommendation,
is not obligated to act in the portfolio's interest (per the Socratic resolution on
FR-002), must produce a legend entry every cycle even when the thesis is unchanged
(showing what fresh angle it considered, per FR-005's resolution), and must treat
its own last 10 legend entries as context to critically re-examine, not as
established fact (per FR-006's resolution). Data block includes: CVS candidate
table (same shape as `DecisionService::buildDataBlock()`'s), current holdings
+ live P&L, the last 10 legend entries from `LlmFreeRepository::
getLegendHistory(10)`, and any per-ticker context text from
`LlmFreeContextGatherer::gather()`.

#### 3. Response parser

**File**: `src/LlmFree/LlmFreeDecisionParser.php`

**Intent**: Validate and normalise the raw Claude response, which now wraps both
decisions and legend in one JSON object instead of `DecisionParser`'s bare array.

**Contract**: `parse(string $rawResponse): array{decisions: array, legend:
string}` — top-level shape `{"decisions": [...], "legend": "..."}`; the
`decisions` array reuses `DecisionParser`'s existing per-item validation rules
(same `VALID_ACTIONS`, same per-item resilience — one malformed decision doesn't
discard the batch) either by direct reuse (extract a shared per-item validator) or
by duplicating the same rules — implementer's call, but the validation *behavior*
must match exactly, including the "skip malformed item, log it, don't fail the
whole response" resilience. `legend` is required, non-empty, capped at a
config-driven max length (mirror `reason_max_chars` from `config/portfolio.php`).

#### 4. Config

**File**: `config/llm-free-wallet.php` (new)

**Intent**: Every tunable knob for this module in one place per FR-010's spirit —
mirrors `config/portfolio.php`'s shape for the pieces that carry over
(`initial_capital_usd`, `market`, `rebalance_window_minutes`, `llm`), adds the
knobs unique to this module.

**Contract**: New keys beyond the portfolio-config shape: `legend_context_count`
(int, default `10` — the N from questioning round 1), `context_search_cap` (int,
default `3` — max fresh-search sub-calls per cycle, the cost-bounding lever),
`legend_max_chars` (int). `llm.max_tokens` set deliberately higher than the
baseline wallet's to afford the legend text, but still a fixed ceiling — this
number, together with `context_search_cap`, is what keeps the ~$0.50/cycle
guardrail a mathematical property of the config rather than a runtime check.

### Success Criteria:

#### Automated Verification:
- `vendor/bin/phpunit tests/LlmFree/LlmFreeDecisionParserTest.php` passes — valid
  `{decisions, legend}` parses; missing `legend` key fails; malformed single
  decision is skipped not fatal; empty `decisions` array with `NO_ACTION` sentinel
  still requires a non-empty `legend`
- `vendor/bin/phpunit tests/LlmFree/LlmFreeDecisionServiceTest.php` passes — reuses
  the `FakeTransport` pattern from `DecisionServiceTest`: canned success response
  parses into decisions+legend, canned malformed response triggers the one retry,
  system prompt text contains the "not obligated to act in the portfolio's
  interest" and "critically re-examine" instructions (string-contains assertions,
  same style as any existing prompt-content test in this codebase), data block
  includes exactly the last `legend_context_count` entries when more exist
- `vendor/bin/phpunit tests/LlmFree/LlmFreeContextGathererTest.php` passes — ticker
  with fresh `ai_analyses` row uses it without a search call; ticker with neither
  fresh source and beyond the `context_search_cap` gets no context (not an error);
  search sub-calls capped at config value even with more missing-context
  candidates
- `composer stan` clean

#### Manual Verification:
- None required in isolation — exercised end-to-end once Phase 4 runs a real cycle

---

## Phase 4: Scheduler

### Overview

The cron entrypoint gluing Phases 1–3 together for one calendar day, isolated from
the baseline wallet's own cron slot.

### Changes Required:

#### 1. Cron entrypoint

**File**: `bin/llm-free-wallet-rebalance.php`

**Intent**: Same overall shape as `bin/portfolio-rebalance.php` (CLI guard, .env
load, `$_SESSION = []` workaround, market-calendar gate, claim, gather inputs, call
decision engine, execute, log) but targeting close instead of the baseline's
existing timing, and with no detached-worker step — see Critical Implementation
Details / Current State Analysis for why that's unnecessary here.

**Contract**: Gathers screener candidates the same way the sibling script does
(`ScreenerRepository::getFiltered()`, no filters), resolves the execution price map
from that day's `cvs_snapshots.price_at_snapshot`, calls
`LlmFreeContextGatherer::gather()` then `LlmFreeDecisionService::generate()`, and
on success calls `LlmFreeService::executeCycle()`. No `set_time_limit()` cap
imposed by the script itself (CLI default is unbounded on this host, per the
`bin/rescore.php` precedent) — the config's higher `max_tokens` and the Claude
client's own `timeout`/`total_timeout` are the only time budgets in play.

#### 2. Cron schedule

**File**: n/a (Cyber_Folks panel entry, documented in this script's own docblock
per project convention)

**Intent**: Two DST-offset entries (mirrors the baseline wallet's own two-entry
approach for the Europe/Warsaw vs America/New_York DST mismatch window), timed for
~10 minutes before NYSE close (15:50 ET) rather than the baseline's existing
timing — a different wall-clock slot, so the two crons never contend for the same
window even during the mismatch weeks.

### Success Criteria:

#### Automated Verification:
- `php -l bin/llm-free-wallet-rebalance.php`
- `composer stan` clean

#### Manual Verification:
- Script runs successfully via SSH CLI on the production host for a manually-picked
  date, seeds the wallet (first-ever run), and produces exactly one `llm_free_cycle`
  row with `status = 'completed'`, non-null `legend`, and at least the seed
  transactions recorded
- Re-running the same script the same day is a no-op (idempotency) — no duplicate
  `llm_free_cycle` row, no duplicate transactions
- Cron entries added in the Cyber_Folks panel, confirmed via the panel UI

---

## Phase 5: UI

### Overview

The read-only page and navigation entry point users interact with.

### Changes Required:

#### 1. Controller

**File**: `src/LlmFree/LlmFreeController.php`

**Intent**: Read-only view controller, mirrors `PortfolioController::index()`'s
live-repricing shape exactly (same `LivePriceProvider`/`resolveLivePrices()`
reuse — no duplicated pricing logic) plus the new legend history.

**Contract**: `index(Request $req): void` — `AuthController::requireAuth()` gate
(same as the baseline wallet, no additional bramka per PRD Access Control
Changes), fetches state/holdings via `LlmFreeRepository`, live-reprices holdings
the same way `PortfolioController::index()` does, fetches
`getLegendHistory($config['legend_context_count'] ?? 10)` — reuse the same config
count so what the model "remembers" matches what the user can see — and renders
`Response::view('llm-free', [...])`.

#### 2. Template

**File**: `templates/llm-free.php`

**Intent**: Minimal MVP surface per questioning round 2 — positions, total
return, chronological legend list. No chart, no vs-baseline overlay (that's a
possible later addition per PRD Non-Goals' explicit scope note, not required now).

**Contract**: Structurally mirrors the stat-tile + holdings-table blocks of
`templates/portfolio.php`, dropping the "recommended tickers not yet held"
section (not applicable — this wallet's whole premise is that it doesn't just
follow screener recommendations). Adds a new section: legend entries newest-first,
each showing its `cycle_date` and full text, styled as prose (not a data table) —
this is the "read a person's reasoning" surface the whole feature exists for, so
it should read like one.

#### 3. Route

**File**: `src/Core/routes.php`

**Intent**: Register the new page.

**Contract**: `$router->get('/llm-free', fn($req) => $llmFree->index($req));`
alongside the existing `/portfolio` registration.

#### 4. Navigation

**File**: `templates/layout.php`

**Intent**: Replace the flat `<a href="/portfolio">Portfel</a>` link with a
"Portfele" dropdown containing both wallets, reusing the existing admin-dropdown
component instead of building a new one.

**Contract**: New `<div class="admin-menu">`-shaped (but non-admin) dropdown —
same `__trigger`/`__dropdown`/`__caret` class structure already proven at
`templates/layout.php`'s existing admin menu, same CSS (no new stylesheet rules
needed, the classes are generic despite the `admin-` prefix — or, cleaner, extract
a `dropdown-menu` class family from the existing admin-specific one so the name
isn't misleading; implementer's call, behavior must match exactly including the
≤768px mobile fallback). Items: "LLM Bazowy" → `/portfolio`, "LLM Free" →
`/llm-free`, each with the same `aria-current` active-state logic the flat link
had.

### Success Criteria:

#### Automated Verification:
- `php -l templates/llm-free.php`
- `composer stan` clean
- `vendor/bin/phpunit` full suite green (no regressions in existing Portfolio/nav
  tests)

#### Manual Verification:
- `/llm-free` renders for a logged-in user: positions, total return, legend
  history all visible and correctly formatted
- "Portfele" dropdown appears in navigation, both links work, active-state
  highlighting correct on each page, mobile (≤768px) fallback behaves like the
  existing admin dropdown's
- Page requires login (redirects to `/login` when logged out), same as
  `/portfolio` today

---

## Testing Strategy

### Unit Tests:
- `LlmFreeRepositoryTest`, `LlmFreeCycleRepositoryTest` — SQLite builder pattern,
  mirror `PortfolioRepositoryTest`/`CycleRepositoryTest`
- `LlmFreeServiceTest` — mirror `PortfolioServiceTest`, plus the "no enforcement"
  case described in Phase 2
- `LlmFreeDecisionParserTest`, `LlmFreeDecisionServiceTest`,
  `LlmFreeContextGathererTest` — mirror `DecisionParserTest`/`DecisionServiceTest`'s
  `FakeTransport` approach; this is how a module built around a
  deliberately-non-deterministic core still gets deterministic test coverage —
  every test fixes the Claude response and asserts on the deterministic plumbing
  around it (prompt content, parsing, persistence, retry, idempotency), never on
  "did the model make a good call"

### Integration Tests:
- None beyond the existing per-class unit coverage — matches the established
  convention for the sibling Portfolio module (no dedicated integration-test layer
  in this codebase; `bin/*.php` entrypoints are verified manually per phase, not
  via automated integration tests)

### Manual Testing Steps:
1. Run `bin/llm-free-wallet-rebalance.php` once via SSH for the seed cycle;
   confirm `llm_free_state`/`llm_free_holdings`/`llm_free_transactions`/
   `llm_free_cycle` all populated correctly
2. Re-run the same script same-day; confirm no duplication (idempotency)
3. Visit `/llm-free` while logged in; confirm positions/return/legend render
4. Visit while logged out; confirm redirect to `/login`
5. Click through the new "Portfele" nav dropdown on desktop and at ≤768px width

## Performance Considerations

The decision-engine call (Phase 3) is the only meaningfully slow step — bounded by
`context_search_cap` (search sub-calls) and `llm.max_tokens`/`timeout`/
`total_timeout` (main call), all config-driven. No page in Phase 5 does any
Claude/network call synchronously — `/llm-free` only reads already-persisted data
plus a live price re-fetch identical in cost to `/portfolio`'s existing one.

## Migration Notes

Purely additive — one new migration (`035_create_llm_free_wallet_tables.sql`), zero
`ALTER` statements on any existing table. No data migration or backfill (PRD
Non-Goals: the baseline wallet's history is explicitly left untouched).

## References

- PRD: `context/foundation/prd.md` ("CVS — LLM_Free_Wallet")
- Shape notes: `context/foundation/shape-notes.md`
- Sibling module: `src/Portfolio/*`, `database/migrations/024_create_rebalance_cycle.sql`
  through `028_add_attempt_count_to_rebalance_cycle.sql`, `026_create_portfolio_tables.sql`
- Isolation precedent: `database/migrations/029_create_lab_tables.sql`, `src/Lab/*`
- Web-search tool precedent: `src/Ai/AiCriticalReviewService.php`,
  `bin/generate_critical_review.php`, `database/migrations/030_create_ai_critical_reviews.sql`
- Freshness-check precedent: `src/Ai/AiAnalysisRepository.php::isFresh()`,
  `src/Ai/AiCriticalReviewRepository.php::isFresh()`
- Recent mark-to-market fix (do not regress it here too):
  `src/Portfolio/PortfolioService.php::computeHoldingsValue()`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Data Foundation

#### Automated
- [x] 1.1 `vendor/bin/phpunit tests/LlmFree/LlmFreeRepositoryTest.php tests/LlmFree/LlmFreeCycleRepositoryTest.php` passes — 8673750
- [x] 1.2 `composer stan` clean on `src/LlmFree/` — 8673750

#### Manual
- [x] 1.3 Migration runs successfully against production DB; `SHOW CREATE TABLE llm_free_cycle` confirms columns — 8673750
- [x] 1.4 Seed row exists in `llm_free_state` with cash = 10000.00 — 8673750

### Phase 2: Write Engine

#### Automated
- [x] 2.1 `vendor/bin/phpunit tests/LlmFree/LlmFreeServiceTest.php` passes — 1c1fe93
- [x] 2.2 `composer stan` clean — 1c1fe93

### Phase 3: Decision Engine

#### Automated
- [x] 3.1 `vendor/bin/phpunit tests/LlmFree/LlmFreeDecisionParserTest.php` passes — 88a318b
- [x] 3.2 `vendor/bin/phpunit tests/LlmFree/LlmFreeDecisionServiceTest.php` passes — 88a318b
- [x] 3.3 `vendor/bin/phpunit tests/LlmFree/LlmFreeContextGathererTest.php` passes — 88a318b
- [x] 3.4 `composer stan` clean — 88a318b

### Phase 4: Scheduler

#### Automated
- [x] 4.1 `php -l bin/llm-free-wallet-rebalance.php`
- [x] 4.2 `composer stan` clean

#### Manual
- [x] 4.3 Manual SSH CLI run seeds the wallet; one `llm_free_cycle` row, `status='completed'`, non-null `legend` — confirmed live 2026-08-10 22:39 (cycle id=2, 4 BUYs executed, legend written)
- [ ] 4.4 Re-run same day is a no-op (idempotency)
- [ ] 4.5 Cron entries added in Cyber_Folks panel

### Phase 5: UI

#### Automated
- [x] 5.1 `php -l templates/llm-free.php`
- [x] 5.2 `composer stan` clean
- [x] 5.3 Full `vendor/bin/phpunit` suite green

#### Manual
- [ ] 5.4 `/llm-free` renders positions/return/legend correctly
- [ ] 5.5 "Portfele" dropdown works on desktop and ≤768px, active-state correct
- [ ] 5.6 `/llm-free` redirects to `/login` when logged out
