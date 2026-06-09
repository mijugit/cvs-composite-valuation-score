# S-02: Track record modelu CVS — Plan Brief

> Full plan: `context/changes/model-track-record/plan.md`

## What & Why

Widok historycznej trafności rekomendacji CVS — czy model miał rację?
Warstwa zaufania: użytkownik widzi ile razy KUPUJ oznaczało faktyczny wzrost ceny,
ile razy UNIKAJ oznaczało spadek. Bez tego track rekordu sygnały CVS są tezami
bez potwierdzenia.

## Starting Point

`cvs_snapshots` zbiera dane od 2026-06-01 (2 dni, 9-14 tickerów). Brakuje kolumny
`price_at_snapshot` — bez ceny w momencie snapshotu nie można ocenić trafności.
`CvsSnapshotRepository` ma `findByTickerSince()` ale brak logiki track record.

## Desired End State

Strona `/track-record` w nawigacji: karta statystyk (% trafień), selektor
horyzontu 30/60/90 dni, tabela wszystkich snapshotów z wynikiem (✓/✗/Za wcześnie),
wykres. Strona `/track-record/{ticker}` z historią per spółka + wykres CVS.
Przez pierwsze 30 dni widok pokazuje snapshoty bez oceny ("za wcześnie") —
ale jest dostępny i rośnie organicznie.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| Źródło ceny "teraz" | Self-join na cvs_snapshots (nowy snapshot tego samego tickera) | Zero dodatkowych API callów YF; działa gdy cron chodzi codziennie |
| Magazynowanie ceny | `price_at_snapshot` kolumna w cvs_snapshots | Czysta architektura; stare 2 dni NULL to akceptowalny koszt |
| Definicja trafienia | Kierunek ceny po N dniach (KUPUJ→wzrost=hit, UNIKAJ→spadek=hit) | Prosta, zrozumiała dla użytkownika |
| Horyzont | Konfigurowalne 30/60/90 dni (GET param, domyślnie 30) | Pasuje do dual-mode modelu (Swing=30, Fund=90) |
| Brak danych | Pokaż snapshot z "Za wcześnie (od X)" zamiast ukrywać | Widok użyteczny od dnia 1, rośnie organicznie |
| Lokacja | Oddzielna strona /track-record w nawigacji | Centralny punkt warstwy zaufania |
| Link z detalu | "Historia CVS" button na /analysis/{ticker} | Naturalny flow: analiza → sprawdź historię |

## Scope

**In scope:** migracja `price_at_snapshot`, aktualizacja `bin/rescore.php`,
`TrackRecordRepository` (self-join), `TrackRecordCalculator` (hit/miss),
`TrackRecordController`, widoki `track-record.php` + `track-record-ticker.php`,
nawigacja, link z detalu, testy jednostkowe.

**Out of scope:** backfill cen dla 2 istniejących dni (brak źródła), porównanie
z analitykami (V2), statystyki per-sektor, alerty skuteczności.

## Architecture / Approach

```
cron (2× dziennie)
  └─ bin/rescore.php
       └─ CvsSnapshotRepository::save(ticker, result, price)
            └─ cvs_snapshots.price_at_snapshot zapisana

GET /track-record?days=30
  └─ TrackRecordController::index()
       └─ TrackRecordRepository::getEvaluations(30)
            └─ self-join: old snapshot (>30d) × latest snapshot (<7d)
                 → price_then + price_now + change_pct
       └─ TrackRecordCalculator::enrichWithResult() + summarise()
       └─ Response::view('track-record', data)
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Migracja + rescore | price_at_snapshot w tabeli i w nowych snapshotach | Self-join bezdatny bez tej kolumny |
| 2. Repository + kalkulator | Logika query + hit/miss, testy | SQL self-join musi obsługiwać NULL i puste zbiory |
| 3. Controller + UI | Widoki, nawigacja, link z detalu | Widok pusty przez 30 dni — komunikat musi być jasny |

**Prerequisites:** F-01 ✅, F-04 ✅ (cvs_snapshots istnieje i jest zasilana)
**Estimated effort:** ~1-2 sesje, 3 fazy.

## Open Risks & Assumptions

- Cron musi działać codziennie żeby self-join miał "nowy snapshot" jako punkt
  odniesienia. Jeśli cron padnie przez 7 dni, evaluacje będą niedostępne.
- Przez pierwsze 30 dni widok pokazuje tylko "Za wcześnie" — to oczekiwane
  zachowanie, nie błąd.

## Success Criteria (Summary)

- `/track-record` renderuje bez błędów i pokazuje snapshoty (z "Za wcześnie" przez pierwsze 30 dni).
- Po uruchomieniu crona nowe snapshoty mają `price_at_snapshot` non-NULL.
- Po 30 dniach pierwsze oceny "Trafna/Błąd" pojawiają się automatycznie.
