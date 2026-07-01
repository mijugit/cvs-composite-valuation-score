# Deep Focus summary — CVS scoring engine (M4L3 format)

> Companion to the full [research.md](research.md) (10x-research, 2026-05-31) in this same folder,
> reshaped into the ② Feature overview / ③ Technical debt structure used by the Architect report.
> Chosen target: the CVS scoring engine (`src/CVS/CVSModel.php` + `Pillars/`), flagged by the
> repo map (`context/map/repo-map.md`) as the busiest and highest-consequence "local center" in
> the codebase.

## ② Feature overview

CVS computes a 0–100 composite score for a ticker from three independently-scored pillars
(Valuation, Momentum, Quality), combined by a fixed linear weighted sum that differs only by mode
(Swing 40/45/15, Fundamental 65/15/20). A binary Quality Gate runs first and can short-circuit to
`CVSResult::failed()` before any pillar computes a numeric score. Everything is
config-driven — weights, sector benchmarks, and recommendation thresholds all come from
`config/cvs-weights.php`, never hardcoded — and deterministic: same `$financials` input always
produces the same score (no `date()`/randomness inside scoring logic). Two "shadow" model
versions (3.1, 3.2) compute in parallel as post-aggregation penalty overlays but never change the
headline recommendation, a guardrail that lets the model evolve without breaking the live
product.

## ③ Technical debt

1. **Untyped external-data contract (confirmed structurally).** `FinancialDataFetcher::fetch()`
   returns a plain `array`, consumed directly by **12 files** across 6 namespaces
   (`grep -rl '\$financials' src/` → `CVSModel`, all 3 `Pillars/`, `QualityGate`,
   `ValuationMetrics`, `OverlayPenalties`, `CorpusScorer`, `AnalysisController`,
   `AiDivergenceService`, `AiAnalysisController`, `TickersController`). No value object or
   `@phpstan-type` shape declares what keys exist — PHPStan (level 6 everywhere else) checks
   nothing here. Full writeup: [`context/domain/03-anti-corruption-layer.md`](../../domain/03-anti-corruption-layer.md).
2. **The one untested boundary in an otherwise well-tested app.** Confirmed directly from
   CLAUDE.md's own documented rule: *"Tests run fully offline — no Yahoo Finance calls.
   `FinancialDataFetcher` is not exercised by the test suite."* This is the correct trade-off for
   determinism, but it means the single riskiest, least-stable dependency (an unversioned,
   undocumented external API) is also the one part of the codebase with zero automated coverage —
   the untyped-array risk above compounds this: neither type-checking nor tests would catch a
   Yahoo schema drift.
3. **Sigmoid steepness (`k=3`) in `ValuationPillar`/`MomentumPillar` normalisation, already
   flagged as too aggressive.** Documented in the Phase-3 framing
   (`context/archive/2026-06-03-cvs-scoring-refinement/frame.md`, problem P3): a fixed sigmoid
   slope over-punishes/over-rewards scores near the tails of the sector distribution. Deliberately
   deferred at the time ("P3 zostaje osobnym, wtórnym wygładzeniem") in favor of the peer-group
   granularity work — still open, tracked in Phase 7/8's percentile-scoring plan
   (quantile-based normalisation to replace the fixed-k sigmoid).

All three risks were already known to the project in some form (lessons.md, archived frame docs,
CLAUDE.md's own test-scope note) — this Deep Focus pass mainly **confirms them structurally**
(the 12-file fan-out count) rather than discovering them from nothing, which is itself a useful
signal: the project's own documentation trail already tracks its real debt reasonably well.
