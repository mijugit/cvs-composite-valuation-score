# S-06 Watchlist + Ticker Autocomplete — Plan Brief

> Full plan: `context/changes/s-06-watchlist/plan.md`

## What & Why

Zalogowany użytkownik może zapisywać ulubione tickery do watchlisty (max 20) i szybko
je analizować jednym kliknięciem. Statyczny typeahead ułatwia wpisywanie symboli
bez znajomości pełnych nazw spółek z pamięci.

## Starting Point

Projekt ma już auth, dashboard z formularzem analizy i detail page. Tabela `users`
z `UserRepository` to gotowy wzorzec do powielenia. Brak jakiegokolwiek mechanizmu
zapisywania stanu po stronie użytkownika poza sesją.

## Desired End State

User loguje się i widzi chipy "Obserwowane" nad formularzem. Klik chipu → ticker
w textarea → Analizuj. Karty wynikowe i strona `/analysis/{ticker}` mają przycisk ⭐/×
do toggle watchlisty przez AJAX (bez przeładowania). Typeahead na textarea sugeruje
spółki z S&P 500 + NASDAQ 100 po wpisaniu pierwszej litery.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego | Source |
|---|---|---|---|
| Controller | Nowy `WatchlistController` | SRP, zgodne z Auth split | Plan |
| Toggle | 1 endpoint `/watchlist/toggle` | Mniej routes, prostszy JS | Plan |
| Autocomplete źródło | Statyczny `tickers.json` | Offline, zero API calls, ~600 spółek wystarczy | Plan |
| Autocomplete zakres | Tylko dashboard textarea | Główny punkt wejścia do analizy | Plan |
| Limit | 20 tickerów / user | Konfiguracja obok `max_tickers` | Plan |
| Empty state | Sekcja ukryta | Czysty dashboard dla nowych | Plan |
| API response | `{ok, action, ticker, count}` | Front-end zna nowy stan bez fetcha | Plan |
| Detail page | `isWatched()` + przycisk | Pełny UX na każdej stronie | Plan |
| Testy | WatchlistRepositoryTest (SQLite) | Spójne z istniejącymi testami | Plan |

## Scope

**In scope:**
- Tabela `watchlist` + `WatchlistRepository`
- `WatchlistController` (POST /watchlist/toggle)
- `public/data/tickers.json` (~600 spółek)
- Autocomplete na `#tickers` textarea (client-side, prefix + substring match)
- Dashboard: chipy watchlisty, toggle na kartach wynikowych
- Detail page: przycisk Obserwuj/Usuń
- `WatchlistRepositoryTest` (SQLite in-memory)

**Out of scope:**
- Auto-analiza po kliknięciu chipu
- Powiadomienia email o zmianie CVS
- Sortowanie watchlisty
- Live validacja istnienia spółki przez Yahoo Finance
- Autocomplete na polach innych niż dashboard textarea

## Architecture / Approach

```
Browser → POST /watchlist/toggle (AJAX, CSRF header)
             ↓
        WatchlistController
             ↓
        WatchlistRepository → MySQL watchlist table
             ↓
        JSON {ok, action, ticker, count}
             ↓
        app.js aktualizuje watchedSet (Set) + DOM bez przeładowania

Autocomplete: fetch tickers.json (raz) → filtruj prefix w pamięci → dropdown
Dashboard render: PHP emituje data-watchlist='[...]' → JS czyta Set z JSON
```

## Phases at a Glance

| Phase | Co dostarcza | Główne ryzyko |
|---|---|---|
| 1. DB + Repo | Migration SQL + WatchlistRepository + testy | SQLite in-memory ma inne typy niż MySQL |
| 2. Controller + Routes | Działający endpoint toggle z CSRF | Autoryzacja poprawna dla AJAX |
| 3. Tickers + Autocomplete | tickers.json + typeahead na textarea | Token detection przy multi-ticker textarea |
| 4. Dashboard UI | Chipy, empty state, toggle na kartach | Synchronizacja `watchedSet` z chipami |
| 5. Detail page | Przycisk na /analysis/{ticker} | isWatched query per page load |

**Prerequisites:** S-05 wdrożone (done ✅), migracja 001 na produkcji (done ✅)
**Estimated effort:** ~3–4 sesje, 5 faz

## Open Risks & Assumptions

- `tickers.json` wymaga ręcznego wygenerowania ze źródła publicznego (S&P 500 CSV); plan opisuje format, nie skrypt
- SQLite in-memory testy mogą mieć edge case z FK (SQLite nie wymusza FK domyślnie — `PRAGMA foreign_keys = ON` jeśli potrzeba)
- Autocomplete z 600 tickerami: filtrowanie w pamięci jest natychmiastowe przy tablicy tej wielkości

## Success Criteria (Summary)

- `vendor/bin/phpunit` → 0 failures, nowe testy watchlisty zielone
- MELI: przejście add → usunięcie → add bez przeładowania dashboardu
- Wpisanie `APP` w textarea → dropdown z AAPL widoczny
