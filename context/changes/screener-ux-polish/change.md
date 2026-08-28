---
change_id: screener-ux-polish
title: Porządki UX — menu, zwijany panel filtrów, czytelne wykresy z zoomem, 3-way NAV comparison
status: implemented
created: 2026-08-28
updated: 2026-08-28
archived_at: null
---

## Notes

Powstało jako odnoga sesji [[cvs-ticker-logo-cache-plan]] (kontekst:
`context/changes/ticker-logo-cache/`) — user po zerknięciu na wdrożone
zmiany logo poprosił o "kilka drobnych zmian" frontendowych, niezwiązanych
z logo. Zrobione bez formalnego /10x-plan (mały, dobrze opisany zakres,
bezpośrednio przez impeccable-style fixy + weryfikacja w przeglądarce).
Commit `c3d3c26`, wdrożone na `cvs.timeflow.fun` tego samego dnia.

### 1. Menu

`templates/layout.php`: kolejność `Screener → Analizy` (było
`Panel → Screener`), "Panel" przemianowany na "Analizy". Uzasadnienie
usera: screener używany częściej niż dodawanie nowych analiz.

### 2. Zwijany panel filtrów na /screener

Cały `.card.screener-filter-card` (nie tylko istniejący nested "Więcej
filtrów") owinięty we wspólny `.accordion` komponent (ten sam mechanizm co
"Więcej filtrów" i dashboard watchlist — `app.js`'s generyczny
`.accordion__toggle` handler), zwinięty domyślnie. Auto-expand gdy
jakikolwiek filtr (podstawowy lub zaawansowany) już aktywny — ten sam wzorzec
co istniejący `$hasAdvancedFilter`, nowa zmienna `$hasAnyFilter`.

**Realne ryzyko znalezione i naprawione**: `.accordion { overflow: hidden }`
obcinałoby `.ac-dropdown` (autocomplete wyszukiwarki tickera,
`position: absolute`) — dokładnie ten sam bug co już udokumentowany w
`app.css` dla `.analysis-form-wrapper`/`.screener-filter-card` z-index fix.
Naprawione scoped override: `.screener-filters-accordion { overflow: visible }`.
Zweryfikowane na produkcji — dropdown "AAPL — APPLE INC." renderuje się
poprawnie po rozwinięciu panelu.

### 3. Czytelne wykresy z zoomem (CVS history / trajektoria)

`renderCvsNavChart()` (`public/js/app.js`) — już istniejąca funkcja
używana przez wykresy portfeli (czysty styl: brak kropek, crosshair na
hover, zoom modal) — rozszerzona o:
- `opts.yMin`/`opts.yMax` — stały zakres osi Y (0-100 dla wyników CVS)
- `opts.showLegend` — ukrycie legendy dla wykresów jednoseriowych
- `opts.dashPatterns` — per-label wzorzec kreskowania (patrz punkt 4)

Dwa miejsca przepisane z ręcznej konfiguracji Chart.js na
`renderCvsNavChart()`:
- `templates/track-record-ticker.php` — "Historia wyników CVS" (Swing +
  Fundamentalny). Wcześniej: grube kropki (`pointRadius: 4`), **brak zoomu
  w ogóle**. Teraz: czysty styl + dodany `.chart-zoom-target` wrapper +
  zoom modal (skopiowany wzorzec z `/analysis`).
- `templates/analysis.php` — blok "Trajektoria CVS" (90 dni). Zoom już był
  wired (`.chart-zoom-target`), ale gęste kropki (`pointRadius: 2` × 90
  punktów) zaciemniały wykres — to była dosłowna skarga usera. Sam render
  przepisany na `renderCvsNavChart()`, wrapper zoom bez zmian.

### 4. 3-way NAV comparison na wszystkich stronach portfeli

`templates/partials/wallet-nav-chart.php`: paleta S&P 500 / Nasdaq 100
zmieniona na szarą rodzinę (`--c-muted` terytorium), + `dashPatterns`
(S&P500 zwykły dash `[6,4]`, Nasdaq100 dash-dot `[6,3,1,3]`) — odróżnia
benchmarki od kolorowych linii portfeli.

`WalletNavChartService`'s 4. parametr konstruktora (`LlmGeminiCycleRepository`)
był **świadomie opcjonalny** od czasu `llm-gemini-wallet` (patrz stary
komentarz w tym pliku i w `LlmGeminiController.php`) — tylko `/llm-gemini`
dostawał pełne porównanie 3 portfeli, `/portfolio` i `/llm-free` widziały
tylko siebie + drugi portfel bazowy. User chciał to ujednolicić: wszystkie
3 strony portfeli powinny pokazywać identyczne porównanie. `PortfolioController`
i `LlmFreeController` teraz też przekazują `LlmGeminiCycleRepository`.
Parametr zostaje nullable w sygnaturze (wygoda testów/backward-compat), ale
w praktyce każdy z 3 callerów go już przekazuje.

## Weryfikacja

Na produkcji (Chrome, konto usera), 2026-08-28:
- `/screener` — nowa kolejność menu, panel zwinięty, po rozwinięciu
  autocomplete wyszukiwarki działa (nie ucięty)
- `/track-record/SNDK` — czysty wykres (bez kropek) + zoom modal działa
- `/analysis/000660.KS` — czysta trajektoria (bez kropek) + zoom modal działa
- `/llm-free` — 3 portfele (Bazowy/Free/Gemini) + 2 szare przerywane
  benchmarki (S&P 500 dash, Nasdaq 100 dash-dot)

Automatyczna weryfikacja: `vendor/bin/phpunit` 1314/1314, `composer stan`
czysty, `php -l` czyste na wszystkich zmienionych plikach.
