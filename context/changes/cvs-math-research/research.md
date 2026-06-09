---
date: 2026-05-31T00:00:00+02:00
researcher: Claude (10x-research)
git_commit: 7c58977e9b038c4691843bfc6da6b49b67a40cbe
branch: main
repository: mijugit/cvs-composite-valuation-score
topic: "CVS mathematical apparatus — internal map vs external composite-valuation methodologies"
tags: [research, codebase, cvs-model, pillars, scoring, valuation]
status: complete
last_updated: 2026-05-31
last_updated_by: Claude (10x-research)
---

# Research: CVS Mathematical Apparatus (internal map)

**Date**: 2026-05-31
**Researcher**: Claude (10x-research, parallel sub-agents)
**Git Commit**: 7c58977
**Branch**: main
**Repository**: mijugit/cvs-composite-valuation-score

## Research Question

Map the complete mathematical apparatus of the CVS model: which metrics feed each of
the 3 pillars (Valuation, Momentum, Quality), how they are normalized, how per-pillar
scores are computed, how they combine into the 0–100 composite in both modes (Swing
val40/mom45/qual15 and Fundamental val65/mom15/qual20), where sector benchmarks and
recommendation thresholds come from. Goal: compare this methodology against documented
external composite-valuation-scoring approaches.

## Summary

CVS is a **deterministic, config-driven composite score** built from three independently
computed pillars, each emitting 0–100, combined by a **fixed linear weighted sum** per
mode, then clamped to [0,100] and mapped to a 5-level recommendation label. Key design
traits:

- **Normalization = logistic sigmoid** (Valuation, Momentum) and **discrete point buckets**
  (Quality). No z-scores, no cross-sectional percentile ranking — every score is computed
  from a single company's data against **hardcoded sector-median benchmarks**.
- **Relative-to-benchmark, not absolute**: Valuation scores EV/FCF (or growth-adjusted
  EV/Sales) as a *ratio to the sector median*; Momentum scores *excess return vs SPY*.
- **Binary Quality Gate** runs first as a hard filter; failure short-circuits to
  `CVSResult::failed()` (no numeric score), separate from the gradational `QualityPillar`.
- **Dual-mode**: Valuation and Quality raw scores are identical across modes; only Momentum
  recomputes with mode-specific `roc_weights`, and the final weighting differs.

This is, in one phrase, a **multi-factor composite scoring model** (a "factor model" /
"smart-beta-style scorecard") — the same family as AAII/Zacks/Piotroski/Morningstar-style
composite scores. See "Open Questions" for the external-comparison targets.

## Detailed Findings

### Orchestration & composite assembly — `src/CVS/CVSModel.php`

- Constructor injects full `config/cvs-weights.php`; builds QualityGate, ValuationPillar
  (all benchmarks), MomentumPillar (swing config — cap/divisor are shared) — `CVSModel.php:38-45`.
- Flow (`calculate()`): Quality Gate → if fail, `CVSResult::failed()` — `CVSModel.php:60-65`.
- Pillar raw scores identical for both modes; Momentum computed twice with mode-specific
  `roc_weights` — `CVSModel.php:76-82`.
- **Composite (linear weighted sum), per mode** — `CVSModel.php:85-97`:
  ```
  swingCvs = 0.40·val + 0.45·momSwing + 0.15·qual
  fundCvs  = 0.65·val + 0.15·momFund  + 0.20·qual
  ```
  rounded to 1 decimal, then clamped to [0,100] — `CVSModel.php:99-101`.
- Recommendation label via `match` on thresholds — `CVSModel.php:127-138`.

### Pillar 1 — Valuation — `src/CVS/Pillars/ValuationPillar.php`

- Inputs: `current_price`, `shares_outstanding`, `total_debt`, `cash`, `free_cash_flow`,
  `revenue`, `gross_margins`, plus growth drivers `forward_eps`/`trailing_eps`/
  `revenue_growth`/`earnings_quarterly_growth` — `ValuationPillar.php:47-50, 73, 84-85, 126-128, 150-151`.
- Enterprise Value: `EV = price·shares + total_debt − cash` — `ValuationPillar.php:56`.
- Forward growth derived (EPS-based → revenue → quarterly earnings, with base-effect and
  EPS/revenue-gap guards), then **capped at sector `max_growth`** — `ValuationPillar.php:70, 124-159`.
- **Variant A — forward EV/FCF** (when `free_cash_flow > 0`) — `ValuationPillar.php:75-78`:
  ```
  forwardFcf = FCF·(1 + g/100)^2
  ratio = (EV / forwardFcf) / median_ev_fcf
  ```
- **Variant B — growth/quality-adjusted forward EV/Sales** (FCF ≤ 0) — `ValuationPillar.php:91-102`:
  ```
  fwdSales = revenue·(1 + g/100)^2
  adjusted = (EV / fwdSales) / max(g·grossMargin, 0.001)
  target   = median_ev_sales / max((max_growth/2)·(median_gm/100), 0.001)
  ratio    = adjusted / max(target, 0.01)
  ```
- **Normalization = decreasing logistic sigmoid, centered at ratio=1, k=3** —
  `ValuationPillar.php:166-170`:
  ```
  score = 100 / (1 + exp(3·(ratio − 1)))   // ratio=1 → 50; cheaper → >50; pricier → <50
  ```
  clamped [0,100], rounded 2 dp.
- **Neutral 50.0** when: empty benchmarks, unresolved sector, null price/shares or shares≤0,
  EV≤0, growth underivable, or Variant-B revenue≤0 / gross_margins null —
  `ValuationPillar.php:34-67, 87-89`.
- ⚠️ **Scale note**: company `gross_margins` used as fraction (0–1) at line 95, while
  benchmark `median_gm` divided by 100 at line 98 — internally consistent only if config
  stores them on those respective scales (documented intent).

### Pillar 2 — Momentum — `src/CVS/Pillars/MomentumPillar.php`

- Inputs: `monthly_closes` (subject price history) and `spy_closes` (SPY benchmark) — raw
  close arrays, **not** pre-computed ROCs — `MomentumPillar.php:60, 93`.
- ROC per period by array offset from latest close: `ROC = (now/price_ago − 1)·100`;
  ~1M/3M/6M/12M, with 12M falling back to 6M when history < 13 months —
  `MomentumPillar.php:67-78`.
- **Weighted blend**: `composite = Σ roc_weights[period]·ROC[period]` — `MomentumPillar.php:85-89`.
  - Swing: `0.5·roc1m + 0.3·roc3m + 0.2·roc6m`
  - Fundamental: `0.3·roc3m + 0.4·roc6m + 0.3·roc12m`
- **SPY calibration**: same roc_weights applied to SPY closes → `spyCalib` (fallback
  constant 15.0 when SPY data insufficient) — `MomentumPillar.php:95-117`.
- **Normalization = excess-return sigmoid** — `MomentumPillar.php:124-128`:
  ```
  excess    = composite − spyCalib
  normRatio = 1 − excess/divisor      // divisor = 40
  raw       = 100 / (1 + exp(3·(normRatio − 1))) = 100 / (1 + exp(−excess·0.075))
  score     = clamp(raw, 5, 95)       // momentum_cap_min/max
  ```
  excess=0 → 50; outperform SPY → >50; underperform → <50. Rounded 2 dp.
- Neutral 50.0 when < 7 monthly closes or no valid 6M price — `MomentumPillar.php:63-65, 80-82`.
- ⚠️ **Latent finding**: sigmoid steepness `3.0` is **hardcoded** at line 127, NOT read from
  `config → sigmoid_k`. Config value happens to equal it, so changing `sigmoid_k` has no
  effect. (`momentum_divisor` IS read, line 120.) Flag for FR-010 compliance.

### Pillar 3 — Quality — `src/CVS/Pillars/QualityPillar.php`

- **Point-bucket scorecard**, raw 0–10 → ×10 → 0–100 — `QualityPillar.php:128-129, 136`.
- Gross margin vs sector median (0–4 pts): `gmDelta = gross_margins·100 − median_gm`;
  buckets ≥15→4, ≥5→3, ≥−5→2, ≥−15→1, else 0 — `QualityPillar.php:67-73`. Missing data →
  neutral 2 pts — `QualityPillar.php:77`.
- Leverage (0–3 pts): **net debt / EBITDA** `= max(0, total_debt−cash)/ebitda`; ≤1→3, ≤2.5→2,
  ≤4→1, else 0. Cash-burn fallback uses `cash/revenue` (≥0.30→2, ≥0.10→1) — `QualityPillar.php:90-108`.
- Forward growth (0–3 pts): >10%→3, >0%→1.5, else 0; same `extractForwardGrowth` priority as
  Valuation — `QualityPillar.php:117-121, 176-210`.
- **Implicit weighting** via differing caps: GM 40% / leverage 30% / growth 30% of raw 10.
  No single neutral-50 return.

### Quality Gate (binary pre-filter) — `src/CVS/QualityGate.php`

- Thresholds injected from `config → quality_gate` — `QualityGate.php:17`.
- Checks: positive revenue (fails on missing/≤0); gross margin < min → fail; D/E (total_debt/
  total_equity) > max → fail; current ratio < min → fail — `QualityGate.php:34-80`.
- **Lenient on missing data** (except revenue): `safeDiv` returns null → check skipped, no fail
  — `QualityGate.php:92-98`. Returns `QualityGateResult{passed, failures[]}` — `QualityGate.php:82-85`.

### Config — `config/cvs-weights.php`

- `modes` (weights + roc_weights + sigmoid/cap/divisor per mode) — `cvs-weights.php:19-42`.
- `benchmarks` — **hardcoded sector medians** (median_ev_fcf, median_ev_sales, median_gm,
  max_growth) for 11 sectors + DEFAULT, ported from the original Python `cvs_analyze.py v1.6`
  — `cvs-weights.php:50-63`.
- `quality_gate` thresholds — `cvs-weights.php:66-71`.
- `thresholds`: strong_buy 72 / accumulate 58 / neutral 42 / reduce 28 / below → avoid —
  `cvs-weights.php:74-80`.

## Code References

- `src/CVS/CVSModel.php:85-101` — composite weighted sum + clamp (both modes)
- `src/CVS/CVSModel.php:127-138` — recommendation label mapping
- `src/CVS/Pillars/ValuationPillar.php:56` — Enterprise Value
- `src/CVS/Pillars/ValuationPillar.php:75-102` — EV/FCF and EV/Sales ratio variants
- `src/CVS/Pillars/ValuationPillar.php:166-170` — valuation sigmoid
- `src/CVS/Pillars/MomentumPillar.php:75-89` — ROC + weighted blend
- `src/CVS/Pillars/MomentumPillar.php:124-128` — excess-return sigmoid + clamp
- `src/CVS/Pillars/QualityPillar.php:67-129` — point buckets + raw→100
- `src/CVS/QualityGate.php:34-85` — binary checks + result
- `config/cvs-weights.php:19-80` — modes, benchmarks, gate, thresholds

## Architecture Insights

- **Determinism by construction**: no RNG, no date/time inside scoring (CLAUDE.md guardrail).
- **FR-010 config-driven** — weights/thresholds never hardcoded in logic, with one exception
  to fix: Momentum sigmoid `k` is hardcoded (see latent finding).
- **Single-company, benchmark-relative** scoring (no live peer cross-section): the "universe"
  is compressed into static sector-median constants. This is the core methodological choice
  that distinguishes CVS from percentile/rank-based composite models.
- **Sigmoid everywhere a continuous ratio exists; buckets where data is coarse/categorical**
  (Quality). Caps (5–95 momentum) prevent single-factor blowups dominating the composite.

## Historical Context (from prior changes)

- `context/changes/s-05-dual-mode/` — the dual-mode rebuild (3 pillars, two weight profiles)
  that produced the current `CVSModel`/config shape.
- `context/changes/s-04-model-validation/` — model validation slice.
- `context/archive/2026-05-28-s-09-forecast/` — analyst-consensus (`recommendationMean`)
  integration; relevant to the "CVS vs analysts divergence" north-star of phase 2.

## Open Questions (→ external research targets for Exa/Context7)

These are deliberately NOT answered by internal research (per lesson: internal research won't
pick a library/methodology). They are the comparison targets for the external Exa pass:

1. What is the standard taxonomy/name for this kind of model? (multi-factor composite score /
   factor scoring / smart-beta scorecard?)
2. Documented reference methodologies for composite valuation scores: Piotroski F-Score,
   Altman Z-Score, Zacks Rank, AAII Grades, Morningstar Quantitative/Star, MSCI/S&P factor
   indices — how do they normalize (z-score vs percentile vs bucket) and combine?
3. Are there maintained open-source libraries for factor scoring / fundamental ratios in PHP
   or (more likely) Python that implement these, that could validate or replace bespoke math?
4. Is sigmoid-on-ratio-vs-median a recognized normalization, or do practitioners prefer
   cross-sectional z-score / winsorized percentile ranking?

## Related Research

- None yet under `context/changes/**/research.md`. This is the first research artifact.

---

## Follow-up Research 2026-05-31 — External methodologies (Exa + Context7)

**Topic in one word:** CVS is a **factor model** — specifically a **Value-Momentum-Quality (VMQ)
composite score**. AAII literally ships this as "VMQ Stocks". Same family as
Piotroski/Altman/Zacks/Morningstar/MSCI factor scoring.

### What the documented field does (sources)

- **AAII VMQ** (vmq.aaii.com): Value+Momentum+Quality, each 0–100, via **percentile rank** of
  several ratios across all exchange-listed stocks; momentum = weighted 4-quarter **relative
  strength rank**.
- **Morningstar Factor Profile** (factor methodology PDF): factor score = **z-score** vs
  universe, **winsorized**, standardized (mean 0, sd 1), **within region/sector**, then
  percentile-ranked 1–100. Quality = equal-weighted z-score(ROE) + z-score(−leverage).
- **MSCI Momentum** (methodology PDF): 6m & 12m price momentum → **z-scores**, combined 50/50,
  **winsorized at ±3**.
- **Vanguard / Fama-French / QuantRocket / skelf-sigc / QuanterLab / StockAlpha**: same recipe —
  per-factor **z-score or percentile rank**, **winsorize outliers**, **linear weighted sum**
  (weights sum to 1), rescale to 0–100. Canonical momentum = **12-1** (12-month return skipping
  the most recent month).
- **Sector-neutralization** (QuantRocket Lesson09): demean or z-score *by sector* — exactly the
  problem CVS solves with static sector medians, but done cross-sectionally on a live universe.

### CVS vs the field — where it matches, where it diverges

| Dimension | Documented mainstream | CVS | Verdict |
|---|---|---|---|
| Combine factors | Linear weighted sum, Σw=1, rescaled 0–100 | Same (`CVSModel.php:85-97`) | ✅ Textbook |
| Normalization | **Cross-sectional z-score / percentile** over a live universe, **winsorized** | **Logistic sigmoid** of ratio vs **static hardcoded sector median**, one company at a time | ⚠️ Non-mainstream |
| Sector handling | Sector/region-neutralize the cross-section | Static sector-median constants | 🟡 Lightweight analog |
| Momentum def. | 12-1 (skip last month), z-scored | 1/3/6m or 3/6/12m **incl. last month**, excess vs SPY, sigmoid | 🟡 Deliberate (swing) divergence |
| Value-trap guard | Explicit (AlphaStocks `min(Value,Mom)`; M* caps low-momentum at 3★) | Soft (golden signals; dual-mode divergence) | 🟡 Possible enhancement |
| Outliers | Winsorize at 1/99th pct | Sigmoid + caps (mom 5–95) absorb tails | 🟡 Different mechanism |

**Core insight:** CVS's distinctive choice is *normalization*. The industry normalizes a metric
**relative to a live peer universe** (z-score/percentile + winsorize); CVS normalizes **relative
to a static sector-median constant via sigmoid**, scoring one ticker in isolation. This is a
direct consequence of the architecture (no universe loaded at scoring time) — a reasonable
engineering tradeoff, with a known cost: scores are quasi-absolute, not truly peer-relative, and
depend on benchmark constants staying calibrated.

### Ready libraries (Context7 + Exa)

- **No drop-in PHP composite-scoring library exists.** The reusable assets are (a) **methodology**
  (Piotroski F-Score, Altman Z-Score, AAII VMQ, Morningstar/MSCI PDFs) and (b) **Python toolkits**:
  - **OpenBB** (`/openbb-finance/openbb`, Context7): `obb.equity.fundamental.ratios()`,
    `obb.equity.screener()`, `obb.famafrench.factors(factor="momentum", ...)` — ready fundamental
    ratios + Fama-French factor data. Could replace the bespoke Yahoo cURL fetch.
  - **Zipline / QuantRocket Pipeline**: `zscore()`, `demean()`, `rank()`, `winsorize()` with
    `groupby=sector` — the canonical sector-neutral factor operators.
  - **skelf-sigc** operators (`zscore`, `rank_pct`, `winsor`, `median`): same primitives.
- These assume a **cross-sectional pandas universe**, so they don't drop into CVS's single-company
  PHP flow — but they (i) validate the aggregation math (✅ CVS is standard) and (ii) offer a
  migration path if CVS ever loads its ~600-ticker universe for true peer-relative scoring
  (phase-2 screener already implies a universe).

### Updated Open Questions

- Should CVS adopt **winsorization** + **cross-sectional percentile/z-score** once the phase-2
  screener loads the ~600-ticker universe (true peer-relative scoring), or keep the static-median
  sigmoid for single-ticker determinism? (Trade-off: peer-relativity vs determinism/simplicity.)
- Should momentum offer a **12-1** academic variant alongside the swing 1/3/6m blend?
- Add an explicit **value-trap guard** (cap composite when momentum is bottom-decile)?

### External Sources

- AAII VMQ — https://vmq.aaii.com/usersguidecontent.cfm
- Morningstar Factor Profile methodology — https://www.morningstar.com/content/dam/marketing/shared/pdfs/Research/Factor_Profile_Methodology.pdf
- Morningstar Quantitative Equity Research — https://s205.q4cdn.com/437373358/files/doc_downloads/methodology_documents/Quantative-Equity-Research-Effective-2-Dec-2024.pdf
- MSCI Momentum Indices methodology — https://www.msci.com/eqb/methodology/meth_docs/MSCI_Momentum_Indices_Methodology_Nov13.pdf
- Vanguard — Not all factors are created equal — https://corporate.vanguard.com/content/dam/corp/research/pdf/not_all_factors_are_created_equal_factors_role_in_asset_allocation.pdf
- QuanterLab — Scoring Methodology: Theory — https://quanterlab.com/articles/scoring-methodology
- QuantRocket — Sector Neutralization — https://www.quantrocket.com/codeload/fundamental-factors/fundamental_factors/Lesson09-Sector-Neutralization.ipynb.html
- OpenBB Platform docs (Context7 `/openbb-finance/openbb`)
