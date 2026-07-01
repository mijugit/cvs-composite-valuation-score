# 01 — Domain Distillation: CVS

Method: compared business vocabulary in `context/foundation/prd.md` / `shape-notes.md` /
`roadmap.md` against actual class/field names in `src/`, looking for the same word meaning
different things (or different words meaning the same thing) across contexts — the DDD
"ubiquitous language" check from lesson M4L5.

## Referential glossary (terms that ARE consistent — keep these)

| Term | Meaning | Where it lives in code |
|---|---|---|
| CVS Score | 0–100 composite valuation score, two modes (Swing/Fundamental) | `CVSResult::cvs()`, `CVSModel::calculate()` |
| Quality Gate | binary pass/fail filter run before scoring | `QualityGate`, `QualityGateResult` |
| Snapshot | one CVS result for one ticker on one day, persisted | `cvs_snapshots` table, `CvsSnapshotRepository` |
| Watchlist | the set of tickers a user follows | `WatchlistController`, `watchlist` table |
| Track Record | historical accuracy of CVS vs. later price | `TrackRecordController`/`Repository` |
| PRO access | per-user code gating AI-generation calls | `ProGate`, `ProRepository` |
| Peer group / sector median | benchmark population for Valuation pillar | `PeerMedianRepository` |

These match well — no rename needed.

## ⚠️ Collision #1 — "Model" means three different things

The single English word **"model"** is overloaded across three unrelated concepts, sometimes in
the *same* config layer:

1. **The CVS model** — informal shorthand for "the whole scoring engine/methodology" (what the
   README and PRD mean by "model"). No single class carries this name; it's the product concept,
   not a code symbol.
2. **`model_version`** (`config/cvs-weights.php:18`, `'model_version' => '4.0'`) — the *scoring
   methodology version number* (4.0 live, 3.1/3.2 shadow). This is what gets stamped on every
   snapshot row and filtered on in every "latest snapshot" read.
3. **`config['model']`** (`config/ai.php:22`, `$_ENV['AI_MODEL']`) — the **Claude LLM model ID**
   (e.g. a Sonnet build) used by `ClaudeClient` for AI-divergence narratives. Nothing to do with
   scoring at all.

**Why it matters:** a sentence like *"which model produced this snapshot"* is genuinely ambiguous
without context — CVS scoring model version, or LLM model, or "the model" as in the whole product.
`config/ai.php` and `config/cvs-weights.php` sit one directory apart and each define their own
"model" key with a completely different referent. This is exactly the fintech "Account" pattern
from the lesson (`AccountService`/`AccountStatus`/`AccountClosed` silently spanning three
contexts) — cheap to rename now, expensive once a fourth "model" concept (e.g. a future ML
calibration model, already scoped in Phase 7/8) lands and collides with both.

**Suggested resolution (not yet applied):** rename `config/ai.php`'s key to `llm_model` (or
`claude_model`) at the config/env level; keep `model_version` as-is since it's already
disambiguated by the `_version` suffix and is the one term with real cross-file consistency today.

## ⚠️ Collision #2 — "Alert" spans two different trigger mechanisms

```
src/Alerts/AlertController.php    src/Alerts/PriceAlertRepository.php
src/Alerts/AlertRepository.php    src/Alerts/PriceAlertService.php
src/Alerts/AlertService.php
```

`AlertService` fires when a watchlisted ticker's **recommendation or golden signal changes**
(state-transition alert, deduplicated via `alert_sent`). `PriceAlertService` fires when a
ticker's **price enters/exits an ATR execution zone** (a completely different trigger: price
threshold crossing, not a scoring-state change). Both live in the same `Alerts\` namespace and
both are user-facing as "🔔 alerts" in the UI — but a user (or a future contributor) hearing
"turn on alerts for AAPL" cannot tell which mechanism is meant without reading the toggle's label
carefully. The code already partially disambiguates this (the `Price` prefix), but the *spoken*
domain language ("alert") does not.

## ⚠️ Collision #3 — `goldenSignal` value `'watchlist'` overloads the `Watchlist` feature name

`CVSResult::$goldenSignal` (`src/CVS/CVSResult.php:28`) takes the value **`'watchlist'`** to mean
*"fundamental score qualifies (≥58) but swing doesn't yet — worth watching for a swing entry"* —
a scoring-engine concept. This is a different thing from **the Watchlist feature**
(`WatchlistController`, the set of tickers a user follows). `$goldenSignal === 'watchlist'` reads,
at a glance, as if it were about the watchlist feature; it is not — a ticker can have
`goldenSignal: 'watchlist'` whether or not the user has actually added it to their watchlist.
Same word, same repo, two unrelated referents.

**Suggested resolution (not yet applied):** rename the golden-signal value from `'watchlist'` to
something like `'fund_only'` or `'setup'` to stop it colliding with the actual Watchlist feature
name. Deferred — this is a stored/serialized value (`cvs_snapshots`, cached AI prompts), so the
rename has a real migration cost that a two-page architecture report is the wrong place to
absorb; flagged here for the next refactor pass instead of the DDD-notes pass.
