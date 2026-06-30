---
change_id: screener-to-portfolio-link
title: Screener to portfolio linkage (S-04)
status: implemented
created: 2026-06-30
updated: 2026-06-30

archived_at: null
---

## Notes

S-04 z roadmapy Virtual Portfolio. Dwa kierunki integracji:
1. Screener pokazuje badge "w portfelu" przy spółkach trzymanych + ostrzeżenie kolorem gdy reko negatywna (REDUKUJ/UNIKAJ).
2. Portfolio pokazuje sekcję "Polecane przez screener, ale nie trzymane" (quality_gate=1, reco SILNE KUPUJ lub AKUMULUJ).

Decyzje projektowe (2026-06-30):
- **Gdzie**: oba widoki (screener + portfolio), nie osobna strona.
- **Data layer**: PHP-side enrichment w ScreenerController (getCurrentHoldings) + nowa metoda PortfolioRepository::getScreenerRecommendationsNotHeld().
- **Marker UX**: badge pill "w portfelu" obok tickera + delikatnie wyróżniony wiersz; kolor badge'a zmienia się na warn/danger przy konflikcie.
- **Def. "polecane"**: quality_gate=1, reco_swing IN {SILNE KUPUJ, AKUMULUJ}, nie trzymane.
- **Empty state**: sekcja w portfolio ukryta gdy brak danych.
- **Testing**: PHPUnit SQLite dla nowej metody (wzorzec z S-03).

PRD refs: FR-015. Prereq: S-01, S-02 (oba done). roadmap_ref: S-04.
