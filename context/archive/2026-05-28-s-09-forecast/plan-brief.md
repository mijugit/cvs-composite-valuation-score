# Stock Price Forecast — Plan Brief

> Full plan: `context/changes/s-09-forecast/plan.md`

## What & Why

Add an analyst-forecast section to the `/analysis/{ticker}` detail page, modeled on
stockanalysis.com's forecast page. It gives users analyst price targets, the recommendation
consensus, its trend over time, and a forward price projection — context that complements
the CVS model without being part of it.

## Starting Point

The detail page already renders the CVS score, radar, raw data, and (s-07) price chart. The
data pipeline `FinancialDataFetcher::fetch() → normalise() → $financials → analysis.php`
already passes everything to the template; price-target fields are even fetched (in the
`financialData` module) but never extracted.

## Desired End State

Below the CVS card and above the disclaimer sits a "Prognoza analityków" card: price targets
(avg/median/low/high + upside%), a consensus block (Polish label + Strong Buy…Strong Sell
counts), a monthly recommendation-trend bar chart, and a price-target fan chart projecting
the historical line to the high/mean/low targets. Tickers without analyst coverage render
exactly as today — the card and any empty block simply don't appear.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| MVP scope | Targets + consensus + trend + fan chart | Reliable Yahoo fields; revenue/EPS deferred | Plan |
| Missing coverage | Independent blocks, hide empties | Robust across ETFs/small caps | Plan |
| Fan chart shape | History + fan to 12M horizon | Matches the reference page | Plan |
| Consensus label | Polish label derived from counts/mean | UI is Polish (CLAUDE.md) | Plan |
| Label thresholds | New `analyst_consensus` config section | FR-010: no hardcoded thresholds | Plan |
| Testing | Pure `ForecastParser` + unit tests | Fetcher is offline-excluded by design | Plan |
| Placement | Under CVS card, above disclaimer | CVS model is the primary product | Plan |

## Scope

**In scope:** price targets + upside%, consensus breakdown + Polish label, recommendation
trend bar chart, price-target fan chart, hide-empty logic, parser unit tests.

**Out of scope:** revenue/EPS forecast tables, per-analyst named table, CVS model changes,
dashboard batch results, new route/DB/controller changes.

## Architecture / Approach

New module `recommendationTrend` added to the fetcher; a pure `CVS\Forecast\ForecastParser`
(static, no I/O) turns the raw response into a flat `forecast` sub-array merged into the
normalised `$financials`. The template reads `$financials['forecast']`, maps the consensus
mean to a Polish label via config thresholds, and renders four guarded blocks plus two
Chart.js charts (reusing the s-07 pattern). No controller, route, or DB change.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Data + parser + config + tests | `forecast` key in `$financials`, tested parser, config thresholds | Yahoo field shape variance across tickers |
| 2. UI card + 2 charts | Rendered forecast card with trend + fan charts | Fan-chart history↔projection join on one axis |

**Prerequisites:** none beyond the existing project (composer installed, PHP 8.2+).
**Estimated effort:** ~1–2 sessions across 2 phases.

## Open Risks & Assumptions

- Yahoo `recommendationTrend` / target fields are absent for many ETFs and small caps —
  handled by per-block empty guards (the primary design driver).
- Session-cached `$financials` from before deploy lack the `forecast` key until TTL expiry;
  empty guards make this graceful.
- Yahoo's unofficial endpoints can change field shapes without notice (existing project risk).

## Success Criteria (Summary)

- `/analysis/XOM` shows targets, upside%, consensus label + counts, trend chart, and a fan
  chart fanning current price → high/mean/low.
- No-coverage tickers render with no forecast card and no layout break.
- CVS card, radar, s-07 price chart, and disclaimer unchanged; `ForecastParser` unit-tested.
