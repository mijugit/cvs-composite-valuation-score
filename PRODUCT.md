# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Self-directed individual investors who want to decide for themselves rather than blindly follow Wall Street analyst recommendations, but don't want to spend hours pulling financial data, computing ratios, and cross-checking every analyst call by hand. Typically watches a dozen-plus tickers, keeps a personal watchlist, and wants to be signaled before an opportunity is missed rather than having to check manually.

Registration is open to a wider, not-personally-known circle of users (not limited to the owner's immediate circle) — an account is required for any functionality (no anonymous access). A gated PRO tier (admin-issued access codes) unlocks the AI divergence-analysis feature on top of the free core.

Not built for day traders and not a source of ready-made investment advice — explicitly positioned as a personal analytical tool the user interprets themselves.

## Product Purpose

Given a ticker, CVS pulls live financial data and returns one composite score (0–100, with a recommendation band from SILNE KUPUJ to UNIKAJ) that answers "is this company cheap or expensive relative to its sector, its own history, and its business quality — right now, in seconds." Beyond the score, the product exists to explain *why* the model's read diverges from what Wall Street analysts are saying, and to keep watching the user's tickers so signals surface proactively instead of requiring a repeat manual check.

## Positioning

CVS is deliberately built as a **contrarian** tool: it is a bound product commitment that the model is allowed to disagree with the crowd. When analyst consensus says "Buy" on a high-momentum, richly-valued stock, the model can say "Reduce" — because it scores hard numbers (sector-relative valuation, momentum vs. the market, quality signals), not narrative. That divergence between "what the model says" and "what analysts say" is treated as the product's core informational payload, not noise to be smoothed away. The AI narrative layer that explains *why* the divergence exists — grounded strictly in the model's own computed data, never guessing — is the mechanism a generic stock-screener or a plain analyst-aggregator page does not offer.

## Operating Context

- User enters a ticker (or several) and gets two parallel scores from three weighted pillars (Valuation, Momentum, Quality): a **Swing** read (1–4 months, momentum-led) and a **Fundamental** read (6–12 months, valuation-led) — both shown together because the horizon changes the interpretation.
- A "golden signal" (⭐/⭐⭐) marks alignment between the two horizons.
- Users maintain a **watchlist**; a daily cron re-scores it and feeds the **screener** (sortable/filterable table across the watched universe) and the **track record** view (historical hit-rate of past recommendations, accumulating over time).
- **Alerts**: email notification only on a genuine state change (recommendation flip or a new golden signal) — deduplicated so routine days stay silent.
- **PRO tier**: an admin issues a per-user access code (manual gate, not a role); PRO unlocks an on-demand AI narrative ("Generuj analizę AI") explaining the CVS-vs-analyst divergence for a ticker. Generated analyses are cached and shared across all logged-in users for 7 days, with the generation date always visible, to control API cost.
- Admin-only panels manage PRO codes and sector/peer-group median maintenance that the Valuation pillar depends on.

## Capabilities and Constraints

- PHP 8.2 monolith, no framework — a deliberate choice for a solo-maintained project on shared hosting (Cyber_Folks). MySQL via PDO, plain-PHP templates, PSR-4 (`CVS\`).
- The scoring core is **deterministic**: identical financial inputs must always yield the identical score and recommendation. Weights, thresholds, and sector benchmarks live in config, never hardcoded.
- Market data comes from Yahoo Finance over cURL (free, public endpoints), cached per-session (default ~1h TTL) — a single-vendor dependency the product is aware of.
- The AI narrative layer runs on the Claude API. It is grounded in the model's own computed data (no open-web access, no guessing) and is built to fail gracefully: an AI/API failure must never break the underlying CVS result or the page.
- Every CVS result must carry a fixed disclaimer (see Brand Commitments) — this is a non-negotiable product/legal constraint, not a style choice.
- Deployment is manual (SSH + git pull) to shared hosting; there is no CI/CD gate, so releases lean on the offline PHPUnit suite (400+ tests) and static analysis (PHPStan level 6) rather than staging environments.

## Brand Commitments

- Product name: **CVS — Composite Valuation Score**. Live at cvs.timeflow.fun.
- Every CVS result must display, verbatim: *"Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie."*
- UI copy (labels, errors, all user-facing text) is Polish; code (identifiers, comments) is English — a fixed convention, not a translation gap.

## Evidence on Hand

- Live production app: https://cvs.timeflow.fun.
- 400+ offline PHPUnit tests, PHPStan level 6 clean — cited internally as evidence of engineering rigor, not user-facing proof.
- The **track record** feature is itself the product's evidence-generation mechanism (does a KUPUJ signal actually precede a rise?) — it is real but still young; historical hit-rate data accumulates over time and should not be overstated as mature yet.
- No formal accessibility audit or WCAG conformance claim exists — do not assert one.

## Product Principles

1. **Determinism over vibes.** The core CVS score is a pure function of the input financials — no randomness, no hidden state, no drift between two runs on the same data. Trust in the signal depends on this.
2. **Numbers over narrative, on purpose.** The model is allowed — expected — to disagree with analyst consensus. Divergence from the crowd is a feature to surface clearly, never something to soften toward agreement.
3. **Golden-mean progressive disclosure.** Always lead with the single most important verdict (score + recommendation); deeper data (pillar breakdowns, raw financials, history) should be reachable but not front-loaded — the owner's standing rule of thumb is roughly "three clicks to the deep data." Avoid both over-simplification and information overload; the interface should never feel like it's hiding the verdict *or* burying the user in numbers.
4. **AI explains, never invents, never breaks the page.** The AI narrative layer is grounded strictly in computed model/analyst data; on any failure it degrades gracefully rather than taking the core product down with it.
5. **Alerts respect attention.** Notifications fire only on genuine state change — never routine/repetitive noise — to keep the signal-to-noise ratio high enough that users keep trusting (and reading) alerts.
