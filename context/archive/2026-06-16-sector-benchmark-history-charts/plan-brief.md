# Historia median sektorowych + wykresy — Plan Brief

> Pełny plan: `context/changes/sector-benchmark-history-charts/plan.md`

## What & Why

Panel `/admin/sectors` pokazuje **aktualne** mediany EV/FCF, EV/Sales i GM% per sektor,
ale nie ma historii — każdy refresh nadpisuje jedyną wartość. Chcemy widzieć jak mediany
ewoluują w czasie, żeby wykryć trend przeszacowania/niedoszacowania całego sektora.

## Starting Point

Tabela `peer_medians` z UNIQUE KEY `(level, bucket_key, model_version, metric_type)` —
`upsertMedian()` robi INSERT + ON DUPLICATE KEY UPDATE, kasując poprzednią wartość.
Chart.js 4.4.2 i wzorzec `.ai-modal` już załadowane globalnie — nie potrzebujemy nowych
dependencji.

## Desired End State

Każdy wiersz sektora **i** branży ma ikonkę 📊. Kliknięcie otwiera modal z multiline
wykresem (EV/FCF niebieska, EV/Sales żółta, GM% zielona, dual-axis Y). Dane ładowane
AJAX z `GET /admin/sectors/history`. Historia rośnie automatycznie przy każdym refreshu
cron/ręcznym — bez ingerencji w scoring ani tabelę `peer_medians`.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego | Source |
|---|---|---|---|
| Persystencja historii | Osobna tabela `peer_medians_history` (append-only) | `peer_medians` ma upsert-semantics krytyczne dla scoringu — nie ruszamy | Plan |
| Zakres danych | All-time (bez okna czasowego) | Na starcie zbierzemy wszystko; okno 12M można dodać jedną linią gdy historia urośnie | Plan |
| Prezentacja metryk | 3 linie na jednym wykresie, dual-axis Y | Trendy porównywalne od razu; dual-axis rozwiązuje problem różnych skal (EV ~15-30 vs GM% 0-100) | Plan |
| Granularność ikonki | Sektor + branża (oba typy wierszy) | Pełna granularność od razu, implementacja analogiczna | Plan |
| Ładowanie danych | AJAX GET przy kliknięciu ikony | Lazy loading — page load nie rośnie z historią; wzorzec identyczny z refresh endpoint | Plan |
| Pusty stan | Wyświetl komunikat w modalu | Uczciwa komunikacja; użytkownik wie że funkcja istnieje i dane się zbierają | Plan |

## Scope

**In scope:**
- Nowa tabela `peer_medians_history` (append-only, migracja 020)
- Rozszerzenie `upsertMedian()` o INSERT do historii (best-effort, try/catch)
- Metoda `findHistory()` w `PeerMedianRepository`
- Endpoint `GET /admin/sectors/history` w `SectorsController`
- Route w `routes.php`
- Ikonka 📊 + shared modal + Chart.js w `templates/admin/sectors.php`

**Out of scope:**
- Zmiany w `peer_medians` i logice scoringu
- Okno 12M (dodać gdy historia urośnie)
- Export danych (CSV/PNG)
- Alerty/progi na trendach
- Historia na poziomie bardziej granularnym niż industry

## Architecture / Approach

```
Refresh (cron/ręczny)
  └─ refresh_peer_medians.php
       └─ PeerMedianRepository::upsertMedian()
            ├─ peer_medians (UPDATE) ← scoring czyta stąd, bez zmian
            └─ peer_medians_history (INSERT) ← nowe, append-only

/admin/sectors (page load)
  └─ SectorsController::index() → findSectorStats() → widok bez zmian

Kliknięcie 📊 → JS
  └─ fetch GET /admin/sectors/history?level=sector&bucket_key=Technology
       └─ SectorsController::history() → findHistory() → JSON
            └─ Chart.js multiline dual-axis w shared modal
```

## Phases at a Glance

| Faza | Co dostarcza | Główne ryzyko |
|---|---|---|
| 1. Tabela historii i write-path | `peer_medians_history` + zapis przy upsert; dane zaczynają się gromadzić | INSERT w historii musi być odporny na błąd (try/catch) żeby nie blokować scoringu |
| 2. Endpoint + modal + wykres | Pełne UI: ikona + modal + Chart.js; endpoint AJAX | Canvas reuse bug (`Chart.destroy()` przed re-init); walidacja parametrów endpoint |

**Prerequisites:** Faza 1 musi być wdrożona i odświeżona (min. 1 cykl refresh) przed testowaniem danych w wykresie. Fazy można commitować osobno.

**Estimated effort:** ~2 sesje (Faza 1 krótka ~30-40 min; Faza 2 ~60-90 min)

## Open Risks & Assumptions

- Dane historyczne zaczynają się od momentu wdrożenia Fazy 1 — retroaktywne dane nie istnieją.
  Przez pierwsze tygodnie wykres będzie miał mało punktów.
- `gm` w `peer_medians_history.median_value` jest w % (np. 42.5) — potwierdzono przez
  `refresh_peer_medians.php:193` (`$grossMargin = $financials['gross_margins'] * 100`).
  Prawa oś Y renderuje wartości bezpośrednio (0–100 range).

## Success Criteria (Summary)

- Po wdrożeniu Fazy 1: każdy refresh (cron lub ręczny) dopisuje wiersze do `peer_medians_history`.
- Po wdrożeniu Fazy 2: każdy wiersz sektora i branży ma ikonkę, modal otwiera się z wykresem
  lub komunikatem o braku danych.
- Scoring, panel i testy (426 testów) działają bez zmian.
