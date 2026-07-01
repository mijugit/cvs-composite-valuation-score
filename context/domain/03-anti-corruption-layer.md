# 03 — Anti-Corruption Layer: the `$financials` array

## What's already good (don't re-solve this)

`FinancialDataFetcher::normalise()` (`src/Api/FinancialDataFetcher.php:549`) already does real
translation work: Yahoo's raw camelCase fields (`longBusinessSummary`, `financialCurrency`, the
nested `calendarEvents`/`earningsTrend` shapes) are renamed into domain-ish snake_case
(`free_cash_flow`, `total_debt`, `long_description`, `current_price`) before anything else in the
app sees them. `EarningsCalendarParser`/`EarningsTrendParser`/`EarningsSurpriseParser`
(`src/Forecast/`) go further and are genuinely well-isolated ACLs: raw Yahoo keys like
`epsTrend.90daysAgo` or `calendarEvents.earnings.earningsDate` are referenced **only** inside
these three parser classes — `OverlayPenalties`/`EarningsGuard` (the domain code that consumes
their output) never touch a raw Yahoo key, only the parsers' clean typed return values
(`revisionPct`, etc.). This is exactly the DDD "adapter/narrow port" pattern working correctly.

## The actual gap: translation happens, but no typed contract exists

The renamed output of `normalise()` is still just a plain PHP `array` (`fetch(): ?array`) — not a
value object, not even a documented `@phpstan-type` shape. **12 files** across 6 namespaces
consume it directly as `$financials`:

```
src/Admin/TickersController.php      src/CVS/OverlayPenalties.php
src/Ai/AiAnalysisController.php      src/CVS/Pillars/MomentumPillar.php
src/Ai/AiDivergenceService.php       src/CVS/Pillars/QualityPillar.php
src/CVS/AnalysisController.php       src/CVS/Pillars/ValuationPillar.php
src/CVS/CVSModel.php                 src/CVS/QualityGate.php
                                      src/CVS/Valuation/ValuationMetrics.php
                                      src/TrackRecord/CorpusScorer.php
```

Each of these independently assumes which keys exist and which are nullable — there is no single
place that says "this is the shape a `$financials` array has." The only thing playing that role
today is `CVSModelTest::baseFinancials()`, a **test fixture**, not an enforced contract. PHPStan
(level 6, otherwise strict everywhere else in this codebase) sees `array` and checks nothing about
its keys.

**Concretely, this is the ts-fsrs `Card` problem from the lesson, one layer removed:** there the
external library's `stability`/`difficulty`/`due` fields leaked straight into domain code. Here,
Yahoo's fields get *renamed* on the way in (so it looks translated), but the untyped array itself
is exactly as free to leak as the raw shape would have been — a missing/renamed Yahoo field
doesn't fail loudly, it silently produces `null`, and CLAUDE.md already documents the natural
consequence: `ValuationPillar` *"returns neutral 50 only when growth data is unavailable"* — a
correct defensive default, but also a mechanism that would mask a schema drift as "the neutral
case" rather than surface it as "Yahoo changed something."

## Where the corruption could actually get in

- Yahoo Finance is an unversioned, undocumented API (no contract, no changelog) — the single
  riskiest external dependency in the whole app, and the one with the least protection today.
- `CVSModel::calculate()` and all three pillars are the parts of this codebase with the strongest
  determinism/correctness guarantee (CLAUDE.md: *"same `$financials` input → identical CVS and
  recommendation"*) — ironically the part most exposed to an unvalidated external shape, since the
  guarantee is only as good as what actually arrives in `$financials`.

## Suggested resolution (not yet applied)

Introduce a `FinancialsSnapshot` value object (or, cheaper first step, a `@phpstan-type
FinancialsShape` array-shape annotation on `FinancialDataFetcher::fetch()`'s return) as the one
place the "shape of normalised Yahoo data" is declared. Every one of the 12 consumers would then
get static-analysis coverage for free — PHPStan would catch a typo'd or dropped key at the call
site instead of at runtime as a silent neutral-50 score. Scoped as a candidate for a future Deep
Focus / refactor slice (same shape as the `SnapshotWriter`/`ValuationMetrics` extractions already
done in this project) — **not** applied here, since introducing a value object across 12
call sites is exactly the kind of change that needs its own plan + golden-value tests before/after,
not a drive-by edit during the DDD-notes pass.
