---
target: dashboard
total_score: 22
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 2
timestamp: 2026-08-11T09-32-53Z
slug: templates-dashboard-php
---
Method: dual-agent (A: design-review sub-agent · B: detector/browser-evidence sub-agent)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Spinner, inline validation, live alerts toggle are solid; no confirmation toast after a watchlist removal completes (chip just vanishes). |
| 2 | Match Between System and Real World | 3 | Domain vocabulary (AKUMULUJ, CVS Swing/Fund) fits the audience; international suffixes assume market literacy the target user is expected to have. |
| 3 | User Control and Freedom | 2 | Confirm modal on removal is good, but no undo, no way to shrink the watchlist, no escape from the 95-chip block short of scrolling past it. |
| 4 | Consistency and Standards | 2 | "Ostatnie analizy" (20 items) is a collapsed-by-default accordion; the far larger watchlist (~95 items) is never collapsible — an inverted application of the product's own progressive-disclosure pattern. |
| 5 | Error Prevention | 3 | Confirm-before-delete modal, format hint + example, client-side empty-input guard all verified live. |
| 6 | Recognition Rather Than Recall | 2 | Border-hue gives ambient state recognition without opening anything — good. But real numbers (CVS Swing/Fund) exist only in a hover tooltip. |
| 7 | Flexibility and Efficiency of Use | 1 | No keyboard shortcuts, no bulk actions, no on-page sort/filter. Live test: 8 Tab presses from load reached only the 7th chip's remove button. |
| 8 | Aesthetic and Minimalist Design | 2 | Token system is clean on its own; the watchlist section undoes it — ~95 items of identical visual weight fill the whole above-the-fold viewport. |
| 9 | Error Recovery | 3 | The one tested error path (empty submit) is plain-language, precisely placed, non-destructive. |
| 10 | Help and Documentation | 1 | No contextual "what is CVS Swing vs Fund" affordance near where the jargon first appears; `/model` exists but isn't linked from the dashboard. |
| **Total** | | **22/40** | **Acceptable (55%)** |

## Design Specificity Verdict

**Partially specific — the surface art is authored, the interaction chrome is not.**

CVS-specific and working: the animated candlestick background, recommendation-color coding applied consistently across chip borders and tooltip text, the dual Swing/Fund score convention, Polish financial vocabulary, international ticker suffixes. These root the page in "financial markets, contrarian valuation tool" and couldn't be dropped into an unrelated product unchanged.

Not specific: strip the color and ticker text away and the shell underneath — `.card`, `.btn--primary`, hamburger nav, a pill-shaped multi-tag input reused for scoring data — is a generic dark-mode SaaS admin template. The watchlist-chip pattern in particular reads as a stock "tag manager" component; nothing about its shape or interaction communicates "valuation signal" beyond border hue. The deterministic scan corroborates this at the code level: `detect.mjs` found **zero** markup-level anti-patterns in `templates/dashboard.php` itself — the page isn't breaking any known rule, it's just not saying anything product-specific in its structure.

## Deterministic Scan (Assessment B)

- `detect.mjs --json templates/dashboard.php` → **clean, exit 0**.
- `detect.mjs --json public/css/app.css public/css/components.css` → **2 findings** (`layout-transition`, both `transition: width` — `app.css:484` `.pillar-bar__fill`, `components.css:266` `.progress-bar__fill`). Both isolated thin fill-bars, not large layout regions — real matches, low practical severity.
- Browser evidence gathered against the live, authenticated production page (script-injection overlay step intentionally skipped — that flow targets a local dev server with CSP consent; screenshot + full accessibility-tree read + live keyboard-tab test were used instead).

## Overall Impression

The page's visual language (color-coded state, candlestick motif, disclaimer discipline) is genuinely on-brand and the analysis form itself is the best-designed moment on the page — calm, clear, single primary action. But the dashboard opens with roughly 95 undifferentiated watchlist chips occupying the entire above-the-fold viewport before that form is reachable, and this single structural choice is the source of nearly every other issue found: it fails 5 of 8 cognitive-load checklist items, it's the reason a keyboard user needs ~90+ Tab presses to reach the primary action, and it directly contradicts the owner's own stated Product Principle #3 ("golden-mean progressive disclosure, lead with the verdict, roughly three clicks to depth"). Both isolated assessments converged on this independently — one via heuristic/cognitive-load analysis, the other via live DOM/keyboard verification — which is a strong signal it's a real, not hallucinated, defect.

## What's Working

1. **State-encoded chip borders.** Mapping `reco--strong-buy/accumulate/reduce/avoid` to the same color used app-wide (verified live across NVDA/MU/TSLA) gives real at-a-glance recognition value — a legitimately strong, product-specific pattern.
2. **Guarded destructive action.** Removing a ticker requires a confirm modal rather than an instant delete — correct discipline for a real data-loss action, confirmed correctly hidden/shown via computed styles.
3. **Graceful, well-placed validation.** Empty-submit produces a plain Polish message right at the form, no reload, no console noise — confirmed live.
4. **Good patterns exist elsewhere on the same page** — the alerts toggle flips its own accessible name, and "Ostatnie analizy" correctly exposes `aria-expanded` and starts collapsed. The team clearly knows the right patterns; they just weren't applied to the watchlist.

## Priority Issues

**[P1] Watchlist wall front-loads ~95 undifferentiated chips before the primary action**
- **What**: `.watchlist-section` (`templates/dashboard.php` lines ~44–92) renders every tracked ticker as an equal-weight pill, always fully expanded, filling the entire above-the-fold viewport.
- **Why it matters**: Fails 5/8 cognitive-load checklist items (critical band) and directly contradicts `PRODUCT.md` Product Principle #3. A returning user checking "did anything change?" pays the same full-scan cost every visit regardless of how many tickers actually moved — which is exactly the manual-checking burden the Alerts feature already exists to remove.
- **Fix**: Collapse the watchlist behind a compact summary by default (e.g. "3 zmiany od ostatniej wizyty · 92 bez zmian"), mirroring the history accordion's own correct pattern; make the full grid expand-to-view rather than forced-past.
- **Suggested command**: `/impeccable distill`

**[P1] Watchlist tooltip content and per-item navigation are keyboard/screen-reader unreachable**
- **What**: `.watchlist-chip__tooltip` (company name + both CVS scores) shows only on `:hover` (`app.css:618`) with no `:focus`/`:focus-within` equivalent, and the chip wrapper carries no `tabindex` — confirmed via computed styles and a live keyboard-tab test (8 Tabs from load reached only the 7th chip's remove button). The ~95 chips also lack list semantics (`<ul>/<li>` or `role="list"`), unlike the `/analysis/{ticker}` links used correctly elsewhere on the same page. Only the "Usuń [TICKER]" button is focusable per chip — there is no way to *navigate to* a ticker from its chip, only delete it.
- **Why it matters**: The entire informational payload of each chip (company name, both CVS scores) is mouse-only. Combined with color-only state signaling on the visible chip face, a keyboard or screen-reader user can only destroy watchlist entries one at a time, never actually browse them — at 95 items this is the primary usage pattern, not an edge case.
- **Fix**: Make tooltip content reachable via `:focus-within` on a focusable wrapper (or better, promote a compact score readout onto the visible chip face so hover isn't required at all); wrap the chip collection in real list semantics; add a landmark/skip mechanism past the block.
- **Suggested command**: `/impeccable harden`

**[P2] Heading hierarchy skips a level**
- **What**: Confirmed live via accessibility tree: H1 "Panel analizy CVS" → H3 "Obserwowane" (skips H2) → H2 "Wprowadź symbole spółek" → H2 "Wyniki".
- **Why it matters**: Screen-reader users commonly navigate by heading level; a skipped level breaks that outline specifically at the section that most needs a clear landmark.
- **Fix**: Promote "Obserwowane" to H2, consistent with its siblings.
- **Suggested command**: `/impeccable harden`

**[P2] No sort/filter/grouping of the watchlist on the dashboard itself**
- **What**: The only way to sort/filter by score, recommendation, or sector is to leave `/dashboard` for `/screener`; the watchlist card has zero controls beyond per-chip removal.
- **Why it matters**: Depresses heuristic 7 (Flexibility/Efficiency) and compounds the wall-of-chips problem — the fix already exists in the product, just not where the overload actually occurs.
- **Fix**: Surface a lightweight inline sort (e.g. by recommendation severity or most-recently-changed) directly on the dashboard.
- **Suggested command**: `/impeccable layout`

**[P2] Muted text token fails WCAG AA contrast**
- **What**: `--c-muted: #5a7595` on `--c-bg: #07101e` computes to ≈4.0:1 — below the 4.5:1 AA minimum. Drives `.hint`, tooltip default-state text, and `.history-table th` labels.
- **Why it matters**: This is exactly the text a low-vision user needs to read to understand what they're looking at — the least-decorative, most-informational text on the page is what fails contrast. Note: the deterministic detector does not check computed contrast, so this only surfaced via the design-review pass — a real gap in what the 59-rule scan can catch.
- **Fix**: Lighten `--c-muted` roughly one step (toward `#6b87a8`+) until it clears 4.5:1 against both `--c-bg` and `--c-surface`.
- **Suggested command**: `/impeccable harden`

**[P2] Inconsistent application of the product's own progressive-disclosure pattern**
- **What**: "Ostatnie analizy" (20 rows) is collapsed by default with `aria-expanded`; "Obserwowane" (~95 items, ~5x larger) has no equivalent collapse control.
- **Why it matters**: The product already has, and correctly uses, the exact UI pattern this problem needs — applied to the smaller, less-overwhelming list instead of the one that needs it.
- **Fix**: Give the watchlist the same collapsible affordance, with collapsed state remembered per user.
- **Suggested command**: `/impeccable layout`

## Persona Red Flags

**Alex (Power User)**
- No skip mechanism past the watchlist — reaching the ticker textarea by keyboard alone costs roughly 90+ Tab presses.
- No bulk actions: pruning 20 stale tickers means 20 separate click → confirm → click cycles.
- No on-page sort/filter — must leave for `/screener` entirely.
- No manual "re-score now" trigger on the dashboard (only nightly cron per `CLAUDE.md`).

**Sam (Accessibility-Dependent User)**
- Tooltip content (company name + both scores) is categorically unreachable via keyboard/screen reader — not just harder, actually unreachable.
- Recommendation state is color-only on the visible chip face, no text/icon fallback.
- Muted text (~4.0:1) undershoots WCAG AA on form hints and table headers.
- ~95 consecutive "Usuń [TICKER]" announcements before any other page content, with a single skipped-level H3 as the only landmark.

**Marta — "the daily portfolio watcher"** (project-specific, derived from `PRODUCT.md`: a self-directed investor tracking a dozen-plus tickers who wants to be *signaled* rather than having to check manually)
- Nothing on the dashboard distinguishes "changed since your last visit" from "unchanged" — every chip carries identical visual weight every day, so Marta must personally re-scan all ~95 borders to find the 1–2 that moved. This is precisely the burden the Alerts feature exists to remove, yet the dashboard layout doesn't reuse that same "genuine state change" signal to promote or badge the tickers that just flipped.
- No way to collapse the list once she's confirmed nothing moved — every visit costs the same flat cognitive tax whether 0 or 10 tickers changed, which works against the product getting cheaper to use as she becomes a habitual daily user.

## Minor Observations

- Live accessibility-tree read of the history table showed ticker **"GD" in two consecutive rows** with identical score (62.8) and identical date (11.08.26) — either a duplicate log entry or an unindicated same-day re-analysis; worth a quick data-integrity check independent of design.
- International ticker suffixes (`.WA`, `.KS`, `.DE`, `.PA`, `.SW`, `.MI`, `.F`, `.L`) appear as bare text with no exchange/flag glyph — minor gap, acceptable for the stated audience.
- `templates/dashboard.php` mixes inline `style="…"` attributes with the project's own component classes — not user-visible today, but a consistency smell likely to compound as the page grows.
- Alerts toggle relies on emoji (🔔/🔕) as the primary state cue; a `title` fallback exists, but there's no monochrome icon alternative if emoji rendering varies.
- The mandatory disclaimer is duplicated verbatim in both the footer and `.disclaimer-inline` — correct per the legal requirement, reads as intentional redundancy, not an oversight.
- `transition: width` on `.pillar-bar__fill` / `.progress-bar__fill` (2 detector findings) — real matches, but on isolated thin fill-bars rather than large layout regions, so practical jank risk is likely low; still a one-line fix (`transform: scaleX()`).

## Questions to Consider

- What if the watchlist opened collapsed by default — the way the history accordion already does — with only a one-line "3 changes since your last visit, 92 unchanged" summary, and the full grid became something you expand *into* rather than something you're forced *past*?
- The Alerts subsystem already knows exactly which tickers just had a genuine state change — why doesn't the dashboard layout use that same signal to visually promote the 2-3 tickers that actually moved, instead of giving all ~95 equal billing every day?
- If "roughly three clicks to deep data" is the owner's own bar, what's the actual cost today for a returning user whose task is just "did anything change" — and is scanning 95 uniform chips really cheaper than a click would be?
- Does the dashboard want the watchlist to be a lightweight reference (collapsible, secondary to the entry form), or does it want the grid to *be* the star of the page (in which case it needs to behave like a real status board — sortable, filterable, badge-able — rather than an oversized tag-input)?
