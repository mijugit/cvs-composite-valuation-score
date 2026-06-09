# CVS Faza 3 — Warstwa peer-group dla Valuation — Plan Brief

> Full plan: `context/changes/cvs-scoring-refinement/plan.md`
> Frame brief: `context/changes/cvs-scoring-refinement/frame.md`
> PRD: `context/foundation/prd.md`

## What & Why

Filar Valuation przestaje porównywać spółkę do jednej grubej mediany sektorowej, a zaczyna
porównywać ją do **podsektora (peer-group)**. Problem, który to rozwiązuje: jednostka odniesienia
jest zbyt gruba — producent gier (TTWO) i streaming (NFLX) lądują w jednym worku „Communication
Services" i są mierzone tą samą medianą, co fałszuje ocenę „tania/droga". P1 (źródło median) i P2
(granularność) to jedna sprzężona decyzja: „do jakiej populacji i jak licznej porównujemy spółkę".

## Starting Point

Dziś `ValuationPillar` liczy `ratio = (EV/forward_FCF) / median_ev_fcf[sektor]` z **12 zahardkodowanych
stałych**. Yahoo `industry` (drobny poziom) jest już pobierany, ale nieużywany w scoringu. `cvs_snapshots`
nie ma `industry` ani `model_version`. `bin/rescore.php` scoruje tylko unię watchlist. Populacja
autocomplete = ~177 tickerów w `public/data/tickers.json`.

## Desired End State

Valuation ocenia spółkę względem mediany jej podsektora (gdy bucket ≥ N próbek), inaczej spada do
sektora, a kotwica absolutna ścina wynik gdy cały podsektor przewartościowany. Mediany liczone
empirycznie z rolującego batcha, zapisane w `peer_medians`. Każdy snapshot niesie `model_version`;
track-record liczony per wersja. Skala rekomendacji zrekalibrowana, by progi znaczyły to co dziś.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego | Źródło |
| --- | --- | --- | --- |
| Korzeń problemu | Granularność rządzi medianą (P1+P2 sprzężone) | Standard: peer-group, nie GICS-1 | Frame |
| Miara sukcesu | Osąd ekspercki (nie backtest) | TrackRecord dojrzeje ~lipiec 2026 | Frame |
| Strop granularności | Maks. 2 poziomy + próg N | Anty over-engineering | Shape |
| Liczenie median | Osobny pipeline + tabela `peer_medians` | Rozdziela zbieranie od agregacji, `sample_count`→N | Plan |
| Wersjonowanie | `model_version` w configu, stempel na snapshot | Deterministyczne, czysta ciągłość track-record | Plan |
| Populacja | Rozszerzyć `tickers.json` >177 (~500) | Gęstsze buckety podsektorów | Plan |
| Pillar + kotwica | Wstrzyknięty resolver median + strażnik kotwicy | Jeden wynik, obrona przed pułapką względności | Plan |
| Cold-start | Twardy fallback do dzisiejszych stałych | Zero seed, brak regresji od dnia 1 | Plan |
| Zakres | Tylko peer-group + faza kalibracji; P3/P4 poza | Spójne z shape, ocena per zmiana | Plan |

## Scope

**In scope:** drzewo sektor→podsektor z Yahoo `industry`; pipeline empirycznych median (rolujący batch);
tabela `peer_medians`; `model_version` + `industry` w snapshotach; resolver z progiem N + fallback;
kotwica absolutna w Valuation; rozszerzenie populacji; multi-soczewka AI (nice-to-have); rekalibracja skali.

**Out of scope:** sigmoid (P3) i wygładzanie Quality (P4); drzewo głębsze niż 2 poziomy; Momentum;
auth/role/UX; płatne dane; backtest/ML; ręczna kuracja mapy sektor→podsektor.

## Architecture / Approach

`ValuationMetrics` (reużywalny wzór EV/FCF) zasila i pillar, i pipeline. `refresh_peer_medians.php`
(rolujący cron po sektorach) liczy mediany per industry → `peer_medians` (stemplowane wersją).
`MedianResolver` (wstrzyknięty do pillara) wybiera: podsektor ≥N → sektor → stała (cold-start).
Pillar liczy score podsektorowy i sektorowy (kotwica), ścina wynik. `CVSModel`/`rescore.php` stempluje
wersję; `TrackRecord` filtruje po wersji. Determinizm: mediany zamrożone w tabeli, zero I/O w scoringu.

## Phases at a Glance

| Faza | Dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Fundament danych | Migracje, populacja ~500, config | Jakość/rozmiar listy tickerów |
| 2. Pipeline median | `ValuationMetrics`, batch, `PeerMedianRepository` | Rate-limit Yahoo, czas crona CF |
| 3. Pillar + kotwica | Resolver, peer-group scoring, wersjonowanie | Determinizm, regresja istniejących spółek |
| 4. Multi-soczewka AI | Warunkowa mediana do AI + przejrzystość | Rozmycie narracji AI (mitygowane progiem) |
| 5. Rekalibracja skali | Raport rozkładu + korekta progów | Przesunięcie znaczenia rekomendacji |

**Prerequisites:** dostęp do bazy (migracje), działający cron CF z PHP 8.2, populacja tickerów.
**Estimated effort:** ~3 tygodnie po godzinach na rdzeń (Fazy 1–3); Fazy 4–5 lżejsze, dokładane po.

## Open Risks & Assumptions

- Drzewo zależy od jakości klasyfikacji `industry` Yahoo (część spółek może mieć ubogie/dziwne etykiety).
- ~500 tickerów może wciąż dać chude buckety w niszowych podsektorach — stąd próg N + fallback (zaprojektowane).
- Reguła łączenia score'u z kotwicą (`anchor_blend`) wymaga dobrania okiem eksperta na realnych danych.
- Rekalibracja progów jest ręczna; ryzyko subiektywności mitygowane raportem rozkładu.

## Success Criteria (Summary)

- Na znanych spółkach (TTWO vs NFLX) Valuation rozróżnia profile i jest zgodny z osądem eksperckim.
- Spółka z przewartościowanego podsektora nie wychodzi „fair" (kotwica działa).
- `composer stan` + PHPUnit zielone; snapshoty niosą `model_version`; track-record nie miesza wersji.
