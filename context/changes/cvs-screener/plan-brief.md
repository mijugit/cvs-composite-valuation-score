# S-03: Screener CVS — Plan Brief

> Full plan: `context/changes/cvs-screener/plan.md`

## What & Why

Screener CVS = strona `/screener` pokazująca ranking spółek z watchlisty wg
CVS z filtrami i sortem. Zamiast pytać „czy ta spółka jest dobra?" user może
teraz zapytać „jakie spółki z moich obserwowanych mają SILNE KUPUJ i złoty sygnał?"

## Starting Point

`cvs_snapshots` (F-04) zbiera dane codziennie. `findAllLatest()` gotowe.
Brakuje: kolumny `sector` w tabeli, klasy `ScreenerRepository`, kontrolera,
widoku i trasy.

## Desired End State

Zalogowany user otwiera `/screener` (link w nawigacji), widzi tabelę wszystkich
spółek z unii watchlisty posortowanych CVS Swing DESC. Może odfiltrować po
rekomendacji, golden signal, min CVS, sektorze. Klik w ticker → analiza.
Informacja „Dane z [data/czas]" pod nagłówkiem.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| Kolumna sector | Migracja + rescore.php | Filtr sektorowy wymaga persistencji |
| Filtry | Reko + signal + min_swing + sector | Pokrywa główne use-cases |
| Sort | PHP-side, CVS Swing DESC domyślnie, klikalny | ~50 wierszy, SQL niepotrzebny |
| Paginacja | Brak — wszystkie naraz | < 50 tickerów |
| Świeżość | MAX(scored_at) pod nagłówkiem | Dane dzienne, user musi wiedzieć |
| Nawigacja | /screener w site-nav | Centralny punkt odkrywania |
| Ticker link | → /analysis/{ticker} | Naturalny flow screener → analiza |

## Scope

**In scope:** migracja sector, CvsSnapshotRepository::save() z sector,
bin/rescore.php update, ScreenerRepository (filtry + sort), ScreenerController,
templates/screener.php, trasa, nawigacja.

**Out of scope:** ~600 spółek (tylko unia watchlisty), live data, paginacja,
wykresy na screenerze.

## Architecture / Approach

```
GET /screener?reco=KUPUJ&signal=strong&min_swing=60&sector=Technology&sort=fund
  └─ ScreenerController::index()
       ├─ ScreenerRepository::getFiltered(...)
       │    └─ CvsSnapshotRepository::findAllLatest()  ← self-join
       │    └─ PHP filter + sort
       ├─ ScreenerRepository::getLastScoredAt()
       ├─ ScreenerRepository::getDistinctSectors()
       └─ Response::view('screener', data)
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Migracja + sector | sector w cvs_snapshots, rescore zapisuje sektor | Stare snapshoty bez sektora (NULL) — filtr działa dopiero po rescorze |
| 2. Repository + Controller | ScreenerRepository (filtry PHP), ScreenerController | reco_swing zawiera emoji — filtr musi exact match |
| 3. View + nav | templates/screener.php, trasa, nawigacja | Sort klikalny wymaga query string loop na nagłówki |

**Prerequisites:** F-01 ✅, F-04 ✅ (dane już są w cvs_snapshots)
**Estimated effort:** ~1 sesja, 3 fazy.

## Open Risks & Assumptions

- Sektor NULL dla starych snapshotów — dropdown sektorów będzie pusty do
  pierwszego rescoretu po migracji. Komunikat graceful.
- reco_swing zawiera Unicode emoji — PHP filter musi używać exact match lub
  str_contains z pełnym labellem ('⬆⬆ SILNE KUPUJ' itp.)

## Success Criteria (Summary)

- `/screener` renderuje tabelę ze wszystkimi tickerami z watchlisty.
- Filtry (reko/signal/min_swing/sector) redukują listę poprawnie.
- Sort klikalny działa; ticker linkuje do analizy.
