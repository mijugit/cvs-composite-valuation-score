# Historia median sektorowych + wykresy w /admin/sectors — Plan implementacji

## Overview

Dodajemy historię median sektorowych (EV/FCF, EV/Sales, GM%) i interaktywne
wykresy w panelu `/admin/sectors`. Zmiany obejmują dwie fazy:
(1) tabela append-only `peer_medians_history` + write-path,
(2) endpoint AJAX + modal z wykresem Chart.js dla każdego sektora i branży.

## Current State Analysis

- `peer_medians` ma UNIQUE KEY `(level, bucket_key, model_version, metric_type)` —
  `upsertMedian()` **nadpisuje** jedyną wartość. Zero historii.
- `PeerMedianRepository::upsertMedian()` (`src/CVS/Valuation/PeerMedianRepository.php:47–119`)
  wykonuje INSERT + UPDATE ON DUPLICATE KEY. Nie zapisuje poprzedniej wartości.
- `SectorsController::refresh()` (`src/Admin/SectorsController.php:66–103`) odpala
  `bin/refresh_peer_medians.php` przez `exec(...' &')` — async, fire-and-forget.
- Cron odpala refresh raz dziennie → docelowo ~365 punktów historii rocznie,
  ~3 wiersze/odświeżenie/sektor (ev_fcf, ev_sales, gm).
- **Chart.js 4.4.2** załadowany globalnie w `templates/layout.php:78` — brak dodatkowych dependencji.
- **Modal pattern**: `.ai-modal` + `[hidden]` toggle, vanilla JS (`public/css/app.css:769–827`).
  Przykład: company-info modal w `templates/analysis.php:40–179`.
- Dual-axis Y precedens: Forecast Fan Chart w `templates/analysis.php:1261–1394` używa
  `yAxisID` w Chart.js — identyczny pattern tutaj.
- `findSectorStats()` (`PeerMedianRepository.php:177–220`) odczytuje aktualne mediany
  dla panelu. Nie zmienia się.

## Desired End State

Po wdrożeniu każdy wiersz sektora **i** branży w `/admin/sectors` ma ikonkę wykresu.
Kliknięcie otwiera modal z multiline chart (3 serie: EV/FCF niebieska, EV/Sales żółta,
GM% zielona), podwójna oś Y (lewa: mnożniki EV, prawa: GM%). Dane ładowane AJAX z
nowego endpointu `GET /admin/sectors/history`. Gdy brak historii — pusty wykres
z komunikatem "Brak danych historycznych. Dane zaczną się gromadzić od następnego odświeżenia."

Historia rośnie automatycznie: każde wywołanie `upsertMedian()` (refresh ręczny lub cron)
zapisuje wiersz do `peer_medians_history`. Tabela `peer_medians` (i scoring) pozostają
bez zmian.

### Key Discoveries

- `peer_medians` UNIQUE KEY → zmiana schematu tej tabeli zniszczyłaby semantykę upsert
  używaną przez scoring. Bezpieczniejsza osobna tabela (`lessons.md`: nie ruszaj tabel
  odczytywanych przez scoring bez analizy blast-radius).
- GM% w `peer_medians.median_value` jest przechowywane jako ułamek (0.0–1.0) lub wartość
  procentowa (0–100) — sprawdzić przed rysowaniem (`findSectorStats()` zwraca jako float,
  `refresh_peer_medians.php:193` mnoży przez 100 przed zapisem → wartość w DB to %, np. 42.5).
- `SectorsController` jest protected przez `requireAdmin()` (nie `requireAuth()`) —
  nowy endpoint historii dziedziczy to samo.
- Na starcie historia będzie pusta przez ~1 dobę — stan "Brak danych" musi być elegancki.

## What We're NOT Doing

- Nie zmieniamy tabeli `peer_medians` ani logiki scoringu.
- Nie dodajemy historii na poziomie industy-only w pierwszej fazie — ikona przy branżach
  w fazie 2, ale ten sam endpoint.
- Nie robimy paginacji ani window 12M na starcie — all-time data, limit 12M można dodać
  gdy historia urośnie (jednolinijkowa zmiana WHERE).
- Nie eksportujemy danych (CSV/PNG) — out of scope.
- Nie robimy alertów/progów na trendach — out of scope.

## Implementation Approach

**Faza 1**: Nowa migracja SQL `020_create_peer_medians_history.sql` + INSERT do nowej
tabeli w `upsertMedian()` po istniejącym upsert. Zero zmian w odczycie/scoringu.

**Faza 2**: Nowa metoda `findHistory()` w repozytorium + endpoint `GET /admin/sectors/history`
w `SectorsController` + modal + JS w `templates/admin/sectors.php`. Chart.js i modal CSS
są już załadowane — tylko HTML + JS do dodania.

## Critical Implementation Details

- **GM% skala**: wartość w `peer_medians_history.median_value` dla metryki `gm` jest
  w procentach (np. 42.5), nie ułamku. Prawa oś Y powinna mieć format `N%` i zakres
  0–100. Lewa oś Y (EV/FCF, EV/Sales) typowo 5–50.
- **AJAX security**: nowy endpoint musi sprawdzać `requireAdmin()` + walidować `level`,
  `bucket_key` i `metric_type` przez whitelist (tak samo jak `refresh()` waliduje sektor
  przez whitelist). Bez tego dowolny string trafi do SQL przez `findHistory()`.
- **Chart.js canvas reuse**: każde otwarcie modalu musi niszczyć poprzednią instancję
  Chart.js (`Chart.getChart(canvas)?.destroy()`) przed `new Chart()`. Inaczej "Canvas
  is already in use" error przy drugim otwarciu.
- **Modal jeden shared**: jeden `<div id="sector-history-modal">` dla wszystkich sektorów
  i branż. Tytuł i dane podmieniane przez JS przy otwarciu — nie tworzyć osobnego modalu
  per wiersz.

---

## Phase 1: Tabela historii i write-path

### Overview

Tworzymy append-only tabelę `peer_medians_history` i rozszerzamy `upsertMedian()`
o INSERT do historii po każdym upsert. Scoring i odczyty panelu są bez zmian.

### Changes Required

#### 1. Migracja SQL

**File**: `database/migrations/020_create_peer_medians_history.sql`

**Intent**: Tworzy append-only tabelę z tą samą strukturą co `peer_medians`,
bez UNIQUE KEY — każde odświeżenie dopisuje nowy wiersz.

**Contract**: Kolumny: `id`, `level`, `bucket_key`, `parent_sector`, `model_version`,
`metric_type`, `median_value`, `sample_count`, `snapshotted_at DATETIME DEFAULT CURRENT_TIMESTAMP`.
Indeksy: `(level, bucket_key, metric_type)` dla szybkiego odczytu historii,
`(snapshotted_at)` dla filtrów czasowych.

#### 2. PeerMedianRepository — zapis historii

**File**: `src/CVS/Valuation/PeerMedianRepository.php`

**Intent**: Po istniejącym upsert w `upsertMedian()` dodaj INSERT do
`peer_medians_history`. Błąd INSERT w historii loguje, ale nie przerywa upsert
(try/catch — history is best-effort, nie blokujemy scoringu).

**Contract**: Nowa prywatna metoda `insertHistory(...)` lub rozszerzenie
istniejącego bloku try/catch w `upsertMedian()`. Sygnatura INSERT:
`(level, bucket_key, parent_sector, model_version, metric_type, median_value,
sample_count, snapshotted_at=NOW())`.

#### 3. Test jednostkowy

**File**: `tests/CVS/Valuation/PeerMedianRepositoryTest.php`

**Intent**: Weryfikacja że po `upsertMedian()` wiersz ląduje w `peer_medians_history`.
Test używa in-memory SQLite lub testowej bazy — sprawdź jak inne testy w tym pliku
mockują DB.

### Success Criteria

#### Automated Verification

- Migracja aplikuje się bez błędów: `php -r "require 'vendor/autoload.php'; /* run SQL */"`
- Testy przechodzą: `vendor/bin/phpunit`
- PHPStan: `vendor/bin/phpstan analyse` (no errors)

#### Manual Verification

- Po wywołaniu `POST /admin/sectors/refresh` (lub ręcznym uruchomieniu
  `php bin/refresh_peer_medians.php --sector=Technology`) w tabeli
  `peer_medians_history` pojawia się 3–6 nowych wierszy (ev_fcf, ev_sales, gm
  × sector + industry).
- Odświeżenie `/admin/sectors` działa normalnie — dane nie zmieniły się.
- Drugi refresh → wiersze w `peer_medians_history` rosną (append, nie nadpisanie).

**Implementation Note**: Po przejściu automated verification — poczekaj na ręczne
potwierdzenie że refresh zapisuje historię przed przejściem do Fazy 2.

---

## Phase 2: Endpoint AJAX + modal z wykresem

### Overview

Nowy endpoint `GET /admin/sectors/history` zwraca JSON z historią dla danego
sektora/branży. Widok `templates/admin/sectors.php` dostaje ikonkę wykresu przy
każdym wierszu oraz shared modal z Chart.js multiline dual-axis.

### Changes Required

#### 1. PeerMedianRepository — odczyt historii

**File**: `src/CVS/Valuation/PeerMedianRepository.php`

**Intent**: Nowa publiczna metoda `findHistory()` zwraca punkty historii dla
danego sektora/branży i metryki — lub dla wszystkich trzech metryk naraz (jeden
query, grupowane w PHP na trzy tablice).

**Contract**:
```
findHistory(string $level, string $bucketKey, string $modelVersion): array
```
Zwraca:
```php
[
  'labels'  => ['2026-06-16', '2026-06-17', ...],   // snapshotted_at DATE (Y-m-d)
  'ev_fcf'  => [18.2, 17.9, ...],                    // float|null per data point
  'ev_sales' => [4.1, 4.3, ...],
  'gm'      => [42.5, 41.8, ...],
]
```
SELECT z `peer_medians_history` WHERE `level=? AND bucket_key=? AND model_version=?`,
ORDER BY `snapshotted_at ASC`. Pivot na trzy tablice w PHP.
Brak ograniczenia czasowego (all-time) — można dodać `LIMIT` lub `WHERE snapshotted_at >=`
w przyszłości jednolinijkową zmianą.

#### 2. SectorsController — endpoint historii

**File**: `src/Admin/SectorsController.php`

**Intent**: Nowa publiczna metoda `history(Request $req)` obsługuje
`GET /admin/sectors/history` — sprawdza admina, waliduje parametry przez whitelist,
zwraca JSON z historią.

**Contract**: Parametry GET: `level` (whitelist: `sector`, `industry`),
`bucket_key` (string, niepusty, max 150 znaków — nie musi być na whiteliście, bo
`findHistory()` używa prepared statements), `model_version` (default z config).
Response: `{'ok': true, 'data': {'labels': [...], 'ev_fcf': [...], ...}}` lub
`{'ok': false, 'message': '...'}` z HTTP 4xx.
Brak CSRF wymagany (GET, read-only, admin-only).

#### 3. Rejestracja route

**File**: `src/Core/routes.php`

**Intent**: Dodaj `GET /admin/sectors/history` wskazujący na
`$sectors->history($req)`.

#### 4. Widok — ikona wykresu przy wierszach

**File**: `templates/admin/sectors.php`

**Intent**: Dodaj ikonkę `📊` (lub SVG `<button>`) przy każdym wierszu sektora
i każdym wierszu branży (expandable rows). Kliknięcie ustawia `data-level` i
`data-bucket` na shared modal i go otwiera.

**Contract**: Przycisk `<button class="btn-sector-chart btn--ghost btn--sm"
data-level="sector" data-bucket="Technology">📊</button>` w ostatniej kolumnie
każdego wiersza. Analogicznie `data-level="industry"` dla wierszy branży.

#### 5. Widok — shared modal + Chart.js

**File**: `templates/admin/sectors.php`

**Intent**: Jeden `<div id="sector-history-modal" class="ai-modal" hidden>` na
dole szablonu. Zawiera: tytuł (nazwa sektora/branży), canvas `#sector-history-chart`,
komunikat empty-state, przycisk zamknięcia. JS obsługuje: otwarcie (fetch AJAX →
Chart.js), zamknięcie, click-backdrop, destroy poprzedniej instancji Chart przed
nowym renderem.

**Contract**: Modal ma `max-width: 700px` (szerzej niż company-modal 580px bo wykres
potrzebuje miejsca). Chart: `type: 'line'`, trzy datasets z `yAxisID`:
- `ev_fcf`: `yAxisID: 'y'` (lewa), kolor `rgba(64, 144, 224, 0.9)` (jak swing)
- `ev_sales`: `yAxisID: 'y'`, kolor `rgba(250, 204, 21, 0.9)` (jak fundamental)
- `gm`: `yAxisID: 'y1'` (prawa), kolor `rgba(52, 211, 153, 0.9)` (zielony)

Scales config:
```js
scales: {
  y:  { position: 'left',  title: { display: true, text: 'Mnożnik (×)' } },
  y1: { position: 'right', title: { display: true, text: 'GM%' },
        min: 0, max: 100, grid: { drawOnChartArea: false } }
}
```
Empty state: gdy `labels.length === 0` → ukryj canvas, pokaż `<p class="empty-state">
Brak danych historycznych. Dane zaczną się gromadzić od następnego odświeżenia.</p>`.

### Success Criteria

#### Automated Verification

- Testy przechodzą: `vendor/bin/phpunit`
- PHPStan: `vendor/bin/phpstan analyse` (no errors)
- PHP lint szablonu: `php -l templates/admin/sectors.php`
- Endpoint zwraca 200 z `ok:true` dla istniejącego sektora (np. Technology)
- Endpoint zwraca 400 dla nieprawidłowego `level`

#### Manual Verification

- Ikona 📊 widoczna przy każdym sektorze w tabeli.
- Ikona 📊 widoczna przy rozwiniętych wierszach branży.
- Kliknięcie ikony otwiera modal z tytułem "Historia: Technology" (lub nazwa branży).
- Gdy brak historii: widoczny komunikat "Brak danych historycznych...".
- Po wykonaniu refresh jednego sektora i ponownym kliknięciu: wykres z pierwszym punktem.
- Po kolejnym kliknięciu drugiego sektora: poprzedni wykres zniszczony, nowy wyrenderowany
  (brak "Canvas already in use" w konsoli).
- Kliknięcie poza modalem (backdrop) zamyka go.
- Trzy serie widoczne w legendzie, prawa oś Y z etykietą "GM%", lewa "Mnożnik (×)".
- Responsywność: modal nie wychodzi poza ekran na 1280px szerokości.

**Implementation Note**: Po przejściu automated checks — przeprowadź pełny test manualny
(otwórz, zamknij, zmień sektor, sprawdź konsolę) przed zamknięciem fazy.

---

## Testing Strategy

### Unit Tests

- `PeerMedianRepositoryTest`: po `upsertMedian()` → `peer_medians_history` ma nowy wiersz.
- `PeerMedianRepositoryTest::findHistory()`: zwraca poprawną strukturę, puste tablice gdy
  brak wierszy.

### Integration Tests

- Manualne: pełny cykl refresh → `/admin/sectors/history` → modal → wykres.

### Manual Testing Steps

1. Wywołaj `POST /admin/sectors/refresh` dla jednego sektora.
2. Sprawdź `SELECT * FROM peer_medians_history LIMIT 10` — wiersze obecne.
3. Otwórz `/admin/sectors`, kliknij ikonę 📊 przy tym sektorze.
4. Modal otwiera się, po ~300ms pojawia się wykres z 1 punktem (lub >1 jeśli cron zdążył).
5. Zamknij modal, kliknij ikonę przy innym sektorze — nowy wykres bez błędów konsoli.
6. Kliknij ikonę przy wierszu branży (rozwiń sektor) — analogiczne zachowanie.
7. Sprawdź responsywność przy 1280px i 1920px.

## References

- Wzorzec modal: `templates/analysis.php:40–179` (company-info modal)
- Wzorzec dual-axis Y: `templates/analysis.php:1261–1394` (forecast fan chart)
- Wzorzec AJAX endpoint: `src/Admin/SectorsController.php:66–103` (refresh)
- Wzorzec upsert + PDO: `src/CVS/Valuation/PeerMedianRepository.php:47–119`
- Existing chart colors: `templates/track-record-ticker.php:37–94`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Tabela historii i write-path

#### Automated

- [x] 1.1 Migracja 020 aplikuje się bez błędów — 595d9f6
- [x] 1.2 Testy przechodzą: vendor/bin/phpunit — 595d9f6
- [x] 1.3 PHPStan: vendor/bin/phpstan analyse (no errors) — 595d9f6

#### Manual

- [x] 1.4 POST /admin/sectors/refresh zapisuje wiersze w peer_medians_history — 595d9f6
- [x] 1.5 Drugi refresh dopisuje nowe wiersze (append, nie nadpisanie) — 595d9f6
- [x] 1.6 Panel /admin/sectors działa normalnie po migracji — 595d9f6

### Phase 2: Endpoint AJAX + modal z wykresem

#### Automated

- [x] 2.1 Testy przechodzą: vendor/bin/phpunit — fbc56cb
- [x] 2.2 PHPStan: vendor/bin/phpstan analyse (no errors) — fbc56cb
- [x] 2.3 PHP lint szablonu: php -l templates/admin/sectors.php — fbc56cb
- [x] 2.4 Endpoint GET /admin/sectors/history zwraca 200 z ok:true dla istniejącego sektora — fbc56cb
- [x] 2.5 Endpoint zwraca 400 dla nieprawidłowego parametru level — fbc56cb

#### Manual

- [x] 2.6 Ikona wykresu widoczna przy każdym wierszu sektora — fbc56cb
- [x] 2.7 Ikona wykresu widoczna przy rozwiniętych wierszach branży — fbc56cb
- [x] 2.8 Modal otwiera się z tytułem i pustym komunikatem (brak historii) — fbc56cb
- [x] 2.9 Po refresh: modal pokazuje wykres z punktem danych — fbc56cb
- [x] 2.10 Zmiana sektora: poprzedni wykres zniszczony, brak błędów konsoli — fbc56cb
- [x] 2.11 Backdrop click zamyka modal — fbc56cb
- [x] 2.12 Trzy serie w legendzie, dual-axis Y poprawnie opisane — fbc56cb
