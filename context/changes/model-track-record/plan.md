# S-02: Track record modelu CVS — Implementation Plan

## Overview

Widok historycznej trafności rekomendacji CVS. Użytkownik widzi tabelę
wszystkich snapshotów CVS (globalnie lub per ticker) z oceną: czy rekomendacja
(KUPUJ/UNIKAJ itp.) okazała się trafna po N dniach. Metodyka: porównanie
kierunku zmiany ceny (`price_at_snapshot` stary vs nowy snapshot) — zero
dodatkowych callów API. Strona `/track-record` w nawigacji + link z detalu
spółki.

## Current State Analysis

- `cvs_snapshots` istnieje (F-04) z polami: ticker, score_date, cvs_swing,
  cvs_fund, reco_swing, reco_fund, golden_signal, quality_gate, pillar_scores.
  **Brak kolumny `price_at_snapshot`** — kluczowa luka.
- `CvsSnapshotRepository` ma: `save()`, `findLatestByTicker()`, `findAllLatest()`,
  `findByTickerSince()`. Brak metod dla track record.
- `bin/rescore.php` wywołuje `save($ticker, $result->toArray())` — nie przekazuje
  ceny bieżącej z `$financials['current_price']`.
- Dane od 2026-06-01, 2 dni, 9-14 tickerów/dzień. Evaluacje możliwe dopiero
  po 30 dniach od pierwszego snapshotu (ok. 2026-07-01).
- Pattern trasy/controllera: `AnalysisController::show()` + `Response::view()`.
- `layout.php` — nawigacja w `<nav class="site-nav">`.

## Desired End State

- `cvs_snapshots.price_at_snapshot` przechowuje cenę w momencie scoringu.
- Strona `/track-record` (dostępna z nawigacji): karta statystyk + tabela
  wszystkich snapshotów z oceną trafności + wykres słupkowy hit/miss.
- Strona `/track-record/{ticker}`: historia dla jednej spółki + wykres linii CVS.
- Snapshoty < N dni: kolumna "Wynik" = "Za wcześnie (od DATE+N)".
- Link "Historia CVS" na stronie `/analysis/{ticker}`.
- Horizon selector: 30/60/90 dni (GET param).
- PHPUnit + PHPStan zielone.

### Key Discoveries

- **Self-join na cvs_snapshots**: stary snapshot + najnowszy snapshot tego
  samego tickera dają parę (cena wtedy, cena teraz) bez API call.
  SQL: `JOIN (SELECT ticker, price_at_snapshot FROM cvs_snapshots
  WHERE score_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) latest
  ON latest.ticker = old.ticker`
- **Stare 2 dni snapshotów**: `price_at_snapshot` = NULL po migracji — UI
  pokazuje „—" dla tych wierszy. Nowe snapshoty od razu mają cenę.
- **Definicja trafienia**:
  - SILNE KUPUJ / KUPUJ / AKUMULUJ → cena wzrosła → hit
  - REDUKUJ / UNIKAJ / SILNA SPRZEDAŻ → cena spadła → hit
  - NEUTRALNIE → nie oceniamy (brak kierunku)
  - Zmiana = `(price_now - price_then) / price_then × 100`
- `bin/rescore.php` ma `$financials` z `current_price` (linia ~52 w fetch call) —
  wystarczy przekazać do `save()`.
- Nawigacja: `templates/layout.php` linia 51 (site-nav) — dodać link.

## What We're NOT Doing

- Przechowywania "ceny po 30 dniach" jako osobnego procesu (zbyt złożone).
- Backfillu cen dla istniejących 2 dni snapshotów (brak źródła danych).
- Statystyk per-sektor lub per-sygnał-złoty (V2).
- Alertów gdy model osiągnie X% skuteczności.
- Porównania track record CVS vs analitycy (V2).

## Implementation Approach

3 fazy: (1) DB + rescore — dodanie ceny do snapshotów; (2) repozytorium track
record + logika oceny; (3) kontroler + widoki + nawigacja.

Fazy 1 i 2 są niezależne od siebie nawzajem (można je testować jednostkowo).
Faza 3 spina wszystko.

## Critical Implementation Details

**Self-join potrzebuje 2 snapshotów tego samego tickera** — jeden stary (≥N dni)
i jeden nowy (≤7 dni). Dla tickera z tylko jednym dniem danych wynik = NULL
(widok pokaże „Za wcześnie"). Ta sytuacja będzie normalna przez pierwsze 30 dni.

**`price_at_snapshot` jako DECIMAL(10,2)**: wystarczy dla cen USD do $99,999.99.
Dla stock splits lub bardzo tanich/drogich akcji — OK przy tej skali.

---

## Phase 1: Migracja price_at_snapshot + aktualizacja rescore.php

### Overview

Dodanie kolumny `price_at_snapshot` do `cvs_snapshots` oraz zapisywanie
ceny bieżącej przy każdym uruchomieniu rescore.php.

### Changes Required

#### 1. Migracja SQL

**File:** `database/migrations/009_add_price_to_cvs_snapshots.sql`

**Intent:** Dodać kolumnę `price_at_snapshot DECIMAL(10,2) NULL` do istniejącej
tabeli cvs_snapshots. Addytywna — istniejące wiersze dostaną NULL.

**Contract:** `ALTER TABLE cvs_snapshots ADD COLUMN price_at_snapshot DECIMAL(10,2) NULL AFTER scored_at;`

#### 2. CvsSnapshotRepository::save() — dodanie parametru ceny

**File:** `src/TrackRecord/CvsSnapshotRepository.php`

**Intent:** Przyjąć opcjonalny `?float $price` i zapisać go w INSERT/UPDATE obok
innych pól snapshotu. Istniejące wywołania bez ceny pozostają kompatybilne (null).

**Contract:** Zmień sygnaturę na `save(string $ticker, array $result, ?float $price = null): void`.
Dodaj `:price_at_snapshot` do INSERT i `price_at_snapshot = VALUES(price_at_snapshot)`
do ON DUPLICATE KEY UPDATE (SQLite fallback: do SET).

#### 3. bin/rescore.php — przekazanie current_price

**File:** `bin/rescore.php`

**Intent:** Po pobraniu `$financials` wyciągnąć `$financials['current_price']`
i przekazać jako trzeci argument do `$snapshots->save()`.

**Contract:** Zmień linię `$snapshots->save($ticker, $result->toArray())` na
`$snapshots->save($ticker, $result->toArray(), isset($financials['current_price']) ? (float) $financials['current_price'] : null)`.

#### 4. Test CvsSnapshotRepository — cena

**File:** `tests/TrackRecord/CvsSnapshotRepositoryTest.php`

**Intent:** Dodać testy weryfikujące że `save()` poprawnie zapisuje i odczytuje
`price_at_snapshot`, oraz że null jest akceptowalny (backward compat).

**Contract:** 2 nowe testy: `test_save_stores_price()` i `test_save_null_price_ok()`.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (nowe testy ceny)
- `vendor/bin/phpstan analyse` zielony
- `php -l bin/rescore.php` bez błędów

#### Manual Verification
- Migracja 009 na CF: `SHOW COLUMNS FROM cvs_snapshots LIKE 'price_at_snapshot'`
- Po jednym uruchomieniu rescore: `SELECT ticker, price_at_snapshot FROM cvs_snapshots WHERE score_date = CURDATE()` — wartości non-NULL

---

## Phase 2: TrackRecordRepository + kalkulator trafności

### Overview

Nowe metody w `CvsSnapshotRepository` (lub oddzielne `TrackRecordRepository`)
do pobierania par (stary snapshot, nowy snapshot) i kalkulacji trafności.

### Changes Required

#### 1. TrackRecordRepository

**File:** `src/TrackRecord/TrackRecordRepository.php` (nowa klasa, namespace `CVS\TrackRecord`)

**Intent:** Jedyne miejsce w kodzie z logiką zapytań dla track record. Oddziela
query complexity od kontrolera.

**Contract:** Klasa z PDO injection. Metody:

- `getEvaluations(int $horizonDays = 30): array` — self-join: snapshoty starsze
  niż `$horizonDays` dni złączone z najnowszym snapdotem (≤7 dni) tego samego
  tickera; oba muszą mieć `price_at_snapshot IS NOT NULL`; sortowane ticker ASC,
  score_date ASC; zwraca `array<int, array{ticker, score_date, cvs_swing,
  reco_swing, golden_signal, price_then, price_now, price_change_pct, quality_gate}>`.

- `getForTicker(string $ticker, int $horizonDays = 30): array` — jak wyżej ale
  filtruje `WHERE old.ticker = ?`; używane przez widok per-ticker.

- `getSummaryStats(int $horizonDays = 30): array` — zwraca `{total, hits, misses,
  pending, hit_rate_pct, avg_change_pct}` — dla karty statystyk.

**Kluczowy SQL dla getEvaluations():**
```sql
SELECT
    old.ticker, old.score_date, old.scored_at,
    old.cvs_swing, old.cvs_fund,
    old.reco_swing, old.reco_fund,
    old.golden_signal, old.quality_gate,
    old.price_at_snapshot  AS price_then,
    latest.price_at_snapshot AS price_now,
    ROUND(
        (latest.price_at_snapshot - old.price_at_snapshot)
        / old.price_at_snapshot * 100, 2
    ) AS price_change_pct
FROM cvs_snapshots old
INNER JOIN (
    SELECT ticker, price_at_snapshot, MAX(score_date) AS score_date
    FROM cvs_snapshots
    WHERE score_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      AND price_at_snapshot IS NOT NULL
    GROUP BY ticker
) latest ON latest.ticker = old.ticker
WHERE old.score_date <= DATE_SUB(CURDATE(), INTERVAL :horizon DAY)
  AND old.price_at_snapshot IS NOT NULL
  AND old.quality_gate = 1
ORDER BY old.ticker ASC, old.score_date ASC
```

#### 2. TrackRecordCalculator

**File:** `src/TrackRecord/TrackRecordCalculator.php`

**Intent:** Prosta klasa statyczna/utility — ocenia wynik jednego wpisu i
wylicza statystyki zbiorcze.

**Contract:**
- `static isHit(string $recoSwing, float $priceChangePct): ?bool` — null gdy
  rekomendacja neutralna lub brak danych. Logika:
  `SILNE KUPUJ | KUPUJ | AKUMULUJ` → zmiana > 0 → true
  `REDUKUJ | UNIKAJ` → zmiana < 0 → true
  `NEUTRALNIE` → null
- `static summarise(array $evaluations): array` — liczy total/hits/misses/pending/
  hit_rate_pct/avg_change_pct.
- `static enrichWithResult(array $evaluations): array` — dodaje `result` key
  (hit/miss/neutral/pending) do każdego wiersza.

#### 3. Testy

**File:** `tests/TrackRecord/TrackRecordCalculatorTest.php`

**Intent:** Unit testy dla `isHit()` i `summarise()` — nie potrzebują DB.

**Contract:** Testy dla każdej gałęzi rekomendacji + edge case (neutralna, null price).

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- `TrackRecordRepository::getEvaluations()` zwraca dane (nawet puste) bez błędu SQL
- Po 2 dniach snapshotów z ceną: wynik "Za wcześnie" (horizon 30 dni, 2 < 30)

---

## Phase 3: Kontroler + widoki + nawigacja

### Overview

Strona `/track-record` (globalna) i `/track-record/{ticker}` (per spółka),
link z `/analysis/{ticker}`, nowa pozycja w nawigacji.

### Changes Required

#### 1. TrackRecordController

**File:** `src/TrackRecord/TrackRecordController.php`

**Intent:** Obsługa GET /track-record i GET /track-record/{ticker}.

**Contract:**
- `index(Request $req): void` — `requireAuth()`; `$horizon = (int) ($req->query('days') ?: 30)`;
  clamp do {30, 60, 90}; wczytaj `getEvaluations($horizon)`, `enrichWithResult()`,
  `summarise()`; `Response::view('track-record', [...])`.
- `show(Request $req): void` — `requireAuth()`; `$ticker = strtoupper($req->param('ticker'))`;
  wczytaj `getForTicker($ticker, $horizon)`, `enrichWithResult()`, `summarise()`;
  `Response::view('track-record-ticker', [...])`.

#### 2. Trasy

**File:** `src/Core/routes.php`

**Contract:**
```php
$router->get('/track-record',          fn($req) => $trackRecord->index($req));
$router->get('/track-record/{ticker}', fn($req) => $trackRecord->show($req));
```

#### 3. Widok globalny

**File:** `templates/track-record.php`

**Intent:** Strona track record: karta statystyk + selektor horyzontu +
tabela wszystkich ocenionych snapshotów + wykres słupkowy.

**Contract:**
- Karta statystyk: N ocen, % trafień (lub "za wcześnie — brak ocenionych"), śr. zmiana ceny.
- Selektor horyzontu: form GET, 3 przyciski `btn--sm` [30 dni] [60 dni] [90 dni].
- Tabela: ticker (link do /track-record/{ticker}), data, CVS Swing, Reko, Cena wtedy,
  Cena teraz, Zmiana%, Wynik (chip: ✓ Trafna / ✗ Błąd / Neutralna / Za wcześnie).
- Wykres Chart.js: grouped bar `hits` vs `misses` per typ rekomendacji (tylko gdy
  jest ≥1 oceniony wynik). Wbudowany `<script>` inline jak w analysis.php.
- Komunikat gdy brak danych: "Track record buduje się od 2026-06-01. Pierwsze oceny
  dostępne ok. 2026-07-01 (30 dni po starcie scoringu)."

#### 4. Widok per-ticker

**File:** `templates/track-record-ticker.php`

**Intent:** Historia CVS dla jednej spółki: tabela chronologiczna + wykres linii CVS.

**Contract:**
- Nagłówek: „Historia CVS: {TICKER}" + link powrót do /analysis/{ticker}.
- Tabela: data, CVS Swing, CVS Fund, Reko Swing, Złoty sygnał, Cena wtedy,
  Cena teraz, Zmiana%, Wynik.
- Wykres linii: CVS Swing i CVS Fund na osi Y (0-100) vs daty snapshotów.
  Korzysta z istniejącego Chart.js CDN z layout.php.

#### 5. Nawigacja

**File:** `templates/layout.php`

**Intent:** Dodać link „Track Record" do `site-nav` dla zalogowanych userów.

**Contract:** Obok istniejącego `<a href="/dashboard">Panel</a>` dodaj
`<a href="/track-record">Track Record</a>` (widoczny tylko gdy sesja zalogowana).

#### 6. Link z detalu spółki

**File:** `templates/analysis.php`

**Intent:** Przycisk/link „Historia CVS" w sekcji `analysis-detail__heading`.

**Contract:** Obok przycisku watchlist dodaj `<a class="btn btn--ghost btn--sm"
href="/track-record/{ticker}">Historia CVS</a>`.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- Trasy `/track-record` i `/track-record/{ticker}` zarejestrowane (grep)

#### Manual Verification
- `/track-record` renderuje się bez błędów, karta statystyk pokazuje "za wcześnie" lub dane
- Selektor 30/60/90 dni działa (URL zmienia się, tabela przeładowuje)
- `/track-record/AAPL` renderuje historię dla AAPL
- Link „Historia CVS" widoczny na `/analysis/AAPL`
- „Track Record" widoczny w nawigacji
- Po wygaśnięciu sesji i ponownym zalogowaniu — nowe snapshoty mają `price_at_snapshot`

---

## Testing Strategy

### Unit Tests

- `CvsSnapshotRepository`: `test_save_stores_price()`, `test_save_null_price_ok()`
- `TrackRecordCalculator`: wszystkie gałęzie `isHit()`, `summarise()` z 0 wierszami i N wierszami

### Manual Testing Steps

1. Deploy + migracja 009 na CF
2. Uruchom `bin/rescore.php` — sprawdź `SELECT price_at_snapshot FROM cvs_snapshots WHERE score_date = CURDATE()`
3. Wejdź na `/track-record` — karta "za wcześnie", pusta tabela (lub wiersze z "Za wcześnie")
4. Wejdź na `/track-record/AAPL` — historia dla AAPL
5. Kliknij selektor 60 dni — URL zmienia się na `?days=60`
6. Na `/analysis/AAPL` kliknij „Historia CVS" — prowadzi do track-record/AAPL

## Performance Considerations

Self-join na `cvs_snapshots` jest wydajny dzięki indeksom `idx_ticker_date`
i `idx_score_date`. Przy ~100 tickerach × 365 dni = ~36,500 wierszy — pomijalny
koszt dla shared hostingu.

## Migration Notes

Migracja `009` addytywna (ADD COLUMN). Istniejące 2 dni snapshotów dostaną NULL.
Rollback: `ALTER TABLE cvs_snapshots DROP COLUMN price_at_snapshot`.

## References

- Roadmap: `context/foundation/roadmap.md` (S-02)
- PRD: `context/foundation/prd.md` (FR-007)
- cvs_snapshots DDL: `database/migrations/004_create_cvs_snapshots.sql`
- CvsSnapshotRepository: `src/TrackRecord/CvsSnapshotRepository.php`
- rescore.php: `bin/rescore.php`
- Pattern trasy: `src/Core/routes.php`
- Pattern kontrolera: `src/CVS/AnalysisController.php:114`
- Pattern widoku: `templates/dashboard.php`, `templates/analysis.php`
- Nawigacja: `templates/layout.php:51` (site-nav)

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Migracja price_at_snapshot + rescore update

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony (nowe testy ceny) — 4706d5e
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — 4706d5e
- [x] 1.3 `php -l bin/rescore.php` bez błędów — 4706d5e

#### Manual
- [x] 1.4 Migracja 009 wykonana na CF — kolumna price_at_snapshot widoczna
- [x] 1.5 Po uruchomieniu rescore — price_at_snapshot non-NULL dla dzisiejszych snapshotów

### Phase 2: TrackRecordRepository + kalkulator

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony — 1457aa3
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — 1457aa3

#### Manual
- [x] 2.3 `getEvaluations(30)` zwraca dane bez błędu SQL (mogą być puste)

### Phase 3: Kontroler + widoki + nawigacja

#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — ca6bdb8
- [x] 3.2 `vendor/bin/phpstan analyse` zielony — ca6bdb8
- [x] 3.3 Trasy `/track-record` i `/track-record/{ticker}` zarejestrowane — ca6bdb8

#### Manual
- [x] 3.4 `/track-record` renderuje bez błędów, karta statystyk poprawna
- [x] 3.5 Selektor 30/60/90 dni działa
- [x] 3.6 `/track-record/{ticker}` pokazuje historię + wykres CVS
- [x] 3.7 Link „Historia CVS" na `/analysis/{ticker}` prowadzi do track-record
- [x] 3.8 „Track Record" widoczny w nawigacji po zalogowaniu
