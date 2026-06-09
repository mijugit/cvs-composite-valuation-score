# Stock Price Forecast Implementation Plan

## Overview

Add an analyst-forecast section to the `/analysis/{ticker}` detail page, modeled on
stockanalysis.com's forecast page. It surfaces analyst price targets (average / median /
low / high + upside%), the recommendation consensus breakdown (Strong Buy … Strong Sell),
a monthly recommendation-trend chart, and a price-target "fan" chart that projects the
historical price line forward to the high/mean/low targets. All data comes from reliable
Yahoo Finance fields; the section is rendered as a clearly separated card so it is never
confused with the CVS model output.

## Current State Analysis

- **Data pipeline already terminates at the template.** `FinancialDataFetcher::fetch()`
  → `normalise()` → `$financials` array → `AnalysisController::show()` passes
  `'financials' => $financials` to `templates/analysis.php`. No route, controller
  signature, or DB change is needed — new fields added to `normalise()` are immediately
  available in the template.
- **Price targets are already fetched but not extracted.** The `financialData` module is
  in `FinancialDataFetcher::MODULES` (src/Api/FinancialDataFetcher.php:41-49) and contains
  `targetMeanPrice`, `targetMedianPrice`, `targetHighPrice`, `targetLowPrice`,
  `numberOfAnalystOpinions`, `recommendationMean`, `recommendationKey`. `normalise()`
  (lines 337-454) does not currently read any of them.
- **Consensus breakdown + trend require a new module.** `recommendationTrend` is not in
  `MODULES`. It returns a `trend` array of up to 4 periods (`0m`, `-1m`, `-2m`, `-3m`),
  each with `strongBuy`, `buy`, `hold`, `sell`, `strongSell` integer counts.
- **Chart pattern is established (s-07).** `templates/analysis.php:266-376` shows the
  Chart.js + `monthly_closes` line-chart pattern (base-100 normalisation, month labels,
  dark-theme axis styling) to reuse for the fan chart; a stacked bar chart reuses the same
  Chart.js global already loaded by the layout.
- **`normalise()` is untested by design.** Per CLAUDE.md the suite runs fully offline and
  never exercises `FinancialDataFetcher`; tests use synthetic `$financials` arrays. New
  parsing logic must therefore live in a pure, I/O-free unit that can be tested directly.
- **Config policy.** CLAUDE.md / FR-010: never hardcode thresholds — they belong in
  `config/cvs-weights.php`. The `recommendationMean → label` mapping is a threshold and
  goes into a new config section.
- **Language.** UI strings Polish; code/identifiers/comments English.

## Desired End State

Opening `/analysis/XOM` (a well-covered large cap) shows, below the CVS result card and
above the disclaimer, a "Prognoza analityków" card containing: a price-target block
(average/median/low/high + upside% vs current price), a consensus block (Polish summary
label + Strong Buy…Strong Sell counts + analyst count), a monthly recommendation-trend
stacked bar chart, and a price-target fan chart. Opening `/analysis/<small-cap-or-ETF>`
with no analyst coverage shows the page exactly as today — the forecast card and any
empty sub-block simply do not render. `ForecastParser` is covered by offline unit tests.

### Key Discoveries:

- Forecast data reaches the view with zero controller changes via the existing
  `$financials` array (src/CVS/AnalysisController.php:134-140).
- The template already `require`s `config/cvs-weights.php` (templates/analysis.php:68), so
  the consensus-label mapping can read its thresholds at render time without touching the
  controller.
- Yahoo's `recommendationMean` scale is 1 (Strong Buy) … 5 (Strong Sell); lower is more
  bullish. Upside% = `(targetMeanPrice − currentPrice) / currentPrice`.
- The fetcher is constructed with only the `data_source` config slice
  (src/CVS/AnalysisController.php:32), so it cannot see top-level `analyst_consensus`
  thresholds — confirming the label mapping must happen in the template, not the fetcher.

## What We're NOT Doing

- No revenue / EPS forecast tables (would require the `earningsTrend` module) — deferred.
- No per-analyst named forecast table — not available from Yahoo's free API.
- No changes to the CVS model, Quality Gate, pillars, recommendations, or the dashboard
  batch flow.
- No new route, no DB table/migration, no `AnalysisController` signature change.
- No forecast data on the dashboard batch results (`POST /analysis`) — detail page only.

## Implementation Approach

Two phases with a clean data/presentation split. Phase 1 adds a pure, testable
`CVS\Forecast\ForecastParser` and wires its output into `normalise()` under a single
`'forecast'` key, plus the config thresholds. Phase 2 renders the card and the two charts,
guarding every sub-block so partial/absent coverage degrades gracefully.

## Critical Implementation Details

- **Fan chart data join.** The fan chart must render the historical line and the forward
  projection on one continuous x-axis. Build a labels array of `len` historical months
  plus a `+12M` endpoint; the history dataset has `null` for the projection endpoint, and
  three projection datasets (high/mean/low) are `null` for all historical points except
  the last (anchored at `current_price`) and the `+12M` endpoint (the target). Chart.js
  with `spanGaps: false` then draws three straight lines fanning from "now" to the targets.
- **Empty-data discipline.** `ForecastParser::parse()` must return a structurally stable
  array whose sub-keys are `null`/`[]` when a Yahoo field is absent, so the template can
  test each block with `!empty()` independently. `numberOfAnalystOpinions === 0` and a
  missing `targetMeanPrice` are both "no targets".

## Phase 1: Data layer, parser, config, tests

### Overview

Add the `recommendationTrend` module, create the pure `ForecastParser`, merge its output
into `normalise()`, add the `analyst_consensus` config section, and unit-test the parser.

### Changes Required:

#### 1. Add the recommendationTrend module

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Make Yahoo return the analyst recommendation breakdown/trend alongside the
modules already fetched.

**Contract**: Append `'recommendationTrend'` to the `MODULES` constant (lines 41-49). No
other fetch-path change; the raw module surfaces as `$raw['recommendationTrend']['trend']`.

#### 2. New pure parser

**File**: `src/Forecast/ForecastParser.php` (new; namespace `CVS\Forecast`)

**Intent**: Extract all forecast figures from the raw Yahoo response into a flat,
template-ready structure with no I/O, so it is unit-testable offline. Also provide the
`recommendationMean → Polish label` mapping as a separate pure method.

**Contract**: Two static methods.
- `parse(array $raw, ?float $currentPrice): array` returns:
  `['targets' => ['mean'=>?float,'median'=>?float,'high'=>?float,'low'=>?float,'upside'=>?float], 'num_analysts'=>?int, 'recommendation_mean'=>?float, 'recommendation_key'=>?string, 'trend'=>array<int,array{period:string,strong_buy:int,buy:int,hold:int,sell:int,strong_sell:int}>, 'latest'=>?array{...same count keys...}]`.
  Reads `$raw['financialData']` for targets / mean / key (unwrapping Yahoo `{raw,fmt}`
  objects) and `$raw['recommendationTrend']['trend']` for the period rows. `upside` =
  `(mean − currentPrice)/currentPrice` when both present, else `null`. `latest` is the
  `0m` period (or first row) for the consensus breakdown block.
- `consensusLabel(float $mean, array $thresholds): string` maps the mean to one of
  `Silne Kupuj / Kupuj / Trzymaj / Sprzedaj / Silna Sprzedaż` using the config thresholds
  (lower mean = more bullish).

#### 3. Wire parser into normalise()

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Expose forecast data through the existing normalised `$financials` array.

**Contract**: In `normalise()` (the returned array, lines 401-453) add
`'forecast' => ForecastParser::parse($raw, $currentPrice)`. Add the
`use CVS\Forecast\ForecastParser;` import.

#### 4. Config thresholds

**File**: `config/cvs-weights.php`

**Intent**: Hold the consensus-label thresholds in config per FR-010.

**Contract**: New top-level section `'analyst_consensus'` with mean cut-points, e.g.
`['strong_buy' => 1.5, 'buy' => 2.5, 'hold' => 3.5, 'sell' => 4.5]` (mean ≤ cut-point →
that label; mean > sell cut-point → Silna Sprzedaż). Comment notes Yahoo's 1–5 scale.

#### 5. Parser unit tests

**File**: `tests/Forecast/ForecastParserTest.php` (new)

**Intent**: Lock parser behavior offline with synthetic raw fixtures.

**Contract**: Cover (a) full coverage → all targets, upside sign/value, breakdown counts,
trend ordering; (b) missing `financialData` targets → `targets` nulls but trend still
parsed; (c) empty/absent `recommendationTrend` → `trend === []`, `latest === null`;
(d) `numberOfAnalystOpinions === 0` treated as no coverage; (e) `consensusLabel` boundary
values at each threshold (1.0, 1.5, 2.5, 3.5, 4.5, 5.0).

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `vendor/bin/phpunit`
- New parser tests pass: `vendor/bin/phpunit tests/Forecast/ForecastParserTest.php`
- PSR-4 autoloads the new namespace (no `composer dump-autoload` error)

#### Manual Verification:

- `ForecastParser::parse()` on a captured XOM raw payload returns sane targets and a
  non-empty trend (spot-check via a throwaway script or REPL).

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human before proceeding to Phase 2.

---

## Phase 2: UI — "Prognoza analityków" card

### Overview

Render the forecast card on the analysis detail page with the price-target and consensus
blocks plus the trend and fan charts, hiding any block (or the whole card) that has no data.

### Changes Required:

#### 1. Forecast card markup

**File**: `templates/analysis.php`

**Intent**: Add the "Prognoza analityków" card after the CVS result card (after the
`card--result` closing `</div>` at line 212) and before the disclaimer (line 214). Pull
data from `$financials['forecast']`.

**Contract**: A `<div class="card forecast-card">` wrapping four `!empty()`-guarded blocks;
the whole card is wrapped so it renders only when `$financials['forecast']` has any usable
data.
- **Targets block**: average / median / low / high tiles + an upside% badge (green ≥ 0,
  red < 0) computed from `forecast.targets.upside`; shows `forecast.num_analysts`.
- **Consensus block**: Polish summary label via
  `ForecastParser::consensusLabel($mean, $cfg['analyst_consensus'])` (the file already
  `require`s the config at line 68) + Strong Buy…Strong Sell counts from `forecast.latest`.
- **Trend chart**: `<canvas id="reco-trend-chart">` rendered only when `forecast.trend`
  is non-empty.
- **Fan chart**: `<canvas id="forecast-fan-chart">` rendered only when both
  `financials.monthly_closes` and `forecast.targets.high/mean/low` exist.
- All labels Polish; escape all output with `htmlspecialchars` / `number_format`.

#### 2. Recommendation-trend chart script

**File**: `templates/analysis.php`

**Intent**: Draw a stacked bar chart of the consensus breakdown across the available
periods, newest on the right.

**Contract**: A guarded `<script>` (Chart.js `type: 'bar'`, `stacked` x/y) consuming
`json_encode($financials['forecast']['trend'])`; five datasets (Strong Buy…Strong Sell)
with the existing dark-theme colour conventions; `animation: false`. Same `typeof Chart`
guard as the s-07 chart.

#### 3. Price-target fan chart script

**File**: `templates/analysis.php`

**Intent**: Project the historical price line forward to the high/mean/low targets.

**Contract**: A guarded `<script>` (Chart.js `type: 'line'`, `spanGaps: false`) building
the joined dataset described in "Critical Implementation Details": last N `monthly_closes`
as the history line + three projection datasets (high/mean/low) anchored at the current
price and terminating at a `+12M` label. Reuse the s-07 month-label generation; append the
`+12M` endpoint. `animation: false`.

#### 4. Styles

**File**: `public/css/app.css`

**Intent**: Style the forecast card, target tiles, upside badge, and chart containers
consistently with existing cards and the s-07 `.price-chart-section`.

**Contract**: Add `.forecast-card`, `.forecast-targets` (tile grid), `.upside-badge`
(positive/negative variants), and fixed-height chart wrappers for `#reco-trend-chart` and
`#forecast-fan-chart`, mirroring existing card/border/colour variables.

### Success Criteria:

#### Automated Verification:

- Full test suite still passes: `vendor/bin/phpunit`
- No PHP parse/lint error in the template: `php84 -l templates/analysis.php`

#### Manual Verification:

- `/analysis/XOM` shows the forecast card under CVS / above disclaimer with sane targets,
  upside%, consensus label + counts, a populated trend bar chart, and a fan chart that
  fans from the current price to the three targets.
- A no-coverage ticker (e.g. an ETF) renders the page with NO forecast card and no layout
  break.
- A partial-coverage ticker (targets present, trend empty or vice versa) shows only the
  populated blocks.
- Disclaimer still present; CVS card unchanged; no regression in the s-07 price chart or
  the radar chart.

**Implementation Note**: After completing this phase and all automated verification passes,
pause here for manual confirmation from the human.

---

## Testing Strategy

### Unit Tests:

- `ForecastParserTest`: full/partial/empty coverage, upside computation, trend ordering,
  `consensusLabel` threshold boundaries (see Phase 1 #5).

### Integration Tests:

- None automated (the view layer has no test harness and the fetcher is offline-excluded
  by design); covered by the manual checks above.

### Manual Testing Steps:

1. `php -S localhost:8000 -t public`, log in, open `/analysis/XOM` — verify all four blocks.
2. Open an ETF / micro-cap with no analyst coverage — verify the card is absent.
3. Open a ticker with targets but no/partial trend — verify only populated blocks show.
4. Confirm disclaimer, CVS card, radar chart, and s-07 price chart are unchanged.

## Performance Considerations

The `recommendationTrend` module rides the existing single quoteSummary request — no extra
HTTP round-trip. Charts render client-side from already-fetched data. Session cache
unchanged (cached `$financials` from before deploy simply lacks `forecast` until TTL
expiry — the empty-guards handle that gracefully).

## Migration Notes

None — no schema change. Existing session-cached financials without a `forecast` key fall
through the template's `!empty()` guards and render as today until the cache expires.

## References

- Change identity & scope: `context/changes/s-09-forecast/change.md`
- Reuse pattern (Chart.js + monthly_closes): `templates/analysis.php:266-376`
- Data normalisation insertion point: `src/Api/FinancialDataFetcher.php:401-453`
- Config policy (FR-010): `config/cvs-weights.php`, `CLAUDE.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Data layer, parser, config, tests

#### Automated

- [x] 1.1 Full test suite passes: `vendor/bin/phpunit` — a67c31c
- [x] 1.2 New parser tests pass: `vendor/bin/phpunit tests/Forecast/ForecastParserTest.php` — a67c31c
- [x] 1.3 PSR-4 autoloads the new namespace (no `composer dump-autoload` error) — a67c31c

#### Manual

- [x] 1.4 `ForecastParser::parse()` on a captured XOM raw payload returns sane targets and a non-empty trend — a67c31c

### Phase 2: UI — "Prognoza analityków" card

#### Automated

- [x] 2.1 Full test suite still passes: `vendor/bin/phpunit` — fdad844
- [x] 2.2 No PHP parse/lint error in the template: `php84 -l templates/analysis.php` — fdad844

#### Manual

- [x] 2.3 `/analysis/XOM` shows the forecast card under CVS / above disclaimer with all four blocks populated — fdad844
- [x] 2.4 A no-coverage ticker (ETF) renders the page with NO forecast card and no layout break — fdad844
- [x] 2.5 A partial-coverage ticker shows only the populated blocks — fdad844
- [x] 2.6 Disclaimer present; CVS card, radar chart, and s-07 price chart unchanged — fdad844
