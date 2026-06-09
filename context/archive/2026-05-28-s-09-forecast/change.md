---
change_id: s-09-forecast
title: Stock Price Forecast — analyst price targets, consensus and recommendation trend
status: archived
created: 2026-05-28
updated: 2026-05-28
archived_at: 2026-05-28T21:58:16Z
---

## Notes

Stock Price Forecast feature modeled on stockanalysis.com/stocks/<ticker>/forecast/.
Renders an analyst-forecast card on the `/analysis/{ticker}` detail page.

MVP scope (confirmed):
- Analyst price targets: average / median / low / high + upside % vs current price.
  Source: Yahoo `financialData` module (`targetMeanPrice`, `targetMedianPrice`,
  `targetHighPrice`, `targetLowPrice`, `numberOfAnalystOpinions`) — already fetched,
  not yet extracted in `FinancialDataFetcher::normalise()`.
- Analyst consensus breakdown: Strong Buy / Buy / Hold / Sell / Strong Sell counts.
  Source: new `recommendationTrend` module.
- Recommendation trend chart: monthly breakdown over the last periods (0m/-1m/-2m/-3m).
  Source: same `recommendationTrend` module.
- Price-target forecast chart: historical price line projected forward to the
  analyst targets as a high / average / low "fan" (max / średnia / min), anchored
  at current price. Reuses `monthly_closes` (history) + target high/mean/low from
  `financialData`. Chart.js already loaded (same pattern as s-07 price chart).

Out of scope (decided):
- Revenue / EPS forecast tables (would need `earningsTrend` module) — deferred.
- Per-analyst named forecast table — not available from Yahoo free API.

Design constraints:
- Analyst data is NOT the CVS model — render in a clearly separated "Prognoza analityków"
  card. CVS disclaimer still mandatory (CLAUDE.md).
- Technical pattern mirrors s-07 (price chart): extend fetcher + analysis.php template
  + CSS, add normalise() unit tests. UI Polish, code English.
