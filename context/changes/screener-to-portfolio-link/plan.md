# Screener to Portfolio Linkage (S-04) — Implementation Plan

## Overview

Add bidirectional visual linkage between the screener and the portfolio:
(1) each screener row gets a "w portfelu" badge when the ticker is currently held,
with a conflict-colour variant when the recommendation is negative (REDUKUJ/UNIKAJ);
(2) the portfolio page gains a "Polecane przez screener" section listing tickers with
reco SILNE KUPUJ or AKUMULUJ that are not yet held.

## Current State Analysis

- `ScreenerRepository::getFiltered()` returns rows from `cvs_snapshots` only — zero
  awareness of `portfolio_holdings`. No "held" markers exist in `templates/screener.php`.
- `PortfolioRepository::getCurrentHoldings()` returns `{ticker, quantity, avg_entry_price,
  updated_at}` for `quantity > 0`. There is no method that cross-references screener data.
- `PortfolioController::index()` already reads `$liveModelVersion` from
  `config/cvs-weights.php` and uses it for `getCurrentHoldingsWithPrice()` — the same
  version value is needed to query `cvs_snapshots` for the recommended-not-held list.
- `PortfolioRepository` already JOINs `cvs_snapshots` in `getCurrentHoldingsWithPrice()`,
  establishing the precedent for cross-table queries in this repository.
- Pattern for PHPUnit: `tests/Portfolio/PortfolioHistoryRepositoryTest.php` (SQLite in-memory,
  no fixtures, pure schema + INSERT).
- CSS pattern: `.signal-pill`, `.card`, color variables `--c-warn`, `--c-danger`,
  `--c-primary` all defined in `public/css/app.css`.

## Desired End State

A logged-in user viewing the screener sees each row they currently hold marked with a
pill "w portfelu" next to the ticker; rows with a negative recommendation show the pill
in amber/red. On the portfolio page, a new section lists screener-recommended tickers
(SILNE KUPUJ or AKUMULUJ, quality_gate=1) they do not yet hold, sorted by CVS Swing
descending; the section is hidden when the list is empty.

Verify: screener badge appears for held tickers, absent for non-held; conflict colour
on REDUKUJ/UNIKAJ rows held in portfolio; portfolio section lists correct tickers
(no held tickers, no NEUTRALNIE/REDUKUJ/UNIKAJ rows); section hidden with empty screener data.

### Key Discoveries

- `ScreenerController::__construct()` already loads `config/cvs-weights.php` and creates
  `ScreenerRepository` with `$liveModelVersion`. Adding `Database::connection()` +
  `PortfolioRepository::getCurrentHoldings()` call follows the same pattern used in
  `PortfolioController::index()`.
- `PortfolioRepository::getCurrentHoldingsWithPrice()` self-joins `cvs_snapshots`
  with `model_version` + `origin='RESCORE'` filters — the new recommended-not-held
  method must apply the same filters (lesson: shadow-row duplication, commit 442689d).
- Self-join pattern for "latest snapshot per ticker" is in `ScreenerRepository::findAllLatest()`
  — replicate it in `PortfolioRepository::getScreenerRecommendationsNotHeld()`.
- `PortfolioController::index()` builds `$holdings` first; `array_column($holdings, null, 'ticker')`
  gives the held-ticker set without a second DB query.
- reco strings use Unicode arrows (`⬆⬆ SILNE KUPUJ`, `⬆ AKUMULUJ`, `⬇ REDUKUJ`,
  `⬇⬇ UNIKAJ`) — match with `str_contains()` on the directional prefix, consistent
  with how `screener.php` already matches them for `$recoColor`.

## What We're NOT Doing

- No schema or migration changes.
- No ATR enrichment in the portfolio recommended-section (not needed for context).
- No "alarm" section on portfolio for negative-reco held positions (can be S-05/S-06 scope).
- No filtering UI inside the recommended section (fixed to SILNE KUPUJ + AKUMULUJ).
- No changes to the rebalance engine or decision pipeline.
- No per-user customisation — section is global, same as the rest of the portfolio.
- No screener filter for "show only held" — this is a static badge, not a filter.

## Implementation Approach

Three phases following the project's data → controller → view flow.

Phase 1 isolates the new repository method and its unit tests, making the data contract
explicit before any UI work touches it. Phase 2 adds the screener-side held markers
(controller enrichment + template badge + CSS). Phase 3 adds the portfolio-side section
(controller call + template section). Each phase is independently verifiable.

## Critical Implementation Details

- **Shadow-row guard**: `getScreenerRecommendationsNotHeld()` must filter by both
  `model_version` AND `origin='RESCORE'` in the self-join (same as
  `ScreenerRepository::findAllLatest()`) — omitting either causes duplicate rows when
  shadow scoring is active.
- **reco matching**: use `str_contains($reco, 'SILNE KUPUJ')` and `str_contains($reco,
  'AKUMULUJ')` rather than exact equality — the emoji prefix is reliable, but string
  exact-match is fragile if whitespace ever changes.
- **Empty $heldTickers**: `getScreenerRecommendationsNotHeld([])` must not crash —
  handle with an unconditional query (no `NOT IN` clause when array is empty, or return
  all quality-gated reco rows when no tickers are held).

---

## Phase 1: Data layer — recommended-not-held repository method

### Overview

Add `PortfolioRepository::getScreenerRecommendationsNotHeld()` and lock its contract
with SQLite-backed unit tests.

### Changes Required

#### 1. New repository method

**File**: `src/Portfolio/PortfolioRepository.php`

**Intent**: Return the latest snapshot rows for tickers that (a) have a positive screener
recommendation (SILNE KUPUJ or AKUMULUJ), (b) pass quality_gate, (c) are not in
the supplied held-ticker list, and (d) belong to the live model version. This is the
data foundation for the portfolio "Polecane" section.

**Contract**: `getScreenerRecommendationsNotHeld(array $heldTickers, string $liveModelVersion): array`
— self-join on `cvs_snapshots` (MAX(score_date) per ticker, same pattern as
`ScreenerRepository::findAllLatest()`), WHERE `quality_gate = 1` AND `origin = 'RESCORE'`
AND `model_version = $liveModelVersion` AND `reco_swing` matches SILNE KUPUJ or AKUMULUJ
(PHP-side `str_contains` after fetch OR SQL `LIKE` — either is fine at ~50 rows).
When `$heldTickers` is empty, omit the NOT IN clause entirely (returns all matching rows).
When non-empty, build dynamic `NOT IN (?, ?, …)` placeholders same as
`getTransactionsForCycles()`. Returns assoc rows with at least:
`ticker, cvs_swing, cvs_fund, reco_swing, golden_signal, price_at_snapshot, score_date`.
Sorted by `cvs_swing DESC`.

#### 2. Unit tests

**File**: `tests/Portfolio/PortfolioScreenerLinkTest.php` (new)

**Intent**: Lock the contract with an SQLite in-memory schema — same approach as
`PortfolioHistoryRepositoryTest.php`. Schema must include `cvs_snapshots` and
`portfolio_holdings`.

**Contract**: 5 tests covering:
(a) only SILNE KUPUJ and AKUMULUJ rows returned — NEUTRALNIE excluded;
(b) held tickers excluded from results;
(c) `quality_gate = 0` rows excluded;
(d) wrong `model_version` rows excluded;
(e) empty `$heldTickers` parameter returns all matching rows (no crash, no empty result
  when there are qualifying snapshots).

### Success Criteria

#### Automated Verification

- Lint passes: `php -l src/Portfolio/PortfolioRepository.php`
- New tests pass: `vendor/bin/phpunit tests/Portfolio/PortfolioScreenerLinkTest.php`
- Full suite green: `vendor/bin/phpunit`
- Static analysis clean: `vendor/bin/phpstan analyse`

#### Manual Verification

- (none — pure data layer, covered by tests)

**Implementation Note**: Automated verification only — proceed after tests pass.

---

## Phase 2: Screener markers — held badges and conflict colours

### Overview

`ScreenerController::index()` fetches the held-ticker set and passes it to the template;
`screener.php` renders badges; CSS defines the visual styles.

### Changes Required

#### 1. ScreenerController enrichment

**File**: `src/Screener/ScreenerController.php`

**Intent**: Enrich the screener view model with a map of currently held tickers so the
template can render badges without any logic in the view.

**Contract**: Inside `index()`, after `AuthController::requireAuth()` and before
`Response::view()`: instantiate `Database::connection()` and `new PortfolioRepository($db)`;
call `getCurrentHoldings()`; build `$heldTickersMap = array_fill_keys(array_column($holdings, 'ticker'), true)`.
Add `'heldTickersMap' => $heldTickersMap` to the `Response::view()` array.
Add `use CVS\Portfolio\PortfolioRepository;` and `use CVS\Core\Database;` imports.

#### 2. Screener template badges

**File**: `templates/screener.php`

**Intent**: For each screener row, show a "w portfelu" pill next to the ticker link
when `$heldTickersMap[$row['ticker']]` is set; use a conflict-colour variant when
the recommendation is REDUKUJ or UNIKAJ.

**Contract**: New local closure `$heldBadge(string $ticker, string $reco): string` that
checks `$heldTickersMap`; when held, returns a `<span>` with class `portfolio-badge`
or `portfolio-badge portfolio-badge--conflict` depending on `str_contains($reco, 'REDUKUJ')
|| str_contains($reco, 'UNIKAJ')`. Append output after the ticker `<a>` link in the
`<td>`. Add `class="<?= isset($heldTickersMap[$row['ticker']]) ? 'tr--held' : '' ?>"` to
the `<tr>`.

#### 3. CSS

**File**: `public/css/app.css`

**Intent**: Define the held-badge and conflict-badge styles, plus a subtle row highlight
for held positions, reusing existing CSS variables.

**Contract**: New block `/* --- Screener held markers (S-04) --- */` containing:
- `.portfolio-badge` — inline-block pill similar to `.signal-pill`, uses `--c-primary`
  tint (held = neutral/positive).
- `.portfolio-badge--conflict` — same pill with `--c-warn` or `--c-danger` background
  for REDUKUJ/UNIKAJ conflict.
- `.tr--held` — subtle background on the `<tr>` (e.g. `background: rgba(var(--c-primary-rgb), .05)` or equivalent existing variable).

### Success Criteria

#### Automated Verification

- Lint passes: `php -l src/Screener/ScreenerController.php templates/screener.php`
- Static analysis clean: `vendor/bin/phpstan analyse`
- Full suite green: `vendor/bin/phpunit`

#### Manual Verification

- Open `/screener`; tickers in portfolio show the "w portfelu" badge next to their link.
- Row background is subtly highlighted for held tickers.
- A held ticker with reco REDUKUJ or UNIKAJ shows the badge in amber/red.
- Non-held tickers show no badge; no layout regressions in the table.
- Screener renders correctly when portfolio is empty (no held tickers).

**Implementation Note**: After automated verification passes, pause for manual confirmation.

---

## Phase 3: Portfolio "recommended" section

### Overview

`PortfolioController::index()` calls `getScreenerRecommendationsNotHeld()`; `portfolio.php`
renders the new section below the holdings table.

### Changes Required

#### 1. PortfolioController enrichment

**File**: `src/Portfolio/PortfolioController.php`

**Intent**: Fetch the list of screener-recommended, not-yet-held tickers and add it
to the portfolio view model.

**Contract**: After `$holdings` is built (live prices applied), derive
`$heldTickers = array_keys(array_fill_keys(array_column($holdings, 'ticker'), true))`.
Call `$portfolioRepo->getScreenerRecommendationsNotHeld($heldTickers, $liveModelVersion)`.
Add `'recommended' => $recommended` to `Response::view()` compact.

#### 2. Portfolio template section

**File**: `templates/portfolio.php`

**Intent**: Render a "Polecane przez screener, ale nie w portfelu" section below the
holdings table; hide it completely when `$recommended` is empty.

**Contract**: `<?php if (!empty($recommended)): ?>` guard wrapping the entire section.
Section heading "Polecane przez screener" with a note about the filter (SILNE KUPUJ /
AKUMULUJ). Compact table: Ticker (link to `/analysis/{ticker}`), Rekomendacja (coloured
same as screener), CVS Swing, Cena. Sorted by CVS Swing (already sorted by repository).
No pagination — at ~50 tickers, full list is fine. Reuse `$recoColor` logic from
screener template (local closure or inline match).

### Success Criteria

#### Automated Verification

- Lint passes: `php -l src/Portfolio/PortfolioController.php templates/portfolio.php`
- Static analysis clean: `vendor/bin/phpstan analyse`
- Full suite green: `vendor/bin/phpunit`

#### Manual Verification

- Open `/portfolio`; "Polecane przez screener" section appears below holdings.
- Section lists tickers with SILNE KUPUJ or AKUMULUJ that are not in the holdings table.
- Currently held tickers do NOT appear in the section.
- NEUTRALNIE / REDUKUJ / UNIKAJ tickers do NOT appear in the section.
- Section is absent (not even an empty card) when screener has no qualifying rows.
- Ticker links in the section navigate to `/analysis/{ticker}`.
- No regressions in the holdings table or other portfolio sections.

**Implementation Note**: After automated verification passes, pause for manual confirmation.

---

## Testing Strategy

### Unit Tests

- `PortfolioScreenerLinkTest` (SQLite in-memory): reco filter, held exclusion,
  quality_gate exclusion, model_version exclusion, empty-heldTickers safety.

### Manual Testing Steps

1. Log in; open `/screener` — verify held tickers have badges, non-held do not.
2. Find (or temporarily create) a held ticker with reco REDUKUJ/UNIKAJ — verify badge
   is amber/red.
3. Open `/portfolio` — verify "Polecane przez screener" section appears and lists
   non-held SILNE KUPUJ / AKUMULUJ tickers.
4. Confirm no held tickers appear in the section.
5. Temporarily filter to reco UNIKAJ in screener — verify those rows have no badge
   if the ticker is not held.

## Performance Considerations

`getCurrentHoldings()` is an extra SELECT per screener page load — one query returning
~10–30 rows. Acceptable at this scale; session-caching not needed.
`getScreenerRecommendationsNotHeld()` is a self-join on ~50-row `cvs_snapshots` —
negligible at this scale.

## Migration Notes

None — no schema changes.

## References

- Change identity: `context/changes/screener-to-portfolio-link/change.md`
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (S-04)
- Pattern: `tests/Portfolio/PortfolioHistoryRepositoryTest.php` (SQLite test pattern)
- Pattern: `src/Portfolio/PortfolioRepository.php` — `getCurrentHoldingsWithPrice()` (cvs_snapshots JOIN)
- Pattern: `src/Screener/ScreenerRepository.php` — `findAllLatest()` (self-join pattern)
- Shadow-row lesson: `context/foundation/lessons.md` (commit 442689d filter rule)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Data layer

#### Automated

- [x] 1.1 Lint passes: `php -l src/Portfolio/PortfolioRepository.php`
- [x] 1.2 New tests pass: `vendor/bin/phpunit tests/Portfolio/PortfolioScreenerLinkTest.php`
- [x] 1.3 Full suite green: `vendor/bin/phpunit`
- [x] 1.4 Static analysis clean: `vendor/bin/phpstan analyse`

### Phase 2: Screener markers

#### Automated

- [ ] 2.1 Lint passes: `php -l src/Screener/ScreenerController.php templates/screener.php`
- [ ] 2.2 Static analysis clean: `vendor/bin/phpstan analyse`
- [ ] 2.3 Full suite green: `vendor/bin/phpunit`

#### Manual

- [ ] 2.4 Held tickers show "w portfelu" badge in screener
- [ ] 2.5 Row background highlighted for held tickers
- [ ] 2.6 Conflict badge amber/red for held tickers with negative reco
- [ ] 2.7 No badge for non-held tickers; no layout regressions
- [ ] 2.8 Screener renders correctly when portfolio is empty

### Phase 3: Portfolio section

#### Automated

- [ ] 3.1 Lint passes: `php -l src/Portfolio/PortfolioController.php templates/portfolio.php`
- [ ] 3.2 Static analysis clean: `vendor/bin/phpstan analyse`
- [ ] 3.3 Full suite green: `vendor/bin/phpunit`

#### Manual

- [ ] 3.4 "Polecane przez screener" section appears below holdings
- [ ] 3.5 Section lists only SILNE KUPUJ/AKUMULUJ tickers not in portfolio
- [ ] 3.6 Held tickers absent from section; NEUTRALNIE/REDUKUJ/UNIKAJ absent
- [ ] 3.7 Section hidden when no qualifying screener rows
- [ ] 3.8 Ticker links navigate to /analysis/{ticker}; no portfolio regressions
