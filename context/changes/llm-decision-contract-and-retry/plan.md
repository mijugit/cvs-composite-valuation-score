# F-03: LLM Decision Contract and Retry Safety — Implementation Plan

## Overview

Deliver the strict LLM decision contract that turns portfolio state + screener signals into validated BUY/SELL/HOLD/NO_ACTION instructions — with a guaranteed two-attempt retry policy, full audit trail, and deterministic failed-cycle semantics. After F-03, the bin/portfolio-rebalance.php engine stub is replaced with a real call chain, and the system can run an autonomous rebalance cycle end-to-end.

## Current State Analysis

From F-01 + F-02:
- `rebalance_cycle` table has status, started_at, finished_at, cash_before/after, executed_count, skipped_count, notes, portfolio_value_usd — **no LLM columns yet**
- `bin/portfolio-rebalance.php` has a `// F-03: wire RebalanceEngine::run() here` stub
- `PortfolioService::executeCycle(int $cycleId, array $decisions)` is ready to receive the parsed decision array
- `CycleRepository::updateStatus()` and `updateCycleSummary()` are implemented

From AI module research:
- `ClaudeClient::sendMessage(array $messages, ?CacheableSystem $system, array $options): AiResult` — never throws, handles transport-level retries internally
- Default `max_retries=2` in `config/ai.php` — F-03 overrides this to `0` for portfolio calls (service-level retry owns the policy)
- `AiDivergenceService::generate()` is the canonical pattern: build `CacheableSystem`, build data string, call `sendMessage()`, return `AiResult`
- `AiResult->ok`, `AiResult->text`, `AiResult->failureKind` are the fields F-03 uses
- `ClaudeClientFactory` creates clients from config arrays

Existing screener:
- `ScreenerRepository::findAllLatest()` returns all tickers with latest snapshot — includes CVS scores, recommendations, golden_signal, sector, price, ATR zone

## Desired End State

After F-03:
- `rebalance_cycle` has four LLM audit columns: `retry_count`, `llm_raw_response`, `llm_failure_kind`, `llm_decision_json`
- `DecisionParser` validates any LLM JSON string and returns a typed result (valid decisions array or parse error)
- `DecisionService` builds the full portfolio prompt, calls ClaudeClient with exactly 0 internal retries, makes up to 2 service-level attempts, and returns a typed result
- `bin/portfolio-rebalance.php` stub is replaced: valid decisions flow to `PortfolioService::executeCycle()`; both LLM failures mark cycle as `llm_failed` with full audit record and no portfolio changes
- PHPStan level 6 passes for all new Portfolio classes

### Key Discoveries

- `ClaudeClientFactory::create(array $config)` or direct `new ClaudeClient($config, $transport)` — portfolio creates its own ClaudeClient instance with `max_retries=0` merged from `config/portfolio.php['llm']` over `config/ai.php`
- `ScreenerRepository::findAllLatest()` already exists and returns the full screener population — no new data fetching needed for the LLM input
- The LLM JSON schema must include `NO_ACTION` as a single object (not per-ticker) when the model decides nothing should change — this needs explicit prompt instruction since it differs from BUY/SELL/HOLD which are per-ticker
- Retry delay between two service-level attempts: 2 seconds flat (not exponential) — the window is tight (30 min before market close) and exponential would eat into execution time

## What We're NOT Doing

- Screener signal filtering / top-N selection — user decided: all tickers from `findAllLatest()`
- Price re-fetching at call time for all screener tickers — screener uses `price_at_snapshot` already present in the snapshot row
- Real-time price fetch for the LLM input (only at execution time per FR-004, handled by PortfolioService BUY/SELL helpers)
- Structured output / tool use / function calling — clean JSON prompt is sufficient and avoids API feature dependency
- Changing the ClaudeClient's internal retry logic — we only pass `max_retries=0` via config override

## Implementation Approach

Four-layer build: schema → parser → service → wiring.

**Schema first** (migration 027): extend `rebalance_cycle` with the four LLM audit columns. These columns are always written, even on success.

**Parser second** (`DecisionParser`): isolated, stateless, unit-testable. Takes a raw string and either returns a validated `array[]` of decisions or throws `\InvalidArgumentException` with a reason. No I/O.

**Service third** (`DecisionService`): builds the prompt, instantiates a ClaudeClient with portfolio-specific config (max_retries=0), runs 2 attempts, delegates parsing to DecisionParser, and writes the LLM audit record. Returns a value-object-like array: `['ok' => bool, 'decisions' => array[], 'raw' => string, 'failure_kind' => string|null, 'retry_count' => int]`.

**Wiring last** (bin/portfolio-rebalance.php): replaces the stub. Three paths:
1. LLM success → `PortfolioService::executeCycle()` → `completed`
2. LLM failed (both attempts) → audit written → `CycleRepository::updateStatus('llm_failed')` → no portfolio changes → exit(1)
3. `executeCycle()` throws → ROLLBACK → `updateStatus('failed')` → exit(1)

## Critical Implementation Details

- **`NO_ACTION` JSON representation.** The LLM prompt must instruct: when the model decides no trades are needed, return a single-element array `[{"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"..."}]` — not an empty array. An empty array is ambiguous (parse failure vs deliberate no-op). `DecisionParser` must accept this as a valid decision and `PortfolioService` must handle it (via the existing `recordNoActionInternal()`).
- **LLM audit write before portfolio execution.** `CycleRepository::updateLlmRecord()` must be called and committed **before** `PortfolioService::executeCycle()` opens its DB transaction. This guarantees the LLM audit is persisted even if `executeCycle()` rolls back.
- **ClaudeClient config merge order.** Start from `config/ai.php`, then override with `config/portfolio.php['llm']`. Never merge the other way — `ai.php` has `max_retries=2`; `portfolio.php` overrides to `0`. The merged config is passed to `new ClaudeClient($mergedConfig, new CurlTransport())`.

---

## Phase 1: Migration 027 — LLM Audit Columns

### Overview

Add the four LLM audit columns to `rebalance_cycle`. These columns are written by `CycleRepository::updateLlmRecord()` after every LLM attempt sequence (success or failure).

### Changes Required

#### 1. Extend rebalance_cycle with LLM columns

**File:** `database/migrations/027_extend_rebalance_cycle_llm.sql`

**Intent:** Add the four audit columns that record what the LLM did, how many attempts were made, and the raw artefacts for debugging and calibration. F-02 left these out deliberately to keep the contracts separate.

**Contract:**

```sql
ALTER TABLE rebalance_cycle
    ADD COLUMN retry_count       TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN llm_raw_response  TEXT             NULL               AFTER retry_count,
    ADD COLUMN llm_failure_kind  VARCHAR(32)      NULL               AFTER llm_raw_response,
    ADD COLUMN llm_decision_json TEXT             NULL               AFTER llm_failure_kind;
```

- `retry_count`: 0 = only one attempt made, 1 = retry was triggered
- `llm_raw_response`: full text from last LLM attempt (or error string on failure)
- `llm_failure_kind`: maps to `AiFailureKind::value` ('timeout', 'rate_limited', …) or 'parse_error' when ClaudeClient succeeded but JSON was invalid
- `llm_decision_json`: the validated JSON string as returned by LLM (only on success; NULL on failure)

### Success Criteria

#### Automated Verification

- `mysql cvs_db < database/migrations/027_extend_rebalance_cycle_llm.sql` applies cleanly
- `DESCRIBE rebalance_cycle` shows all four new columns with correct types and NULLability

#### Manual Verification

- `llm_raw_response` is TEXT (not VARCHAR) — can hold full LLM response for 100+ tickers

**Implementation Note:** Pause after migration verification before proceeding to Phase 2.

---

## Phase 2: DecisionParser (JSON Schema Validation)

### Overview

Isolated, stateless class that takes the raw LLM response string and either returns a validated decision array or throws. No I/O, no DB, no external calls — fully unit-testable.

### Changes Required

#### 1. DecisionParser

**File:** `src/Portfolio/DecisionParser.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Own the JSON contract enforcement. Separating parsing from the LLM call makes both independently testable and keeps the schema definition in one place.

**Contract:** Single public method:

- `parse(string $rawResponse): array` — parses and validates the LLM JSON string. Throws `\InvalidArgumentException` with a descriptive message on any failure. On success, returns a plain array where each element is a validated decision row.

Validation rules:
- `json_decode()` must succeed and return an array
- Array must be non-empty (at least one element)
- Each element must have: `action` (string, must be one of `BUY|SELL|HOLD|NO_ACTION`), `ticker` (string or null), `quantity` (positive int or null), `reason` (string, max 500 chars enforced by truncation)
- `ticker` must be non-null and non-empty for `BUY`, `SELL`, `HOLD`; may be null for `NO_ACTION`
- `quantity` must be a positive integer for `BUY` and `SELL`; must be null for `HOLD` and `NO_ACTION`
- Duplicate tickers in one response are collapsed: last entry wins (log the collision)
- Returns the validated and normalised array (reason truncated to 500 chars, ticker uppercased)

### Success Criteria

#### Automated Verification

- `php -l src/Portfolio/DecisionParser.php` passes
- PHPStan level 6 passes
- Unit tests:
  - Valid BUY JSON → returns array with one BUY element
  - Valid NO_ACTION single-element array → accepted
  - Empty JSON array `[]` → throws `InvalidArgumentException`
  - Missing `action` field → throws
  - Unknown action value `"MAYBE"` → throws
  - BUY with null quantity → throws
  - HOLD with non-null quantity → throws (quantity must be null)
  - Duplicate tickers (AAPL appears twice) → last entry wins, no exception
  - reason > 500 chars → truncated silently, no exception
  - Non-JSON string → throws

#### Manual Verification

- Parse a real LLM JSON response from a test call; confirm all fields normalised correctly

**Implementation Note:** Pause after all parser unit tests pass before Phase 3.

---

## Phase 3: DecisionService + CycleRepository Extension

### Overview

`DecisionService` builds the full LLM prompt (system + data block), runs exactly two service-level attempts with ClaudeClient configured for zero internal retries, and writes the LLM audit record. `CycleRepository` gets a new `updateLlmRecord()` method.

### Changes Required

#### 1. CycleRepository extension

**File:** `src/Portfolio/CycleRepository.php` *(extend existing class from F-01 and F-02)*

**Intent:** Add the method that persists the LLM audit fields. Called after every `DecisionService` attempt sequence, regardless of outcome.

**Contract:** New public method:

- `updateLlmRecord(int $cycleId, int $retryCount, string $rawResponse, ?string $failureKind, ?string $decisionJson): void` — UPDATE `rebalance_cycle` SET `retry_count`, `llm_raw_response`, `llm_failure_kind`, `llm_decision_json` WHERE `id = ?`. Always called outside the portfolio DB transaction (see Critical Details).

#### 2. Portfolio LLM config additions

**File:** `config/portfolio.php` *(extend existing from F-01)*

**Intent:** Add a `llm` sub-array that overrides `config/ai.php` specifically for portfolio rebalance calls — in particular setting `max_retries=0` and `max_tokens=2048`.

**Contract:** New key `llm` in the returned array:

```php
'llm' => [
    'max_retries'           => 0,        // service-level retry owns the policy
    'max_tokens'            => 2048,
    'timeout'               => 20,       // per-attempt seconds (same as ai.php default)
    'total_timeout'         => 25,
    'retry_base_delay_ms'   => 0,        // irrelevant at max_retries=0, kept for completeness
    'retry_delay_seconds'   => 2,        // flat delay between service-level attempts
    'max_candidates'        => null,     // null = all screener tickers (future: set to N to cap)
    'reason_max_chars'      => 500,      // max reason length enforced by DecisionParser
    'system_prompt_ttl'     => '5m',     // CacheableSystem TTL
],
```

#### 3. DecisionService

**File:** `src/Portfolio/DecisionService.php`

**Namespace:** `CVS\Portfolio`

**Intent:** Own the entire LLM decision pipeline: data assembly → prompt → call → retry → parse → audit. `bin/portfolio-rebalance.php` calls this once per cycle and receives a self-describing result array.

**Contract:** Constructor takes `PDO $db`, `CycleRepository $cycleRepo`, `array $aiConfig` (merged `config/ai.php` + `config/portfolio.php['llm']`), `array $portfolioConfig`.

Public method:

- `generate(int $cycleId, array $portfolioState, array $holdings, array $screenerRows): array`

  Returns: `['ok' => bool, 'decisions' => array[], 'retryCount' => int, 'rawResponse' => string, 'failureKind' => string|null]`

  Flow:
  ```
  1. Build $system = new CacheableSystem($this->buildSystemPrompt(), TTL_5M)
  2. Build $userMessage = $this->buildDataBlock($portfolioState, $holdings, $screenerRows)
  3. Create ClaudeClient with merged $aiConfig (max_retries=0)
  4. $attempt = 0; $lastResult = null; $lastRaw = ''
  5. LOOP (max 2 iterations):
     a. $result = $client->sendMessage([['role'=>'user','content'=>$userMessage]], $system)
     b. $lastRaw = $result->text ?? $result->failureKind ?? 'unknown'
     c. If $result->ok:
        try { $decisions = (new DecisionParser())->parse($result->text); SUCCESS → break }
        catch (InvalidArgumentException $e) { $failureKind = 'parse_error'; $lastRaw = $result->text; }
     d. Else: $failureKind = $result->failureKind->value ?? 'unknown'
     e. If $attempt === 0: sleep($retryDelaySecs); $attempt++; continue
     f. ELSE: break (second failure → exit loop)
  6. Write audit: $cycleRepo->updateLlmRecord($cycleId, $attempt, $lastRaw, $failureKind, $decisionJson)
  7. Return ['ok' => $ok, 'decisions' => $decisions, 'retryCount' => $attempt, ...]
  ```

Private methods:

- `buildSystemPrompt(): string` — Polish system prompt instructing the model to act as a portfolio manager, output ONLY valid JSON, include `NO_ACTION` as single-element array when no trades needed, keep each reason under 500 chars, respect cash constraint described in the user message. Stable text → cached by `CacheableSystem`.

- `buildDataBlock(array $portfolioState, array $holdings, array $screenerRows): string` — assembles the user message: portfolio cash, current holdings (ticker + quantity + avg_price), available cash, list of all screener tickers with CVS swing/fund scores, recommendation, golden_signal, sector, current price, with explicit cash constraint note. Format: structured text block (not JSON) so it's easy to read in logs.

### Success Criteria

#### Automated Verification

- `php -l src/Portfolio/DecisionService.php` passes
- PHPStan level 6 passes for all Portfolio classes
- Unit test: `generate()` with mocked ClaudeClient returning valid JSON on first call → `ok=true`, `retryCount=0`, decisions populated
- Unit test: first call returns `ok=false` (Timeout), second call returns valid JSON → `ok=true`, `retryCount=1`
- Unit test: first call valid JSON but parse fails, second call valid parseable JSON → `ok=true`, `retryCount=1`
- Unit test: both calls fail → `ok=false`, `retryCount=1`, `failureKind` set
- Unit test: `updateLlmRecord()` is called exactly once per `generate()` invocation regardless of outcome
- Unit test: sleep between attempts is called when `$attempt===0` and first call failed

#### Manual Verification

- Make a real call to Claude API (non-production, small screener dataset); confirm returned JSON matches the expected schema
- Verify `llm_raw_response` in DB is the full LLM text, not truncated
- Verify `retry_count=0` when first attempt succeeds, `retry_count=1` when retry was needed

**Implementation Note:** Pause for manual LLM call verification before Phase 4.

---

## Phase 4: Wiring — bin/portfolio-rebalance.php

### Overview

Replace the F-01 engine stub with the real call chain. Three exit paths: success (cycle completed), LLM failed (cycle marked llm_failed, no portfolio changes), execution failed (ROLLBACK, cycle marked failed).

### Changes Required

#### 1. Replace engine stub in CLI entry point

**File:** `bin/portfolio-rebalance.php` *(modify existing file from F-01)*

**Intent:** Complete the end-to-end cycle: fetch portfolio state and screener data, call `DecisionService`, branch on result, call `PortfolioService::executeCycle()` on success, or record `llm_failed` and exit without touching the portfolio.

**Contract:** The section immediately after the cycle-started log gains this flow (replacing the stub comment):

```
1. $config = require ROOT_PATH . '/config/portfolio.php'
   $aiConfig = require ROOT_PATH . '/config/ai.php'
   $mergedLlmConfig = array_merge($aiConfig, $config['llm'])

2. Instantiate services:
   $portfolioRepo = new CVS\Portfolio\PortfolioRepository($db)
   $portfolioService = new CVS\Portfolio\PortfolioService($db, $cycleRepo)
   $decisionService = new CVS\Portfolio\DecisionService($db, $cycleRepo, $mergedLlmConfig, $config)
   $screenerRepo = new CVS\Screener\ScreenerRepository($db)

3. Gather inputs:
   $portfolioState = $portfolioRepo->getCurrentState()
   $holdings = $portfolioRepo->getCurrentHoldings()
   $screenerRows = $screenerRepo->findAllLatest()

4. Call LLM:
   $result = $decisionService->generate($cycleId, $portfolioState, $holdings, $screenerRows)

5. Branch on result:
   If !$result['ok']:
     $log("cycle {$cycleDate} LLM FAILED after {$result['retryCount']} retry, kind={$result['failureKind']}")
     $cycleRepo->updateStatus($cycleId, 'llm_failed')
     exit(1)

6. Execute portfolio:
   try {
     $portfolioService->executeCycle($cycleId, $result['decisions'])
     $log("cycle {$cycleDate} completed, executed={$executedCount}")
   } catch (Throwable $e) {
     $log("cycle {$cycleDate} EXECUTION FAILED: " . $e->getMessage())
     $cycleRepo->updateStatus($cycleId, 'failed')
     exit(1)
   }

7. exit(0)
```

Note: `Database::reconnect()` should be called before any long query sequence (ScreenerRepository query on 100+ rows) — CF may drop idle connections during the LLM call duration.

### Success Criteria

#### Automated Verification

- `php -l bin/portfolio-rebalance.php` passes
- PHPStan level 6 passes
- Manual CLI run on Saturday: `market_closed` in log, no DB writes to portfolio tables

#### Manual Verification

- Run script during valid NYSE window; verify full cycle completes end-to-end: `rebalance_cycle` row has status=`completed`, `llm_decision_json` populated, `portfolio_state.cash` updated, `portfolio_transactions` has rows
- Simulate LLM failure (invalid API key in test env): `rebalance_cycle.status = llm_failed`, `llm_failure_kind = auth`, no changes to `portfolio_state` or `portfolio_holdings`
- Verify `retry_count=1` appears in DB row when first attempt failed and retry succeeded
- Verify `NO_ACTION` decision: cycle completes, no portfolio_transactions rows with status=`executed`
- Verify `Database::reconnect()` call does not cause errors after LLM call completes

**Implementation Note:** Final verification — this is the first fully autonomous cycle. Pause and review before declaring F-03 done.

---

## Testing Strategy

### Unit Tests

- `tests/Portfolio/DecisionParserTest.php` — covers all validation rules (valid BUY/SELL/HOLD/NO_ACTION, bad JSON, wrong types, duplicate tickers, reason truncation)
- `tests/Portfolio/DecisionServiceTest.php` — mocked ClaudeClient; covers 2-attempt paths (success-first, retry-success, double-fail, parse-error-then-success)

### Integration Tests

- Manual: real Claude API call with small screener dataset; inspect returned JSON and DB state

### Manual Testing Steps

1. Apply migration 027; verify `DESCRIBE rebalance_cycle` shows all 4 new columns
2. Run `php bin/portfolio-rebalance.php` on a valid trading day within window; confirm `rebalance_cycle` has a completed row with `llm_decision_json` populated and `portfolio_state.cash` changed
3. Run on Saturday; confirm `market_closed` in log, no DB writes
4. Set invalid ANTHROPIC_API_KEY; run in window; confirm `status=llm_failed`, `llm_failure_kind=auth`, portfolio unchanged
5. Force parse error by injecting malformed JSON stub in test env; confirm retry fires (`retry_count=1`), then second success: cycle completes

## Performance Considerations

- `ScreenerRepository::findAllLatest()` may return 100+ rows; each row adds ~100 tokens to the LLM prompt. At 100 tickers × ~100 tokens ≈ 10k input tokens. Within Claude's context window and the 2048 output token budget.
- The 2-second sleep between attempts adds at most 2s to the critical path — acceptable given the 30-min window.
- `Database::reconnect()` before heavy queries prevents CF connection drops during the ~20s LLM call.

## Migration Notes

- Migration 027 depends on 024 (F-01) and 025/026 (F-02) having been applied in order
- F-03 does NOT add any new tables — only extends `rebalance_cycle`
- S-01 (read-only view) and S-03 (history) can read the new columns immediately after migration 027 is applied

## References

- F-01 plan: `context/changes/rebalance-scheduler-and-calendar/plan.md`
- F-02 plan: `context/changes/virtual-portfolio-ledger/plan.md`
- Existing ClaudeClient pattern: `src/Ai/ClaudeClient.php` (sendMessage L40–84)
- AiDivergenceService prompt pattern: `src/Ai/AiDivergenceService.php` (generate L37–55, buildSystemPrompt L61–172)
- ScreenerRepository::findAllLatest(): `src/Screener/ScreenerRepository.php`
- PRD: `context/foundation/prd-virtual-portfolio.md` (FR-005, FR-009, Guardrails)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Migration 027 — LLM Audit Columns

#### Automated

- [ ] 1.1 Migration 027 applies cleanly
- [ ] 1.2 DESCRIBE rebalance_cycle shows all 4 new columns with correct types

#### Manual

- [ ] 1.3 llm_raw_response confirmed as TEXT (not VARCHAR)

### Phase 2: DecisionParser (JSON Schema Validation)

#### Automated

- [ ] 2.1 php -l src/Portfolio/DecisionParser.php passes
- [ ] 2.2 PHPStan level 6 passes for DecisionParser
- [ ] 2.3 Valid BUY JSON → returns correct array
- [ ] 2.4 Valid NO_ACTION single-element → accepted
- [ ] 2.5 Empty array [] → throws InvalidArgumentException
- [ ] 2.6 Missing action field → throws
- [ ] 2.7 Unknown action "MAYBE" → throws
- [ ] 2.8 BUY with null quantity → throws
- [ ] 2.9 HOLD with non-null quantity → throws
- [ ] 2.10 Duplicate tickers → last wins, no exception
- [ ] 2.11 reason > 500 chars → truncated silently
- [ ] 2.12 Non-JSON string → throws

#### Manual

- [ ] 2.13 Real LLM JSON response parsed; all fields normalised correctly

### Phase 3: DecisionService + CycleRepository Extension

#### Automated

- [ ] 3.1 php -l src/Portfolio/DecisionService.php passes
- [ ] 3.2 PHPStan level 6 passes for all Portfolio classes
- [ ] 3.3 generate() — success on first call: ok=true, retryCount=0
- [ ] 3.4 generate() — Timeout on first, success on second: ok=true, retryCount=1
- [ ] 3.5 generate() — parse error on first, parseable on second: ok=true, retryCount=1
- [ ] 3.6 generate() — both calls fail: ok=false, retryCount=1, failureKind set
- [ ] 3.7 updateLlmRecord() called exactly once per generate() regardless of outcome
- [ ] 3.8 sleep() between attempts fires when first attempt failed

#### Manual

- [ ] 3.9 Real Claude API call returns valid JSON matching expected schema
- [ ] 3.10 llm_raw_response in DB is full LLM text, not truncated
- [ ] 3.11 retry_count=0 on first-attempt success; retry_count=1 on retry

### Phase 4: Wiring — bin/portfolio-rebalance.php

#### Automated

- [ ] 4.1 php -l bin/portfolio-rebalance.php passes
- [ ] 4.2 PHPStan level 6 passes
- [ ] 4.3 Saturday run: market_closed in log, no portfolio DB writes

#### Manual

- [ ] 4.4 Valid window run: rebalance_cycle status=completed, llm_decision_json populated, portfolio_state.cash updated, portfolio_transactions rows present
- [ ] 4.5 Invalid API key run: status=llm_failed, llm_failure_kind=auth, portfolio unchanged
- [ ] 4.6 retry_count=1 in DB when retry was needed
- [ ] 4.7 NO_ACTION decision: cycle completed, no executed transactions
- [ ] 4.8 Database::reconnect() causes no errors after LLM call
