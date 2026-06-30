---
change_id: full-history-and-reason-log
title: Full rebalance history and reason timeline (S-03)
status: implemented
created: 2026-06-30
updated: 2026-06-30
archived_at: null
---

## Notes

S-03 z roadmapy Virtual Portfolio. Osobna strona `/portfolio/history` z pełną historią rebalansów.

Decyzje projektowe (ustalone z userem 2026-06-30):
- **Lokalizacja**: osobna strona `/portfolio/history`, link z `/portfolio` (strona portfela zostaje lekka — ostatni cykl).
- **Granulacja**: karta per cykl (data, status, liczba transakcji, wartość portfela) z rozwijaną listą transakcji BUY/SELL/HOLD/SKIP/NO_ACTION i ich uzasadnieniami.
- **Nieudane cykle**: udane rebalanse w głównej osi czasu; nieudane (timeout/llm_failed/failed) w osobnej, zwijanej sekcji „zdarzenia operacyjne".

Grunt repozytoryjny istnieje: `PortfolioRepository::getCycleHistory()` i `getTransactionsByCycle()` — obecnie nieużywane (brak route'a i widoku).

PRD refs: US-02, FR-011, FR-013. Prereq: S-02 (done). roadmap_ref: S-03.
