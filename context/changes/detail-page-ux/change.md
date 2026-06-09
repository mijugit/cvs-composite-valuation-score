---
change-id: detail-page-ux
title: "X-03: UX strony detalu — radar + wykres side-by-side, rozmiar radaru"
status: implemented
created: 2026-06-01
updated: 2026-06-01
roadmap_ref: X-03
prd_refs: []
---

# X-03 — Detail Page UX Improvements

Drobne ulepszenia UX na stronie `/analysis/{ticker}`.

**Zmiany:**
1. **Radar max-width** — zmniejszony z fullwidth (responsywny, do ~960px) do 220px
   żeby nie zajmował całego ekranu i nie wymagał scrollowania.

2. **Side-by-side layout** — radar (220px lewo) + wykres ceny (flex:1 prawo)
   w jednym wierszu `.radar-price-row`. Usunięty standalone `.price-chart-section`
   który był osobną sekcją nad kartą wynikową.

3. **Wykres ceny 12 miesięcy** — zachowany pełny horyzont 12M (krótko był 3M).

**CSS:** `.radar-price-row`, `.price-chart-compact`, `.price-chart-compact__label`
dodane do `app.css`.

**Commits:** `6bd968e` (radar size), `77a0282` (side-by-side), `c8fe5a6` (restore 12M)
