# S-03: Screener CVS — Implementation Plan

## Overview

Strona `/screener` pokazująca ranking wszystkich spółek z unii watchlisty
(dane z `cvs_snapshots`) z filtrami (rekomendacja, golden signal, min CVS swing,
sektor) i sortowaniem (domyślnie CVS Swing DESC). Każdy ticker linkuje do
`/analysis/{ticker}`. Wymaga dodania kolumny `sector` do tabeli snapshotów
i aktualizacji `bin/rescore.php`.

## Current State Analysis

- `cvs_snapshots` zawiera dane codziennych przeliczeń (F-04) —
  ticker, score_date, cvs_swing, cvs_fund, reco_swing, reco_fund,
  golden_signal, quality_gate, pillar_scores, price_at_snapshot.
- **Brak kolumny `sector`** — jest tylko w `$financials['sector']` podczas
  kalkulacji, ale nie jest persystowany.
- `CvsSnapshotRepository::findAllLatest()` — gotowe zapytanie self-join,
  zwraca najnowszy snapshot per ticker. Filtrowanie i sort trzeba dodać.
- Wzorzec widoku: `templates/track-record.php` — tabela + filtry GET.
- Wzorzec kontrolera: `src/TrackRecord/TrackRecordController.php`.
- Nawigacja: `templates/layout.php:19` — `site-nav` z Panelem i Track Record.

## Desired End State

- Strona `/screener` dostępna z nawigacji (zalogowani users).
- Tabela: Ticker | CVS Swing | CVS Fund | Reko | Złoty sygnał | Sektor |
  Cena | Data snapshotu; posortowana domyślnie CVS Swing DESC.
- Filtry (GET params): `reco` (dropdown reko swing), `signal` (multi-select
  golden signal), `min_swing` (int, min CVS Swing), `sector` (dropdown),
  `sort` (swing/fund/date).
- Nagłówek: „Dane z [data] [czas] UTC" — MAX(scored_at) z przefiltrowanych wyników.
- Ticker klikalny → `/analysis/{ticker}`.
- PHPUnit + PHPStan zielone.

### Key Discoveries

- `bin/rescore.php` ma `$financials['sector']` — łatwo przekazać do `save()`.
- `CvsSnapshotRepository::save()` już przyjmuje nullable params — rozszerzamy
  tak samo jak z `price_at_snapshot`.
- Filtrowanie PHP-side (nie SQL WHERE) jest OK przy ~50 tickerach; SQL-side
  nie jest potrzebne.
- `reco_swing` wartości zawierają emoji (⬆⬆, ⬆, →, ⬇, ⬇⬇) — filtr musi
  obsługiwać exact match lub str_contains.
- `golden_signal` wartości: 'strong', 'watchlist', 'momentum', null.
- `sector` od Yahoo Finance: 'Technology', 'Healthcare', 'Consumer Cyclical'
  itp. + null dla nieznanych.

## What We're NOT Doing

- Filtrowania po wszystkich ~600 spółkach (tylko unia watchlisty).
- Live data — tylko dzienne snapshoty.
- Paginacji (< 50 tickerów, niepotrzebna).
- Wykresów na stronie screener'a (lista wystarczy).
- Edycji watchlisty ze screener'a.

## Implementation Approach

3 fazy: (1) migracja + sector w rescore; (2) ScreenerRepository (filtry + sort)
+ ScreenerController; (3) widok + nawigacja + trasa.

---

## Phase 1: Migracja sector + update rescore.php

### Overview

Dodanie kolumny `sector VARCHAR(50)` do tabeli i zapis sektora przy każdym
rescore. Istniejące snapshoty dostaną NULL.

### Changes Required

#### 1. Migracja SQL

**File:** `database/migrations/010_add_sector_to_cvs_snapshots.sql`

**Intent:** Addytywna migracja — dodaje kolumnę `sector` do snapshots by
umożliwić filtrowanie po sektorze w screenerze.

**Contract:** `ALTER TABLE cvs_snapshots ADD COLUMN sector VARCHAR(50) NULL AFTER ticker;`

#### 2. CvsSnapshotRepository::save() — dodanie parametru sector

**File:** `src/TrackRecord/CvsSnapshotRepository.php`

**Intent:** Przyjąć opcjonalny `?string $sector = null` i zapisać obok pozostałych
pól. Ten sam wzorzec co `$priceAtSnapshot` (dodane w S-02).

**Contract:** Zmień sygnaturę na
`save(string $ticker, array $result, ?float $priceAtSnapshot = null, ?string $sector = null): void`.
Dodaj `:sector` do INSERT i do UPDATE ON DUPLICATE KEY.

#### 3. bin/rescore.php — przekazanie sector

**File:** `bin/rescore.php`

**Intent:** Wyciągnąć `$financials['sector']` i przekazać jako czwarty argument
do `$snapshots->save()`.

**Contract:** Zmień linię save na:
`$snapshots->save($ticker, $result->toArray(), $price, $financials['sector'] ?? null)`

#### 4. Testy CvsSnapshotRepository — sector

**File:** `tests/TrackRecord/CvsSnapshotRepositoryTest.php`

**Intent:** Dodać testy: `test_save_stores_sector()` i `test_save_null_sector_ok()`.

**Contract:** SQLite schema w `makeRepo()` musi mieć kolumnę `sector TEXT NULL`
(dodana przed istniejącymi kolumnami).

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (nowe testy sector)
- `vendor/bin/phpstan analyse` zielony
- `php -l bin/rescore.php` bez błędów

#### Manual Verification
- Migracja 010 na CF — `SHOW COLUMNS FROM cvs_snapshots LIKE 'sector'`
- Rescore na CF — `SELECT ticker, sector FROM cvs_snapshots WHERE score_date = CURDATE() LIMIT 5`

---

## Phase 2: ScreenerRepository + ScreenerController

### Overview

Nowe klasy w `src/Screener/` — repozytorium z filtrowaniem PHP-side i kontroler.

### Changes Required

#### 1. ScreenerRepository

**File:** `src/Screener/ScreenerRepository.php` (namespace `CVS\Screener`)

**Intent:** Pobiera najnowsze snapshoty (przez `CvsSnapshotRepository::findAllLatest()`)
i filtruje PHP-side wg parametrów przekazanych z kontrolera.

**Contract:** Klasa z konstruktorem przyjmującym `?PDO $db = null`.
Jedna publiczna metoda:

```php
public function getFiltered(
    ?string $reco    = null,     // exact match reco_swing (np. '⬆⬆ SILNE KUPUJ')
    ?string $signal  = null,     // golden_signal ('strong','watchlist','momentum','none')
    ?int    $minSwing = null,    // min cvs_swing (0-100)
    ?string $sector  = null,     // exact match sector
    string  $sort    = 'swing'   // 'swing'|'fund'|'date'
): array                         // array<int, array<string, mixed>>
```

Logika:
1. Pobierz `findAllLatest()` — wszystkie najnowsze snapshoty
2. Filtruj PHP: `quality_gate = 1`, reco, signal, minSwing, sector
3. Sortuj: swing DESC / fund DESC / score_date DESC
4. Zwróć przefiltrowaną tablicę

Osobna metoda:
- `getLastScoredAt(): ?string` — `SELECT MAX(scored_at) FROM cvs_snapshots`
  (dla informacji o świeżości)
- `getDistinctSectors(): array` — `SELECT DISTINCT sector FROM cvs_snapshots
  WHERE sector IS NOT NULL ORDER BY sector` (dla dropdownu)

#### 2. ScreenerController

**File:** `src/Screener/ScreenerController.php` (namespace `CVS\Screener`)

**Intent:** Obsługa GET /screener — parsuje filtry z query string, wzywa
ScreenerRepository, przekazuje dane do widoku.

**Contract:**
```php
public function index(Request $req): void
```
- `requireAuth()`
- Parsuj: `$reco = $req->query('reco')`, `$signal`, `$min_swing`, `$sector`, `$sort`
- Waliduj sort: tylko `['swing','fund','date']`, fallback = 'swing'
- Waliduj min_swing: int 0-100, fallback = 0
- `ScreenerRepository::getFiltered(...)`
- `ScreenerRepository::getLastScoredAt()`
- `ScreenerRepository::getDistinctSectors()`
- `Response::view('screener', [...])`

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- `ScreenerRepository::getFiltered()` zwraca dane bez błędów
- Filtry działają poprawnie (test manualny na żywych danych)

---

## Phase 3: Widok + trasa + nawigacja

### Overview

Strona `/screener` z tabelą, filtrami i sortowaniem.

### Changes Required

#### 1. Widok screener

**File:** `templates/screener.php`

**Intent:** Tabela rankingu z filtrami GET i sortem klikalnym w nagłówkach
kolumn. Komunikat o świeżości danych.

**Contract:**
- Nagłówek: „Screener CVS" + `<small>Dane z [scored_at]</small>`
- Panel filtrów (form GET): dropdown Rekomendacja (+ opcja Wszystkie),
  dropdown Złoty sygnał (strong/watchlist/momentum/brak), input Min CVS Swing,
  dropdown Sektor (+ opcja Wszystkie), przycisk Filtruj + Reset.
- Tabela (jeśli wyniki > 0): Ticker (link) | CVS Swing (klikalny) | CVS Fund
  (klikalny) | Reko | Złoty sygnał (chip) | Sektor | Cena | Data (klikalny).
  Aktywny sort: podkreślenie lub strzałka w nagłówku.
- Komunikat gdy brak wyników z filtrami: „Brak spółek spełniających kryteria."
- Komunikat gdy brak jakichkolwiek snapshotów: „Screener buduje się — wróć
  po pierwszym przeliczeniu crona."
- Disclaimer.

#### 2. Trasa

**File:** `src/Core/routes.php`

**Contract:** `$router->get('/screener', fn($req) => $screener->index($req));`
w nowej sekcji `// Screener (S-03)`.

#### 3. Nawigacja

**File:** `templates/layout.php`

**Contract:** Dodaj `<a href="/screener">Screener</a>` w `site-nav` pomiędzy
„Panel" a „Track Record".

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- Trasa `/screener` zarejestrowana w `routes.php`

#### Manual Verification
- `/screener` renderuje się z tabelą (14+ wierszy) lub komunikatem gdy puste
- Filtr po Rekomendacji działa (pokazuje tylko wybrane reko)
- Filtr po Złotym sygnale działa
- Min CVS Swing (np. 60) filtruje wyniki < 60
- Filtr po sektorze działa (po kolejnym rescorze gdy sector jest uzupełniony)
- Klik na nagłówek CVS Fund → tabela posortowana fund DESC
- Klik na Ticker → przejście na /analysis/{ticker}
- „Screener" widoczny w nawigacji

---

## Testing Strategy

### Unit Tests

- `CvsSnapshotRepository`: `test_save_stores_sector()`, `test_save_null_sector_ok()`
- `ScreenerRepository`: `test_getFiltered_empty()`, `test_getFiltered_by_reco()`,
  `test_getFiltered_by_min_swing()`, `test_sort_by_fund()`

### Manual Testing Steps

1. Deploy + migracja 010 + rescore → sprawdź sektor w tabeli
2. Wejdź na `/screener` — pełna lista spółek
3. Filtruj po rekomendacji „SILNE KUPUJ" — pozostają tylko silne kupuj
4. Ustaw Min CVS Swing = 70 — wszystkie < 70 znikają
5. Filtruj po „Złoty sygnał: strong" — tylko strong signal
6. Kliknij nagłówek „CVS Fund" — posortowane fund DESC
7. Kliknij ticker → /analysis/{ticker}
8. Kliknij „Reset" — wszystkie filtry wyczyszczone

## Performance Considerations

PHP-side filtering na ~50 wierszach z bazy: < 1ms, pomijalny. Zapytanie
`findAllLatest()` (self-join) jest pokryte indeksami. `MAX(scored_at)` — O(1)
z indeksem idx_score_date. Akceptowalne.

## Migration Notes

Migracja 010 addytywna. Rollback: `ALTER TABLE cvs_snapshots DROP COLUMN sector`.
Stare snapshoty dostaną NULL — filtr sektorowy zadziała dopiero po kolejnym
rescorze.

## References

- Roadmap: `context/foundation/roadmap.md` (S-03)
- PRD: `context/foundation/prd.md` (FR-008, FR-009)
- cvs_snapshots DDL: `database/migrations/004_create_cvs_snapshots.sql`
- CvsSnapshotRepository: `src/TrackRecord/CvsSnapshotRepository.php`
- bin/rescore.php: `bin/rescore.php`
- Wzorzec widoku: `templates/track-record.php`
- Wzorzec kontrolera: `src/TrackRecord/TrackRecordController.php`
- Nawigacja: `templates/layout.php:18`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Migracja sector + rescore update

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony (testy sector) — 287c7f1
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — 287c7f1
- [x] 1.3 `php -l bin/rescore.php` bez błędów — 287c7f1

#### Manual
- [x] 1.4 Migracja 010 na CF — kolumna sector widoczna
- [x] 1.5 Rescore na CF — sector non-NULL dla dzisiejszych snapshotów

### Phase 2: ScreenerRepository + ScreenerController

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony — ef03d35
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — ef03d35

#### Manual
- [x] 2.3 `getFiltered()` zwraca dane bez błędów SQL

### Phase 3: Widok + trasa + nawigacja

#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — aa30444
- [x] 3.2 `vendor/bin/phpstan analyse` zielony — aa30444
- [x] 3.3 Trasa `/screener` zarejestrowana — aa30444

#### Manual
- [x] 3.4 `/screener` renderuje tabelę z 14+ wierszami
- [x] 3.5 Filtry (reko, signal, min_swing) działają
- [x] 3.6 Sort klikalny w nagłówkach działa
- [x] 3.7 Ticker → /analysis/{ticker}
- [x] 3.8 „Screener" w nawigacji
