# Screener to Portfolio Linkage (S-04) — Plan Brief

> Full plan: `context/changes/screener-to-portfolio-link/plan.md`

## What & Why

Connect the screener and portfolio views so the user can instantly see which screener
recommendations they already hold (and spot conflicts), and which positive signals they
are missing. This closes the "compare held vs recommended" gap identified in FR-015
and makes the educational sandbox more actionable.

## Starting Point

Screener rows come purely from `cvs_snapshots` with no awareness of `portfolio_holdings`.
The portfolio page shows holdings only — no signal about what the screener currently
recommends buying. There are no visual "held" markers, no cross-references, no dedicated
comparison view.

## Desired End State

Every screener row shows a "w portfelu" badge when the ticker is currently held; rows
where the model now says REDUKUJ or UNIKAJ show the badge in amber/red to flag the
conflict. Below the holdings table on the portfolio page, a new "Polecane przez screener"
section lists tickers with SILNE KUPUJ or AKUMULUJ that are not yet held — sorted by
CVS Swing, hidden when the screener has no qualifying rows.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Where the comparison lives | Both screener + portfolio (not a dedicated page) | Maximum educational value in both reading directions without requiring navigation | Plan |
| Data enrichment method | PHP-side in ScreenerController (getCurrentHoldings → map) | Zero SQL changes to ScreenerRepository; one extra query at ~30 rows is negligible | Plan |
| Held-marker UX | Badge pill + row highlight; conflict colour for negative reco | Scannable at a glance, consistent with existing signal-pill pattern | Plan |
| "Polecane" definition | quality_gate=1 AND reco ∈ {SILNE KUPUJ, AKUMULUJ} | Actionable short list; NEUTRALNIE/REDUKUJ/UNIKAJ add noise without signal | Plan |
| Conflict handling | Badge colour change (warn/danger) — no separate alarm section | Simplest UX; dedicated alarm section is S-05/S-06 scope | Plan |
| Empty state | Hide section entirely when no qualifying screener rows | No placeholder clutter; screener may be empty on first boot | Plan |
| Tests | PHPUnit + SQLite in-memory for new PortfolioRepository method | Consistent with S-03 pattern; catches reco/model_version filter regressions | Plan |

## Scope

**In scope:**
- `PortfolioRepository::getScreenerRecommendationsNotHeld()` method + unit tests
- "w portfelu" badge + row highlight in screener template
- Conflict colour badge (amber/red) when held + REDUKUJ/UNIKAJ
- "Polecane przez screener" section on portfolio page (hidden when empty)

**Out of scope:**
- Schema changes
- Screener filter for "show only held"
- Alarm/alert section for conflicting positions (future S-05/S-06)
- ATR enrichment in the portfolio section
- Per-user customisation of the recommended section

## Architecture / Approach

ScreenerController already knows `$liveModelVersion`; it gains a second DB call for
`getCurrentHoldings()` → builds `$heldTickersMap` → passes to template.
PortfolioController already builds `$holdings` → derives `$heldTickers` array → calls
new `PortfolioRepository::getScreenerRecommendationsNotHeld()` which self-joins
`cvs_snapshots` (same pattern as `getCurrentHoldingsWithPrice()`). No new tables,
no new routes.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Data layer | `getScreenerRecommendationsNotHeld()` + 5 unit tests | Shadow-row bug if model_version/origin filter omitted |
| 2. Screener markers | Badge pill + row highlight + CSS | PHPStan may flag PortfolioRepository cross-module use in ScreenerController |
| 3. Portfolio section | "Polecane" section below holdings | Empty-state condition must hide entire card, not render blank |

**Prerequisites:** S-01 (portfolio_holdings table exists), S-02 (holdings populated)
**Estimated effort:** ~2 sessions across 3 phases

## Open Risks & Assumptions

- `portfolio_holdings` is assumed to have `quantity > 0` rows (S-01/S-02 must be live).
- Shadow-row duplication (lesson commit 442689d) requires both `model_version` and
  `origin='RESCORE'` filters in the new repository method.
- reco strings contain Unicode arrows — matching with `str_contains` on prefix is
  more robust than exact equality.

## Success Criteria (Summary)

- Held tickers visible at a glance in screener with conflict colouring when model signals sell.
- Portfolio page surfaces non-held tickers the screener currently rates SILNE KUPUJ or AKUMULUJ.
- Section hidden (no empty card) when screener has no qualifying rows.
