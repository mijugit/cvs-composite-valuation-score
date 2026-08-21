# Fundamentals Validation Implementation Plan

## Overview

Add an admin-only, per-ticker mechanism to detect and correct fundamental-data fields that Yahoo
Finance returns wrong-but-not-NULL (confirmed live: `days_since_earnings` off by ~37x,
`free_cash_flow` internally inconsistent with `operating_cash_flow`) or genuinely missing for
large, well-covered companies. Local, free rules flag suspect fields first; an admin-triggered
Gemini call (with web search) fills the gaps; the admin reviews a diff before anything is
persisted; confirmed values become durable overrides that take priority over the live Yahoo fetch
and trigger a rescore of that one ticker.

## Current State Analysis

- `FinancialDataFetcher::fetch()` (`src/Api/FinancialDataFetcher.php:140-199`) fetches ~50
  fundamental fields live from Yahoo on every call; nothing is ever persisted raw. The only
  quality check is `PayloadCompleteness::missingEssentialFields()` (`src/Api/PayloadCompleteness.php`),
  and it only checks `revenue`, only from `bin/rescore.php:176`.
- No single-ticker code path writes to `cvs_snapshots` today. `AnalysisController::show()` and
  `AiAnalysisController` compute a `CVSResult` for display only.
- The existing async-job pattern (`AiAnalysisController::criticalReview()`/`criticalReviewStatus()`,
  `AiCriticalReviewRepository`, `bin/generate_critical_review.php`) is a complete, working
  `markPending → exec(cmd.' &') → markCompleted/markFailed → poll` reference — see
  `context/changes/fundamentals-validation/research.md` §1 for the full trace.
- `src/Ai/GeminiClient.php` is a generic, reusable, typed transport client (mirrors `ClaudeClient`)
  already supporting `googleSearch` grounding via `options['tools']`, exercised today by
  `src/LlmGemini/LlmGeminiContextGatherer.php`. It does **not** support a structured
  `responseSchema` — `buildBody()` only emits `contents`/`generationConfig.maxOutputTokens`/
  `systemInstruction`/`tools` (`src/Ai/GeminiClient.php:107-133`). We rely on prompt-instructed
  JSON in the response text, not an enforced schema — already proven reliable in the manual
  3-provider experiment that grounded this feature (Gemini/Perplexity/GPT all returned clean,
  parseable JSON when explicitly asked).
- `FinancialDataFetcher::fetchDailyOhlc(string $ticker, string $range): array` is already
  **public** (`src/Api/FinancialDataFetcher.php:382`), but the default `fetch()` call only
  requests `'3mo'` (`src/Api/FinancialDataFetcher.php:180`) — about 63 daily bars, insufficient
  for a real 200-trading-day average. `moving_average_200` (`src/Api/FinancialDataFetcher.php:1063`)
  is consequently NULL whenever Yahoo's own `twoHundredDayAverage` is absent, with no local
  fallback despite the daily-close data being fetchable.
- The "Dane źródłowe (surowe)" section (`templates/analysis.php:748-797`) skips any field whose
  value is `null` (`:784`, `if ($val === null) continue;`) — so the exact fields we most need to
  flag (currently-NULL ones) don't render at all today. Its `$rawFields` list also omits
  `gross_profit`, `total_equity`, `current_assets`, `current_liabilities`, and `ps_ratio` — all
  PRD-relevant fields.
- Admin gating has no shared helper; two existing patterns both re-read `is_admin` fresh from
  `UserRepository` rather than trusting `$_SESSION['is_admin']` (`src/Admin/TickersController.php:321-329`,
  `src/Links/TickerLinkController.php:29-32`). `templates/analysis.php` has no `is_admin` check
  today and `AnalysisController::show()` doesn't pass one into the view.
- `bin/rescore.php:161-253` is the canonical per-ticker pipeline: fetch → `PayloadCompleteness`
  gate → peer-bucket-override merge → `CVSModel::calculate()` → `FairPriceCalculator::compute()`
  → `SnapshotWriter::persist()` → `AtrZoneCalculator::compute()` + `PriceAlertRepository::upsertZone()`
  → `AlertService::checkAndNotify()` (queued) → `AlertService::flushDigests()` (sent once at the
  end of the run). No single-ticker equivalent exists; this plan builds one, replicating every
  step (full parity, per user decision).

## Desired End State

An admin viewing any ticker's analysis page sees the "Dane źródłowe (surowe)" section with every
field that's expected-but-missing, or that fails a local consistency/cadence check, highlighted
red. Two buttons ("Sprawdź wszystkie dane", "Sprawdź dane brakujące") are visible only to admins.
Clicking either kicks off a background job; the admin sees a live status indicator, then an
inline diff (old vs proposed new value) once ready; clicking "Zastosuj" persists the accepted
values as overrides (permanently prioritized over Yahoo until the next manual trigger for that
field), triggers a full rescore of that ticker (score + snapshot + ATR zone + alert digest), and
the row colors flip to green (or gray, for fields Gemini genuinely couldn't find). The daily batch
(`bin/rescore.php`) is unmodified and picks up the override automatically on its next run.

### Key Discoveries:

- `bin/rescore.php:204-207` — the exact insertion point/pattern for merging an admin override
  into `$financials` before scoring (peer-bucket-override precedent); the new fundamental-override
  merge follows this identically.
- `src/LlmGemini/LlmGeminiContextGatherer.php:65-82` — the exact call shape for a `googleSearch`-
  grounded Gemini call to copy for the validation service.
- `src/TrackRecord/SnapshotWriter.php:48-99` — must be used (not `CvsSnapshotRepository::save()`
  directly) so shadow-`model_version` fan-out and the never-NULL-`model_version` invariant
  (`context/foundation/lessons.md` "NULL w kolumnie UNIQUE...") stay correct automatically.
- `templates/analysis.php:782-792` — the `continue`-on-null loop that must change so NULL fields
  render (and can be colored), without breaking existing non-flagged NULL fields (e.g.
  `trailing_pe` on negative EPS) from rendering as false-positive "suspect".

## What We're NOT Doing

- No automatic/scheduled validation across the whole ticker universe — this is a manual,
  per-ticker admin action only (PRD Non-Goal).
- No expiry/TTL on applied overrides — they last until the next manual trigger for that field
  (PRD Non-Goal, tracked as a future Open Question in the PRD).
- No support for LLM providers other than Gemini in this UI/flow (PRD Non-Goal).
- No validation of fields that don't feed `CVSModel` scoring (e.g. `beta`, `employees`, `website`)
  — `FundamentalFieldRegistry::SCORING_FIELDS` is a fixed, curated whitelist.
- No widening of the *default* `FinancialDataFetcher::fetch()` OHLC range — the wider `'1y'`
  daily-OHLC fetch for MA200 happens only inside the validation worker, never on the hot path.
- No integration test hitting the real Gemini API — unit tests only, with `FakeTransport`,
  matching every existing AI-client test in this codebase.
- No selective per-field approval UI within a single confirm action — the admin reviews the whole
  diff and applies it in one action; a field Gemini couldn't answer simply isn't in the diff.

## Implementation Approach

Every new component is a direct copy of an existing, working pattern in this codebase (see
Key Discoveries above and `research.md`), composed for this specific feature rather than
generalized prematurely. Two new tables separate **proposed** state (`fundamental_validation_runs`
— one pending/completed/failed job per ticker, holding the not-yet-applied diff) from **applied**
state (`fundamental_overrides` — one row per ticker+field, actually merged into scoring). This
mirrors the existing `ai_critical_reviews` (job status) vs. `cvs_snapshots` (applied result)
separation already in the codebase. A single `FundamentalFieldRegistry` is the one source of
truth for which fields are in scope, their types, and which are "expected non-null" — referenced
by the detector, the merger, and the prompt-builder, so the whitelist can never drift between them
(per `lessons.md` "Dwie implementacje jednej reguły zawsze się rozjadą").

## Critical Implementation Details

**Earnings-timing fields are asked as dates, converted to day-counts locally.** Do not ask Gemini
for `days_since_earnings`/`days_to_earnings` as integers — it would have to silently assume
"today". Instead, mirror the manually-validated experiment prompt: ask for
`last_reported_fiscal_quarter_end` and `next_earnings_date` (ISO dates), and have the worker
script compute `days_since_earnings`/`days_to_earnings` from those dates against
`(new DateTimeImmutable())` at write time — the same reference-date-injection discipline
`FinancialDataFetcher`'s own `EarningsTiming` calculation already follows.

**NULL fields must render to be colorable, but not every NULL field is "suspect."**
`templates/analysis.php:784`'s `continue` must be removed so every field in `$rawFields` renders
a row regardless of value — but the red/gray/green coloring is driven entirely by
`SuspectFieldDetector::detect()` and `FundamentalOverrideRepository::findByTicker()`, never by
"is this value null". A field like `trailing_pe` that is null because EPS is negative must render
with no color at all (present in `$rawFields`, absent from the suspect list) — it is a correct
NULL, not a gap.

**Currency for MA200 must use the price FX rate, not the statement rate.** Per
`context/foundation/lessons.md` ("Konwersja walut: wielkości 'na akcję' idą kursem ceny"),
converting `fetchDailyOhlc()`'s native daily closes to USD must use the same price-FX ratio
`FinancialDataFetcher::normalise()` already applies to `daily_ohlc`/`current_price` — derivable
as `current_price / native_price` from the already-fetched `$financials`, never the
`fx_rate_to_usd` (statement-currency) field.

## Phase 1: Data layer & local rule detection

### Overview

Two new tables and the pure, offline-testable detection/casting logic. No LLM calls, no HTTP
endpoints, no template changes — this phase is the deterministic foundation everything else
composes on top of.

### Changes Required:

#### 1. Migration: applied overrides table

**File**: `database/migrations/039_create_fundamental_overrides.sql`

**Intent**: One row per (ticker, field) that has been admin-confirmed. `value` is nullable to
represent the "checked, genuinely no data found" gray state distinctly from "never checked" (row
absent) — per `lessons.md` "brak danych to nie zero", a `checked_no_data` row must never be
misread as an override value.

**Contract**: Follow `database/migrations/037_create_peer_bucket_override.sql`'s style
(`CREATE TABLE IF NOT EXISTS`, InnoDB/utf8mb4, inline `AUTO_INCREMENT PRIMARY KEY`, admin
attribution columns). Columns: `ticker VARCHAR(20) NOT NULL`, `field_name VARCHAR(60) NOT NULL`,
`value VARCHAR(255) NULL` (stringified; cast back per `FundamentalFieldRegistry::FIELD_TYPES`),
`status VARCHAR(16) NOT NULL` (`validated` | `checked_no_data`), `source VARCHAR(32) NOT NULL
DEFAULT 'gemini_validation'` (also takes `local_calculation` for MA200), `validated_by INT
UNSIGNED NULL`, `validated_at DATETIME NOT NULL`. `UNIQUE KEY uq_fo_ticker_field (ticker,
field_name)`, `INDEX idx_fo_ticker (ticker)`.

#### 2. Migration: validation-run status table

**File**: `database/migrations/040_create_fundamental_validation_runs.sql`

**Intent**: One row per ticker holding the in-flight or last-completed validation job — mirrors
`ai_critical_reviews` (`database/migrations/030_create_ai_critical_reviews.sql`) exactly, but the
`content` equivalent is a structured diff, not free text.

**Contract**: `ticker VARCHAR(20) NOT NULL`, `status VARCHAR(16) NOT NULL DEFAULT 'pending'`
(`pending` | `completed` | `failed`), `mode VARCHAR(16) NOT NULL` (`all` | `missing`),
`requested_fields TEXT NULL` (JSON list), `diff TEXT NULL` (JSON: `field_name => {old, new,
status, note}`), `notes TEXT NULL` (Gemini's free-text explanation, display-only, never parsed
into `diff`), `error_message TEXT NULL`, `model VARCHAR(80) NULL`, `requested_by INT UNSIGNED
NULL`, `requested_at DATETIME NOT NULL`, `completed_at DATETIME NULL`. `UNIQUE KEY uq_fvr_ticker
(ticker)`.

#### 3. Field registry (single source of truth)

**File**: `src/Api/FundamentalFieldRegistry.php`

**Intent**: One static class holding the field whitelist so the detector, the merger, and the
prompt-builder can never diverge (per `lessons.md` "Dwie implementacje jednej reguły zawsze się
rozjadą").

**Contract**: `const SCORING_FIELDS` — the curated list of fields that feed `CVSModel` (revenue,
gross_profit, ebitda, free_cash_flow, operating_cash_flow, total_debt, cash, total_equity,
current_assets, current_liabilities, price_to_book, book_value_per_share, return_on_equity,
return_on_assets, trailing_pe, forward_pe, ps_ratio, ev_ebitda, peg_ratio, dividend_yield,
payout_ratio, gross_margins, operating_margin, profit_margin, revenue_growth, forward_eps,
trailing_eps, shares_outstanding). `const EXPECTED_NON_NULL` — subset flagged suspect when null
(gross_profit, total_equity, current_assets, current_liabilities, ps_ratio). `const
LOCALLY_COMPUTED` — `['moving_average_200']`, never sent to Gemini. `const EARNINGS_DATE_FIELDS`
— `['last_reported_fiscal_quarter_end' => 'days_since_earnings', 'next_earnings_date' =>
'days_to_earnings']` mapping the date field Gemini is asked for to the day-count field actually
stored. `const FIELD_TYPES` — `field_name => 'int'|'float'` for every storable field, used both
for casting stored `VARCHAR` values back and for building the JSON-shape hint in the prompt.

#### 4. Suspect-field detector

**File**: `src/Api/SuspectFieldDetector.php`

**Intent**: Pure function over `$financials` (plus optional list of `$financials` from prior
snapshots is NOT needed — single-payload rules only) returning which fields are suspect and why,
for both the red-coloring UI and for building the "missing" mode's field list.

**Contract**: `public static function detect(array $financials): array<string, string>` (field
name => human-readable Polish reason for the tooltip). Rules: (1) consistency —
`free_cash_flow > operating_cash_flow` (both non-null) flags `free_cash_flow`; (2) cadence —
`days_since_earnings` present and greater than a `SUSPECT_CADENCE_DAYS = 150` constant flags
`days_since_earnings` (150 = one quarter + a generous buffer, calibrated against the confirmed
real bug values of 1900+ days so it never false-positives on a slightly-late reporter); (3)
expected-missing — any `FundamentalFieldRegistry::EXPECTED_NON_NULL` field that is null in
`$financials` is flagged. `moving_average_200` null is flagged too (via the same expected-missing
path, added explicitly), but downstream code branches it to local computation, never to Gemini.

#### 5. Override repository

**File**: `src/Api/FundamentalOverrideRepository.php`

**Intent**: CRUD over `fundamental_overrides` for read (drives UI coloring + merge) and
write (confirm step persists here).

**Contract**: `findByTicker(string $ticker): array<string, array{value: ?string, status: string,
source: string, validated_at: string}>` keyed by `field_name`. `upsert(string $ticker, string
$field, ?string $value, string $status, string $source, ?int $adminId): void` — INSERT ... ON
DUPLICATE KEY UPDATE on `(ticker, field_name)`, mirroring the INSERT-or-UPDATE-on-duplicate-key
shape already used by `AiCriticalReviewRepository::markPending()`.

#### 6. Override merger

**File**: `src/Api/FundamentalOverrideMerger.php`

**Intent**: The single place that applies confirmed overrides on top of a freshly-fetched
`$financials` array — used by both the confirm-endpoint's rescore call and (implicitly, via the
same merge point convention as `peer_bucket_override`) any future caller that needs it.

**Contract**: `public static function merge(array $financials, array $overrideRows): array` — for
each row in `$overrideRows` (from `FundamentalOverrideRepository::findByTicker()`) where `status
=== 'validated'` and `value !== null`, cast the string value per `FundamentalFieldRegistry::FIELD_TYPES[$field]`
and set `$financials[$field]`. Rows with `status === 'checked_no_data'` are never merged (they
exist for UI/history only). Insertion point in every caller: immediately after `fetch()` returns,
before `PayloadCompleteness::missingEssentialFields()` and before `CVSModel::calculate()` — same
point `bin/rescore.php:204-207` merges `peer_bucket_override`.

#### 7. Local MA200 calculator

**File**: `src/Api/MovingAverageCalculator.php`

**Intent**: Computes a genuine 200-daily-close SMA from a wider one-off `fetchDailyOhlc('1y')`
call, applying the price-FX rate correctly (see Critical Implementation Details).

**Contract**: `public static function computeMa200(array $dailyOhlcNative, float $priceFxRate):
?float` — takes the `close` array from `fetchDailyOhlc()`'s return shape, uses the last 200
entries (oldest-first per that method's docblock) if at least 150 are available (else returns
`null` — insufficient history, e.g. a recent IPO), multiplies each by `$priceFxRate`, returns the
simple average.

### Success Criteria:

#### Automated Verification:

- Migrations apply cleanly against a test DB: manual `mysql < 039_*.sql && mysql < 040_*.sql` (no
  automated migration runner exists in this codebase — verify by inspection + a local apply)
- `vendor/bin/phpunit tests/Api/FundamentalFieldRegistryTest.php` passes
- `vendor/bin/phpunit tests/Api/SuspectFieldDetectorTest.php` passes — covers: FCF>OCF flags;
  FCF≤OCF does not flag; days_since_earnings > 150 flags; ≤150 does not; each
  `EXPECTED_NON_NULL` field null flags, non-null does not; `trailing_pe` null never flags (not in
  the registry); `moving_average_200` null flags
- `vendor/bin/phpunit tests/Api/FundamentalOverrideMergerTest.php` passes — covers: validated
  row overwrites `$financials[field]` with correctly-typed value; `checked_no_data` row is a
  no-op; absent field is a no-op
- `vendor/bin/phpunit tests/Api/MovingAverageCalculatorTest.php` passes — covers: ≥150 closes
  computes a plausible average; <150 closes returns null; FX rate applied correctly
- `composer stan` passes (PHPStan level 6) on all new files
- `php -l` clean on all new files

#### Manual Verification:

- N/A — this phase has no user-facing surface; verified entirely by automated tests

---

## Phase 2: Gemini validation service

### Overview

The prompt-builder/response-parser service that calls the existing `GeminiClient` with web
search, plus the job-status repository for `fundamental_validation_runs`.

### Changes Required:

#### 1. Validation-run repository

**File**: `src/Ai/FundamentalsValidationRunRepository.php`

**Intent**: Job-status tracking, structurally identical to `AiCriticalReviewRepository`.

**Contract**: `findByTicker(string $ticker): ?array`. `isPending(string $ticker): bool`.
`markPending(string $ticker, string $mode, array $requestedFields, int $adminId): void` (JSON-
encodes `$requestedFields`; INSERT-or-UPDATE-on-duplicate-key). `markCompleted(string $ticker,
array $diff, string $notes, string $model): void` (JSON-encodes `$diff`). `markFailed(string
$ticker, string $errorMessage): void`.

#### 2. Validation service

**File**: `src/Ai/FundamentalsValidationService.php`

**Intent**: Builds the prompt (mirroring the manually-validated experiment prompt: current values
for comparison, explicit instruction to web-search rather than recall, explicit instruction to
say "brak wiarygodnych danych" rather than guess), calls `GeminiClient::sendMessage()` with
`googleSearch` grounding, parses the JSON text response into a typed diff array.

**Contract**: `public function validate(string $ticker, array $sector, array $fieldsToCheck,
array $currentValues, ?GeminiClient $clientOverride = null): FundamentalsValidationResult` — a
small value object with `ok: bool`, `diff: array<string, array{old: mixed, new: mixed, status:
string}>`, `notes: string`, `model: string`, `failureMessage: ?string`. Response parsing:
`json_decode()` the model's text; any field in `$fieldsToCheck` absent or `null` in the decoded
response is recorded in `diff` with `status = 'checked_no_data'` and `new = null`; any field
present with a non-null value is recorded with `status = 'validated'`. Malformed JSON overall is
a full `AiResult`-style failure (`ok = false`), not a partial diff — mirrors "wszystko-albo-nic"
only for **transport/parse-level** failure; once JSON parses, per-field partial success (per the
partial-success decision) is the normal path, not an error.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/Ai/FundamentalsValidationServiceTest.php` passes — covers: well-
  formed JSON response produces correct `diff` with `validated`/`checked_no_data` split; missing/
  extra fields in the response are handled; malformed (non-JSON) response text produces `ok =
  false`; `googleSearch` tool is present in the request the `FakeTransport` receives (reuse
  `tests/Ai/FakeTransport.php`, same pattern as `GeminiClientTest.php`)
- `vendor/bin/phpunit tests/Ai/FundamentalsValidationRunRepositoryTest.php` passes — covers
  markPending/markCompleted/markFailed round-trip, mirroring `AiCriticalReviewRepositoryTest.php`
- `composer stan` passes; `php -l` clean

#### Manual Verification:

- N/A — no user-facing surface yet

---

## Phase 3: Single-ticker rescore composition

### Overview

A reusable composer that takes an already-fetched-and-merged `$financials` array and does exactly
what `bin/rescore.php`'s per-ticker body does — full parity (score, snapshot, ATR zone, alert
digest) — for the confirm endpoint to call after applying overrides.

### Changes Required:

#### 1. Single-ticker rescorer

**File**: `src/TrackRecord/SingleTickerRescorer.php`

**Intent**: Extract `bin/rescore.php:204-252`'s per-ticker body into a reusable, unit-testable
class, so the admin confirm-endpoint and the batch script both call the identical logic instead
of two hand-written copies (per `lessons.md` "Dwie implementacje jednej reguły zawsze się
rozjadą") — **note**: `bin/rescore.php` itself is left unmodified per Non-Goals/Guardrails (it's
not refactored to call this new class in this plan; the new class is a faithful parallel
extraction for the new caller only, since touching the daily batch script is explicitly out of
scope and carries its own regression risk).

**Contract**: Constructor takes `CVSModel`, `SnapshotWriter`, `MedianResolver`,
`PriceAlertRepository`, `AlertService`, `array $atrZonesConfig`. `public function rescore(string
$ticker, array $financials, array $cvsWeightsConfig): SingleTickerRescoreResult` (value object:
`qualityGatePassed: bool`, `cvsResult: CVSResult`, `fairValue: ?float`). Body order: peer-bucket-
override merge (`$peerOverrides` lookup, same as `bin/rescore.php:204-207` — inject via
constructor, fetched once per instantiation) → `CVSModel::calculate()` → extract
price/sector/industry/companyName/fx fields from `$financials` → `FairPriceCalculator::compute()`
→ `SnapshotWriter::persist(..., CvsSnapshotRepository::ORIGIN_RESCORE, ...)` → `AtrZoneCalculator::compute()`
+ `PriceAlertRepository::upsertZone()` when a zone exists → `AlertService::checkAndNotify()` →
caller is responsible for calling `AlertService::flushDigests()` once, since it's a class-level
batch-sender not meant to fire per rescore call (the confirm endpoint calls it once after
`rescore()` returns, matching `bin/rescore.php:257`'s single end-of-run call).

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/TrackRecord/SingleTickerRescorerTest.php` passes — covers: a
  passing-gate `$financials` (reuse `CVSModelTest::baseFinancials()` fixture shape) produces a
  `SnapshotWriter::persist()` call with the right `origin`/`model_version`; a gate-rejecting
  payload still persists via `CVSResult::failed()`'s live `model_version` (never a null-version
  write, per `lessons.md`); ATR zone upsert is skipped when `daily_ohlc` is empty; peer-bucket
  override is applied when present in the injected map
- `composer stan` passes; `php -l` clean

#### Manual Verification:

- N/A — exercised end-to-end in Phase 4/5's manual verification, not standalone

---

## Phase 4: Async worker + admin controller

### Overview

The three HTTP endpoints (trigger, poll status, confirm) and the background worker script, wired
with the admin gate and CSRF, mirroring `criticalReview()`/`criticalReviewStatus()` exactly plus
one new confirm action.

### Changes Required:

#### 1. Background worker script

**File**: `bin/validate_fundamentals.php`

**Intent**: The slow work — separates the requested field list into locally-computable
(`moving_average_200`) and Gemini-bound buckets, computes the local one directly (a wider
`fetchDailyOhlc('1y')` call, per the MA200 decision — this is the ONLY place that ever requests
the wider OHLC range), calls `FundamentalsValidationService` for the rest, converts any
`last_reported_fiscal_quarter_end`/`next_earnings_date` dates in the response to
`days_since_earnings`/`days_to_earnings` day-counts (see Critical Implementation Details), merges
both buckets into one diff, and calls `markCompleted()`/`markFailed()`.

**Contract**: `php bin/validate_fundamentals.php <ticker> <userId> <mode: all|missing>`. Same CLI
guard, dedicated `logs/validate_fundamentals.log` (never `error_log()`, per `lessons.md`), `.env`
loading, and `$_SESSION = []` workaround as `bin/generate_critical_review.php`.

#### 2. Admin controller

**File**: `src/Ai/FundamentalsValidationController.php`

**Intent**: HTTP surface. `trigger()` validates admin+CSRF+not-already-pending, computes the
field list for the requested mode (`all` → `FundamentalFieldRegistry::SCORING_FIELDS`; `missing`
→ `SuspectFieldDetector::detect()` keys), `markPending()`, fires the worker via `exec($cmd . '
&')` (hardcoded PHP 8.2 binary path, `logs/validate_fundamentals.log` redirect — same shape as
`AiAnalysisController::criticalReview()`), returns 202. `status()` is GET, auth-only, returns the
run's JSON state (mirrors `criticalReviewStatus()`). `confirm()` validates admin+CSRF, re-reads
the run's `diff` (must be `status = 'completed'`), for each `validated`/`checked_no_data` entry
calls `FundamentalOverrideRepository::upsert()`, fetches fresh `$financials` for the ticker,
merges overrides via `FundamentalOverrideMerger::merge()`, calls
`PayloadCompleteness::missingEssentialFields()` (new call site — the gap `research.md` flagged;
this is where it belongs, honoring PRD FR-012), runs `SingleTickerRescorer::rescore()`, calls
`AlertService::flushDigests()`, returns the updated score + per-field new states as JSON.

**Contract for admin gate**: `private function isCurrentUserAdmin(): bool` inline in this
controller, re-reading `UserRepository::findById($_SESSION['user_id'])['is_admin']` — duplicated
per-controller, matching the existing convention (`TickerLinkController.php:29-32`), not extracted
into a shared helper (per research's explicit recommendation to follow convention over reducing
duplication for a single new controller).

#### 3. Routes

**File**: `src/Core/routes.php`

**Intent**: Wire the three endpoints under the existing `// AI Analysis (S-01)` banner area,
tagged with this change's slug.

**Contract**: Add after line 142 (`criticalReviewStatus` route):
```php
// Fundamentals validation (change: fundamentals-validation)
$fundamentalsValidation = new FundamentalsValidationController();
$router->post('/analysis/{ticker}/validate-fundamentals',          fn($req) => $fundamentalsValidation->trigger($req));
$router->get('/analysis/{ticker}/validate-fundamentals/status',    fn($req) => $fundamentalsValidation->status($req));
$router->post('/analysis/{ticker}/validate-fundamentals/confirm',  fn($req) => $fundamentalsValidation->confirm($req));
```
Add the corresponding `use CVS\Ai\FundamentalsValidationController;` alongside the other `use`
statements at the top of the file.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/Ai/FundamentalsValidationControllerTest.php` passes — covers:
  non-admin gets rejected before CSRF/business logic runs; missing CSRF token is rejected;
  duplicate trigger while pending returns 409; `confirm()` on a non-completed run is rejected;
  `confirm()` on a completed run calls the repository upsert and rescorer with correct arguments
  (mock/fake the collaborators, mirroring `AiAnalysisControllerCriticalReviewTest.php`'s pattern)
- `composer stan` passes; `php -l` clean on all touched files

#### Manual Verification:

- As an admin, POST to `/analysis/GIS/validate-fundamentals` (mode=missing) via browser dev tools
  or curl with a valid session+CSRF token; confirm `logs/validate_fundamentals.log` shows the
  worker ran and `fundamental_validation_runs` has a `completed` row with a non-empty `diff`
- Confirm `bin/rescore.php`'s own log output is unaffected when run manually alongside (no shared
  state collision)

**Implementation Note**: Pause here for manual confirmation before Phase 5 — Phase 5 wires the UI
on top of these endpoints, so verifying the HTTP contract directly first isolates backend bugs
from template bugs.

---

## Phase 5: UI

### Overview

Extend the analysis template: render every `$rawFields` entry (including nulls), color by suspect/
validated/checked-no-data state, add the two admin-only buttons, and the trigger→poll→diff→confirm
JS flow mirroring the critical-review pattern.

### Changes Required:

#### 1. Controller: pass new view data

**File**: `src/CVS/AnalysisController.php`

**Intent**: `show()` needs to compute and pass the per-field state map and the current validation-
run status, plus an `isAdmin` flag that doesn't exist in this view today.

**Contract**: Add to the `Response::view('analysis', [...])` array (near the existing
`'financials' => $financials` entry): `'fieldStates' => `(merge of `SuspectFieldDetector::detect($financials)`
keys as `'suspect'` and `FundamentalOverrideRepository::findByTicker($ticker)` rows as
`'validated'`/`'checked_no_data'`, overrides winning when both apply)`, `'validationRun' =>
FundamentalsValidationRunRepository::findByTicker($ticker)`, `'isAdmin' => !empty($_SESSION['is_admin'])`.

#### 2. Template: raw-data section

**File**: `templates/analysis.php`

**Intent**: Extend `$rawFields` with the missing PRD fields, stop skipping null values, add
per-row color class + tooltip, add the two admin-gated buttons and a diff/confirm area.

**Contract**: Add to the `$rawFields` array (`:755-781`): `'gross_profit' => 'Zysk brutto ($)'`,
`'total_equity' => 'Kapitał własny ($)'`, `'current_assets' => 'Aktywa obrotowe ($)'`,
`'current_liabilities' => 'Zobowiązania krótkoterminowe ($)'`, `'ps_ratio' => 'P/S'`,
`'moving_average_200' => 'Średnia 200-dniowa (USD)'`. Change the loop (`:782-792`) to remove the
`if ($val === null) continue;` skip, and add `data-field="<?= htmlspecialchars($key) ?>"` plus a
CSS class from `$fieldStates[$key] ?? null` (`'field--suspect'` / `'field--validated'` /
`'field--checked-no-data'` / no class) on the `<tr>`, with a `title` attribute carrying the
suspect reason or validation date/source. After the `</table>` (`:794`), inside the same `<?php
if (!empty($isAdmin)): ?>` block, add the two buttons (`id="btn-validate-all"`, `id="btn-validate-
missing"`, `data-ticker="<?= htmlspecialchars($ticker) ?>"`) and an initially-hidden diff/confirm
container (`id="validation-diff"`).

#### 3. Template: JS

**File**: `templates/analysis.php`

**Intent**: Trigger→poll→render-diff→confirm→refresh flow, structurally copying
`crHandleClick()`/`crStartPolling()`/`crPoll()`/`crStopPolling()` (`:1514-1657`) with a third
state (diff shown, awaiting confirm) inserted between "poll sees completed" and "done".

**Contract**: New IIFE block (same file, near the existing critical-review JS): click handler on
both buttons POSTs to `/analysis/{ticker}/validate-fundamentals` with `mode` from which button was
clicked, same CSRF header/body pattern (`:1516-1524`); on `202` starts a poll loop (same 5s
interval / 5-minute cap constants, new names to avoid colliding with `CR_POLL_INTERVAL_MS`); on
`status === 'completed'` renders the `diff` inline into the matching `<tr>`s (new-value cell +
"Zastosuj" button) instead of auto-applying; the confirm button POSTs to
`/analysis/{ticker}/validate-fundamentals/confirm`; on success, re-renders the affected rows with
the `field--validated`/`field--checked-no-data` class and updates the visible CVS score elements
on the page (swing/fund values already rendered elsewhere in this same template — re-use their
existing DOM ids rather than a full page reload, since the score changed silently underneath).

#### 4. CSS

**File**: `public/css/app.css`

**Intent**: Three field-state colors, matching the app's existing color token usage.

**Contract**: Add `.raw-table .field--suspect`, `.raw-table .field--validated`, `.raw-table
.field--checked-no-data` rules near the existing `.raw-table` rule (`app.css:1127`), using the
same CSS custom properties (`--c-danger`, `--c-success`, `--c-muted`) other chips in this template
already reference (e.g. `$atrChip`'s inline styles in `templates/screener.php`).

### Success Criteria:

#### Automated Verification:

- `php -l templates/analysis.php` clean (per `lessons.md` "Szablony PHP sprawdzać php -l przed
  deployem")
- `composer stan` passes on `src/CVS/AnalysisController.php`

#### Manual Verification:

- As an admin, open the analysis page for a ticker with known-suspect fields (e.g. GIS); confirm
  suspect fields render red, previously-hidden-when-null fields now render
- Click "Sprawdź dane brakujące"; confirm the button disables, a pending indicator shows, and
  polling stops automatically once the worker completes (check `logs/validate_fundamentals.log`
  for timing)
- Confirm the diff renders inline with old/new values per suspect field
- Click "Zastosuj"; confirm the row(s) turn green/gray appropriately, the on-page CVS score
  updates without a full reload, and `fundamental_overrides` has the new row(s)
- Reload the page; confirm the overridden field still shows green (state persists) and its value
  now comes from the override (spot-check against `fundamental_overrides` directly)
- As a non-admin user, confirm neither button nor any suspect coloring hint about the feature is
  visible
- Manually run `bin/rescore.php` for the whole watchlist afterward; confirm the overridden
  ticker's next batch-produced snapshot still reflects the override (merge point is honored on
  the next fetch too, not just the manual confirm call)

---

## Testing Strategy

### Unit Tests:

- `SuspectFieldDetector` — every rule in isolation, plus a combined-flags case
- `FundamentalOverrideMerger` — cast correctness per field type, no-op on `checked_no_data`
- `MovingAverageCalculator` — sufficient/insufficient history, FX application
- `FundamentalsValidationService` — JSON parse success/partial/failure, `googleSearch` tool
  presence in the outgoing request (via `FakeTransport`)
- `FundamentalsValidationRunRepository` / `FundamentalOverrideRepository` — CRUD round-trips
- `SingleTickerRescorer` — gate-pass and gate-reject paths, ATR skip-when-no-OHLC
- `FundamentalsValidationController` — auth/CSRF/pending-guard ordering, confirm-requires-
  completed-run

### Integration Tests:

- None planned beyond the controller unit tests above (matches Non-Goals: no live-API
  integration test).

### Manual Testing Steps:

1. Trigger "Sprawdź dane brakujące" on a ticker with confirmed-suspect fields (e.g. GIS) and
   verify the diff matches what the manual 3-provider experiment found.
2. Trigger "Sprawdź wszystkie dane" and verify the request payload sent to Gemini is limited to
   `FundamentalFieldRegistry::SCORING_FIELDS` (check `logs/validate_fundamentals.log` or a
   temporary debug dump — not committed).
3. Verify a field Gemini can't find (e.g. `forward_fcf_est`-shaped absence) shows the gray
   "checked, no data" state after confirm, not red or green.
4. Verify the daily `bin/rescore.php` batch, run manually, does not error and does not duplicate
   any override logic (it remains untouched — confirm via `git diff bin/rescore.php` showing no
   changes).

## Performance Considerations

The only widened network call (`fetchDailyOhlc('1y')` for MA200) happens exclusively inside the
manually-triggered background worker, never on `FinancialDataFetcher::fetch()`'s default path —
zero impact on the daily batch or any page load. The Gemini call itself runs at the existing
`config/gemini.php` timeout budget (180s/200s total), already sized for a background worker, not
a user-facing request.

## Migration Notes

Both new tables are purely additive (`CREATE TABLE IF NOT EXISTS`); no existing table or data is
touched. Next available migration numbers are `039` and `040` (last is `038_create_llm_gemini_wallet_tables.sql`).

## References

- Research: `context/changes/fundamentals-validation/research.md`
- PRD: `context/foundation/prd-fundamentals-validation.md`
- Shape notes: `context/foundation/shape-notes-fundamentals-validation.md`
- Async pattern to copy: `src/Ai/AiAnalysisController.php:296-433`, `bin/generate_critical_review.php`
- Batch pipeline to mirror: `bin/rescore.php:161-253`
- Gemini search-grounded call to copy: `src/LlmGemini/LlmGeminiContextGatherer.php:65-82`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not
> rename step titles. See `references/progress-format.md`.

### Phase 1: Data layer & local rule detection

#### Automated

- [x] 1.1 Migrations 039/040 apply cleanly — 82bad48
- [x] 1.2 FundamentalFieldRegistry tests pass — 82bad48
- [x] 1.3 SuspectFieldDetector tests pass — 82bad48
- [x] 1.4 FundamentalOverrideMerger tests pass — 82bad48
- [x] 1.5 MovingAverageCalculator tests pass — 82bad48
- [x] 1.6 PHPStan level 6 clean on new files — 82bad48
- [x] 1.7 php -l clean on new files — 82bad48

#### Manual

(none — see Phase overview)

### Phase 2: Gemini validation service

#### Automated

- [x] 2.1 FundamentalsValidationService tests pass — 689c52f
- [x] 2.2 FundamentalsValidationRunRepository tests pass — 689c52f
- [x] 2.3 PHPStan clean; php -l clean — 689c52f

#### Manual

(none — see Phase overview)

### Phase 3: Single-ticker rescore composition

#### Automated

- [x] 3.1 SingleTickerRescorer tests pass — 64d78b2
- [x] 3.2 PHPStan clean; php -l clean — 64d78b2

#### Manual

(none — see Phase overview)

### Phase 4: Async worker + admin controller

#### Automated

- [x] 4.1 FundamentalsValidationController tests pass
- [x] 4.2 PHPStan clean; php -l clean on all touched files

#### Manual

- [ ] 4.3 Trigger endpoint fires worker and produces a completed run with a non-empty diff
- [ ] 4.4 bin/rescore.php log output unaffected when run alongside

### Phase 5: UI

#### Automated

- [ ] 5.1 php -l clean on templates/analysis.php
- [ ] 5.2 PHPStan clean on AnalysisController.php

#### Manual

- [ ] 5.3 Suspect fields render red; previously-hidden null fields now render
- [ ] 5.4 Trigger button → pending indicator → poll stops on completion
- [ ] 5.5 Diff renders inline with old/new values
- [ ] 5.6 Confirm applies overrides, updates row colors and on-page score without reload
- [ ] 5.7 Override persists across page reload and comes from fundamental_overrides
- [ ] 5.8 Non-admin sees no validation UI at all
- [ ] 5.9 bin/rescore.php batch run afterward reflects the override on its own next snapshot
