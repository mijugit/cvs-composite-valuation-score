---
target: screener
total_score: 30
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 2
timestamp: 2026-08-11T11-21-20Z
slug: templates-screener-php
---
Method: dual-agent (A: design-review sub-agent · B: detector/browser-evidence sub-agent)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Sort arrow is hardcoded `↓` for whichever column is active regardless of true direction — confirmed in source (`templates/screener.php:36`) and live (`?sort=ticker` shows "TICKER ↓" while the list is ascending). |
| 2 | Match Between System and Real World | 3 | Good Polish with inline jargon tooltips, but "Rynek" mixes friendly labels ("GPW (Warszawa)") with 5 bare, untranslated exchange suffixes (.F/.MI/.OL/.SW/.TO). |
| 3 | User Control and Freedom | 3 | Reset, checkbox toggle-off, and empty-state "Wyczyść filtry" all work; but no column's sort direction can be reversed — clicking an active header is a no-op. |
| 4 | Consistency and Standards | 3 | Chip/tooltip patterns reused consistently from the rest of the app. |
| 5 | Error Prevention | 3 | `min_swing` is server-clamped 0–100 and `sort`/`atr` whitelisted, but an out-of-range number is silently corrected with no visible feedback. |
| 6 | Recognition Rather Than Recall | 4 | Every non-obvious column/filter carries a specific, plain-Polish ⓘ tooltip, keyboard-reachable; ticker hover-hints surface company data without navigating away. Strongest heuristic on the page. |
| 7 | Flexibility and Efficiency of Use | 2 | Live keyboard-navigable search autocomplete is a real accelerator, undercut by no sort-reversal, no bulk actions, and 16 non-actionable `tabindex="0"` hint icons interleaved with real controls. |
| 8 | Aesthetic and Minimalist Design | 3 | Column density is appropriate for a screener; filter panel gives all 10 controls equal weight; Rekomendacja select clips its own longest option. |
| 9 | Error Recovery | 3 | Both empty states are plain-language with a working recovery action; no equivalent feedback for the silently-clamped `min_swing`. |
| 10 | Help and Documentation | 3 | No dedicated help affordance, but 16 inline tooltips function as distributed contextual help. |
| **Total** | | **30/40** | **Good (75%)** |

## Design Specificity Verdict

**Specific in content and vocabulary, generic in filter-control chrome.** The table is unmistakably CVS-authored: dual CVS Swing/Fund columns, golden-signal stars, ATR accumulation-zone chips with decision-oriented tooltips, an FV margin column that coaches the user to cross-check pillars, a "w portfelu" badge that turns amber/red on conflict with the model's own recommendation. None of that is screener boilerplate. The filter *panel* is the opposite: five plain `<select>`s, one number input, two checkbox pills, Filtruj/Reset — visually and interactively indistinguishable from any generic admin-table filter bar.

Deterministic scan: `detect.mjs` found **zero** anti-patterns across `screener.php`, `app.js`, `app.css`, `components.css`, `tokens.css`. Caveat worth knowing: `.php` files route through the detector's regex-fallback engine, not the full DOM-aware HTML engine — so "clean" here means "no line-pattern issues," not "structurally audited." The real structural/accessibility findings below came entirely from live browser inspection, not the scan.

## Overall Impression

The table itself earns its density — every column is real product vocabulary with a specific tooltip, and the live search + keyboard autocomplete is genuinely good power-user tooling. But two defects undercut the page's actual job of "scan the model's scored universe in one place": the header row scrolls away for good after ~2 rows (confirmed independently by both assessments — one via computed-style + scroll test, one via five sequential screenshots), and the sort arrow lies about direction with no way to reverse it. Both are P1s that touch the page's core task, not cosmetic issues.

## What's Working

1. **Recognition-first tooltip system** — every non-obvious column/filter carries a specific, plain-Polish ⓘ hint (e.g. FV's tooltip explicitly coaches checking Wycena/Jakość when margin is negative despite a buy signal), keyboard-reachable via Tab.
2. **Live search with ranked, keyboard-navigable autocomplete** — client-side, no round trip, ticker-prefix hits ranked above name-substring hits, full Arrow/Enter/Escape support confirmed live.
3. **Never color-alone signal encoding** — trend/recommendation/ATR/FV chips all pair a symbol and text with color, confirmed in source and visually.
4. **Real semantic `<table>`** — genuine `<thead>`/`<tbody>`/`<th>` markup, not a div-grid; zero console errors across load, filter-change, and sort interactions.

## Priority Issues

**[P1] No sticky table header on a 105-row single-page table**
- **What**: `#screener-table thead` has no `position: sticky` anywhere in `app.css` (computed `position: static`, confirmed via `getComputedStyle`); scrolls away after ~2 rows and never returns, confirmed by five sequential screenshots across the full 105-row scroll.
- **Why it matters**: Column meaning for an abbreviation-heavy table (Trend d/d, ATR, FV) is lost for ~90% of the row count — the single biggest failure mode for the page's actual job of scanning the scored universe.
- **Fix**: `position: sticky; top: 0; background: var(--c-surface);` on `#screener-table thead`, scoped narrowly so it doesn't affect other tables reusing shared table classes.
- **Suggested command**: `/impeccable polish`

**[P1] Sort arrow is inaccurate and direction can't be reversed**
- **What**: `templates/screener.php:36` — `$arrow = $col === $sort ? ' ↓' : '';` renders the same down-arrow for whichever column is active regardless of true order. `ScreenerRepository::getFiltered()`'s `usort()` (line ~201) confirms every column has exactly one fixed direction (`ticker` ascending via `strcmp`, everything else descending) with no parameter to flip it; clicking an already-active header is a no-op.
- **Why it matters**: For `ticker` specifically the arrow is backwards (shows "↓" while ascending); for every other column direction can never be reversed — a user wanting "worst CVS Fund first" has no path there.
- **Fix**: Add a `dir` param (asc/desc) threaded through `ScreenerController` → `ScreenerRepository::getFiltered()`'s `usort` → the `$sortLink`/arrow logic; clicking an active header flips it; render a direction-correct glyph.
- **Suggested command**: `/impeccable clarify`

**[P2] Filter panel exposes 10 simultaneous decision controls with no primary/advanced split**
- **What**: Szukaj, Rekomendacja, Złoty sygnał, Sektor, Rynek, Strefa ATR, Min CVS Swing, two checkbox toggles, and Filtruj/Reset all render unconditionally, above the fold, equal visual weight — confirmed live and in source.
- **Why it matters**: Squarely in "8+ items = overloaded" territory and in tension with `PRODUCT.md` Principle #3 (golden-mean progressive disclosure), even granting a screener legitimately needs more filter surface than a dashboard.
- **Fix**: Keep Szukaj + Rekomendacja + Sygnał always visible (core model vocabulary); collapse Sektor/Rynek/Strefa ATR/Min CVS Swing/checkboxes behind a "Więcej filtrów" disclosure — same accordion pattern already used to fix the dashboard's watchlist wall.
- **Suggested command**: `/impeccable distill`

**[P2] Five filter `<select>`s have visible label text with no programmatic association**
- **What**: Confirmed in source (`templates/screener.php:209-262`) and via live DOM query: Rekomendacja/Złoty sygnał/Sektor/Rynek/Strefa ATR each have a `<label>` element with real text, but no `for`/`id` pairing and the select isn't wrapped in the label — so the text is visible but not programmatically tied to its control. Search and Min CVS Swing (the two inputs) are correctly associated.
- **Why it matters**: A screen reader landing directly on any of these five selects announces only "combo box" with no name — the majority of the filter bar's interactive controls are effectively unlabeled for assistive tech, despite looking labeled to sighted users.
- **Fix**: Add matching `id` on each `<select>` and `for` on its `<label>` (five one-line changes).
- **Suggested command**: `/impeccable harden`

**[P2] "Rynek" filter mixes human labels with untranslated exchange codes**
- **What**: Confirmed via live DOM: 6 of 11 market options carry a friendly Polish label ("USA (NYSE/NASDAQ)", "GPW (Warszawa)"...); 5 render as bare, unexplained suffixes — `.F`, `.MI`, `.OL`, `.SW`, `.TO` — no label at all.
- **Why it matters**: Contradicts Heuristic 2 for the audience `PRODUCT.md` describes (self-directed investors, not assumed domain experts) and is internally inconsistent — the control sometimes explains itself, sometimes doesn't.
- **Fix**: Fill the missing suffix→market labels in the existing market-resolver config (same mechanism already used for the other 6).
- **Suggested command**: `/impeccable clarify`

**[P3] `<th>` elements have no `scope="col"`**
- **What**: Confirmed in source and live DOM query — all 11 `<th>` are plain, no `scope` attribute.
- **Why it matters**: For a single-header-row table most screen readers still resolve column association via the default algorithm, so this is lower real-world severity than the label gap above — but it's the explicit best-practice for table navigation commands and a one-line-per-header fix.
- **Fix**: Add `scope="col"` to each `<th>`.
- **Suggested command**: `/impeccable harden`

**[P3] "Rekomendacja" select clips its own longest option**
- **What**: Confirmed by zoomed screenshot — once "⬆⬆ SILNE KUPUJ" is selected, the rendered control shows "↑↑ SILNE KUPI", trailing "UJ" cut off behind the dropdown caret.
- **Why it matters**: Small, but it's the widest of five options and likely a common filter choice.
- **Fix**: Right padding on `.form-group select`, or trim option font-size slightly.
- **Suggested command**: `/impeccable polish`

## Persona Red Flags

**Alex (Power User)**
- Cannot reverse sort on any column; wanting "worst first" forces an unrelated-column workaround.
- No bulk actions — adding several promising tickers to the watchlist means clicking into up to 105 rows one at a time.
- 16 non-actionable `tabindex="0"` hint icons interleaved with ~15 real controls before reaching data, confirmed via live Tab traversal.
- No sticky header turns "scan 105 rows fast" into repeated scroll-up-to-reorient cycles.

**Sam (Accessibility-dependent user)**
- Tooltips are keyboard-focusable and announce on focus — a genuine positive.
- But that same mechanism produces 16 extra linear tab stops before the first data row.
- Five filter selects effectively unlabeled for assistive tech (P2 above); no `scope="col"` weakens table-navigation commands on an 11-column table.
- Bare Rynek codes (".F", ".OL") are read literally with zero disambiguating context.

**Marek — "the periodic dozen-ticker investor"** (project-specific, derived from `PRODUCT.md`: watches a dozen-plus tickers, wants to decide for himself, wants to be signaled rather than checking manually)
- The two columns his workflow depends on most — Trend d/d and Trend w/w — have no sort link at all; he can eyeball "did anything change" but can't rank by it.
- No saved-filter or default-view persistence — filters live only in the URL query string, confirmed in `ScreenerController::index`. Every periodic visit means re-applying his usual filters from scratch, which cuts against "signaled proactively" being the product's own positioning.

## Minor Observations

- "1 spółek" is grammatically wrong for exactly one result (confirmed live under a narrow market filter) — should be "1 spółka."
- "Wyniki" (earnings-timing) column has no sort link.
- Min CVS Swing's placeholder "0–100" disappears once a value is typed, no persistent range hint.
- FV shows "—" for a non-trivial slice of the universe (confirmed: LPP.WA, VST, BDX.WA, and others) — tooltip explains why, but the bare header sets an "always a number" expectation broken often enough to notice.
- `--c-muted` was already fixed for contrast in the earlier dashboard pass (tokens.css comment documents it) — this table reuses that same token for headers/tooltips, so it inherits the earlier fix; raw contrast values sampled here (muted ≈5.2:1, red trend ≈5.1:1, green ≈8.4:1, yellow ≈12.4:1) all look comfortably AA-compliant.
- Horizontal overflow at mobile width (517px overflow at 375px container) is real but has a working `overflow-x: auto` scrollbar — not silently broken, just no sticky-first-column or "scroll for more" affordance.

## Questions to Consider

- If the sort arrow can't yet tell you which direction you're looking at, should it render at all until it's accurate?
- The screener's whole value is scanning the scored universe in one table — what does that promise mean once the header has scrolled out of view for ~90% of the rows?
- Principle #3 asks for "roughly three clicks to depth" — is the third click (into `/analysis/{ticker}`) reachable calmly if the second click first requires surviving ten simultaneous filter decisions and an unlabeled market code?
- Marek checks a dozen tickers periodically, not intraday — what would this page feel like if it remembered his last filter state instead of resetting to all 105 every time he opens it?
