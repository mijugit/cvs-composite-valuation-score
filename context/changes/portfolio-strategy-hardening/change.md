---
id: portfolio-strategy-hardening
title: "Portfolio strategy hardening: CVS swing rules, hard guards, P&L exits, retries, live UX"
status: implemented
created: 2026-06-29
updated: 2026-06-29
roadmap_ref: "S-02 (+ F-01 / F-03 / S-01 hardening)"
---

## Summary

Production-hardening pass over the autonomous virtual portfolio after the first
real autonomous rebalances surfaced contract, execution, and strategy gaps. Turns
the F-03 decision contract from "runs but generic" into a CVS-grounded swing
strategy with deterministic, server-side risk guards, P&L-based exits, bounded
daily retries, and a complete portfolio UX (live pricing, per-position reasons,
strategy rules).

Spans four roadmap items: F-03 (decision contract), F-01 (scheduler/idempotency),
S-01 (portfolio view), S-02 (first autonomous rebalance — now actually live).

### What shipped

**Strategy (CVS-grounded swing).** Empty prompt replaced with full CVS methodology
(dual-mode swing/fund, recommendation bands, golden signals) plus explicit
construction rules. Screener pre-filtered to `golden=strong` + current holdings
(156 rows → ~20–35). Portfolio value, target weight, per-holding P&L and band
(emerging/mature) passed into the prompt. All thresholds in
`config/portfolio.php → strategy`.

**Hard server-side guards (`DecisionEnforcer`, new).** The LLM narrates cap-aware
reasoning but emits un-trimmed quantities, so caps are enforced deterministically:
per-stock 15%, per-sector 40%, trim-to-fit against cash; force-sell on stop-loss
even when the model says HOLD; no same-cycle rebuy of a sold ticker.

**P&L exits.** Holdings carry unrealized P&L in the prompt. SELL priority ladder:
hard stop-loss (−15%, server-enforced) > soft take-profit (+25%, model judgement)
> signal decay (swing<54 / REDUKUJ-UNIKAJ / golden null-momentum) > weight trim.
Hysteresis tightened 50 → 54.

**Reliability fixes.**
- Parser: strip markdown fences; skip individually-invalid items instead of
  failing the whole batch; `quantity 0` on HOLD normalised to null.
- Real execution prices injected before `executeCycle` (LLM never returns
  `price_usd`; the executor was costing buys at $0).
- Execution deadlock (SQLSTATE 1205) fixed: `executeCycle` and its `CycleRepository`
  now share one fresh connection created after the LLM call.
- Bounded daily retries: `CycleRepository::claimForRun()` + `attempt_count`
  (migration 028) retry failed cycles up to `max_daily_attempts` (3); timing
  driven by the cron schedule. LLM timeout 20s → 45s.
- `nextTradingDay` timezone fix (ET, not Warsaw midnight).

**Portfolio UX (S-01).** Live re-pricing on load (`LivePriceProvider`,
session-cached 15 min, 5s/quote timeout, snapshot fallback, non-USD safe);
per-position P&L column and live/snapshot badge; ⓘ popover with the last-rebalance
justification per holding; market clock (Warsaw/NY); config-driven strategy-rules
section (buy/hold/sell, ordered by importance).

## Context

The F-03 change delivered the decision *contract* and retry policy; this change is
the operational hardening that followed the first live cycles. The recurring lesson:
arithmetic constraints requiring a running sum (sector cap, stop-loss) must be
server-enforced — the LLM describes them correctly but cannot keep them in sync with
its structured `quantity` fields. Preserves the existing invariants: deterministic
CVS core, config-not-hardcode (FR-010), atomic ledger writes, mandatory disclaimer,
PHPStan level 6, full offline test suite.

## Components touched

- New: `src/Portfolio/DecisionEnforcer.php`, `src/Portfolio/LivePriceProvider.php`,
  `src/Api/LatestPriceSource.php`, `database/migrations/028_add_attempt_count_to_rebalance_cycle.sql`
- Changed: `DecisionService`, `DecisionParser`, `CycleRepository`, `PortfolioController`,
  `PortfolioRepository`, `MarketCalendar` callers, `bin/portfolio-rebalance.php`,
  `config/portfolio.php`, `templates/portfolio.php`, `public/css/app.css`
- Tests: `DecisionEnforcerTest`, `CycleRepositoryTest`, `LivePriceProviderTest`,
  extended `DecisionParserTest` (594→598 tests, PHPStan level 6 green)

## Links

- PRD: `context/foundation/prd-virtual-portfolio.md`
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (S-02, F-01, F-03, S-01)
- Builds on: `context/changes/llm-decision-contract-and-retry/` (F-03 contract + retry)
- Living reference (strategy, runbook, config): `docs/autonomous-portfolio.md`
- Lessons: `context/foundation/lessons.md`
