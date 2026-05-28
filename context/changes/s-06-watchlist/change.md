---
id: s-06-watchlist
title: "Watchlist — zapisywanie ulubionych tickerów + autocomplete"
status: implemented
roadmap_ref: S-06
created: 2026-05-28
updated: 2026-05-28
implemented: 2026-05-28
---

## Summary

Użytkownik może zapisywać ulubione tickery do watchlisty (max 20). Dashboard pokazuje
je jako klikalne chipy pre-fillujące formularz. Karty wynikowe i strona szczegółów
mają przycisk toggle ⭐/×. Statyczny słownik ~600 spółek (S&P 500 + NASDAQ 100)
napędza typeahead na głównym polu textarea.

## Decisions

| Decyzja | Wybór |
|---|---|
| Controller | Nowy `WatchlistController` (SRP) |
| Toggle | Jeden endpoint `/watchlist/toggle` — add lub remove |
| Limit | 20 tickerów / user |
| Autocomplete | Statyczny `public/data/tickers.json` (~600 spółek) |
| Autocomplete zakres | Główna textarea dashboardu |
| Detail page | Przycisk + 1 query `isWatched()` |
| Empty state | Sekcja ukryta jeśli pusta |
| API response | `{ok, action: 'added'|'removed', ticker, count}` |
