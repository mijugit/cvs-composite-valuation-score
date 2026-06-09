# F-04: Dzienny silnik re-scoringu + snapshoty CVS — Implementation Plan

## Overview

Zadanie CLI (`bin/rescore.php`) uruchamiane cronem 2× dziennie (15:30 CET
po otwarciu NYSE + 22:30 CET po zamknięciu) re-scoruje unię watchlist wszystkich
userów i zapisuje pełne snapshoty CVS do nowej tabeli `cvs_snapshots`. Jeden
mechanizm karmi trzy slice'y fazy 2: S-02 (track record), S-03 (screener),
S-04 (alerty). Snapshoty trzeba zacząć gromadzić jak najwcześniej — im później,
tym dłużej track record będzie rzadki.

## Current State Analysis

- `CVSModel::calculate(ticker, financials)` → `CVSResult` z dual-mode scores
  istnieje i jest deterministyczny.
- `FinancialDataFetcher::fetch()` używa `$_SESSION` jako cache — w CLI brak sesji;
  workaround: `$_SESSION = []` na starcie skryptu, fetcher działa bez zmian.
- `WatchlistRepository` ma tylko `findByUser(int $userId)` — brak metody unii.
- `UserRepository` nie ma `findAll()`.
- Brak tabeli `cvs_snapshots` (istnieje tylko `analysis_history` dla analiz
  inicjowanych przez usera).
- Brak katalogu `bin/` — trzeba go stworzyć.
- CRON na Cyber_Folks potwierdzony (2026-05-29): typ „Ścieżka", harmonogram
  co do minuty; PHP CLI domyślnie 7.4 → wymagana jawna ścieżka `/usr/local/bin/php84`.

## Desired End State

- Tabela `cvs_snapshots` istnieje i jest populowana 2× dziennie.
- `bin/rescore.php` wykonuje się idempotentnie — ponowne uruchomienie tego
  samego dnia nadpisuje (nie duplikuje) snapshoty dla tego dnia.
- Każdy ticker z unii watchlist wszystkich userów ma snapshot z bieżącego dnia
  (lub poprzedniego, jeśli cron jeszcze nie chodził).
- PHPUnit i PHPStan nadal zielone.
- Na serwerze skonfigurowane 2 cron joby i zweryfikowane wierszami w bazie.

### Key Discoveries

- `FinancialDataFetcher::fetch()` — `$_SESSION['cvs_fin_' . $ticker]`;
  inicjalizacja `$_SESSION = []` w `bin/rescore.php` pozwala reużyć fetchera
  bez modyfikacji. Cały fetch jest świeży per run (pożądane dla dziennego cronaj).
- `config/cvs-weights.php → data_source.timeout_seconds = 25` — per-ticker
  timeout Yahoo Finance, obowiązuje też w CLI.
- `CVSResult::toArray()` zwraca `swing`, `fundamental`, `golden_signal`,
  `pillar_scores`, `quality_gate`, `gate_failures` — wszystkie pola do snapshotu.
- UNIQUE KEY `(ticker, score_date)` + `ON DUPLICATE KEY UPDATE` — idempotencja
  bez guard-query w PHP; drugie uruchomienie tego dnia aktualizuje snapshot
  świeższymi danymi po zamknięciu notowań.
- Namespace dla repozytorium: `CVS\TrackRecord\` (per CLAUDE.md Phase 2 conventions).

## What We're NOT Doing

- Lazy-trigger przy pierwszym requeście HTTP — wyłącznie cron.
- Zapis do `analysis_history` — wyłącznie `cvs_snapshots`.
- Refaktor `FinancialDataFetcher` (cache strategy injection) — `$_SESSION = []`
  w skrypcie wystarczy.
- Re-scoring pełnego słownika ~600 spółek — tylko unia watchlist.
- Alerty mailowe (S-04) ani screener UI (S-03) — to osobne slice'y.

## Implementation Approach

4 fazy w kolejności zależności:
1. Migracja SQL → nowa tabela `cvs_snapshots`.
2. `CvsSnapshotRepository` + rozszerzenia `UserRepository` / `WatchlistRepository`.
3. `bin/rescore.php` — CLI skrypt spinający wszystko.
4. Deploy + konfiguracja crona + weryfikacja na żywo.

Fazy 1–3 są lokalne i testowalne przed deployem. Faza 4 wymaga SSH na CF.

## Critical Implementation Details

**`$_SESSION` w CLI:** PHP nie startuje sesji automatycznie w CLI. Bez `$_SESSION = []`
na starcie skryptu `FinancialDataFetcher` wrzuci notice na `$_SESSION['cvs_fin_...']`.
Jedna linia `$_SESSION = [];` przed pierwszym `fetch()` w `bin/rescore.php` rozwiązuje
to bez dotykania klasy fetchera.

**Cron + PHP CLI na Cyber_Folks:** Domyślny `php` w cronie CF to 7.4. Ścieżka
absolutna `/usr/local/bin/php84 /home/<cf-user>/sites/cvs.timeflow.fun/bin/rescore.php`
jest konieczna. Typ crona: „Ścieżka" (bez max_execution_time, kluczowe dla batcha).

**`ON DUPLICATE KEY UPDATE` zamiast INSERT IGNORE:** INSERT IGNORE cicho
pomija duplikaty bez aktualizowania danych. ON DUPLICATE KEY UPDATE pozwala
drugiemu uruchomieniu crona (po zamknięciu) nadpisać snapshots ze świeższymi
danymi. To pożądane zachowanie.

---

## Phase 1: Migracja — tabela cvs_snapshots

### Overview

Tworzy tabelę `cvs_snapshots` z pełnym schematem (dual-mode CVS, reko, signal,
gate, JSON pilarów) i unikalnym constraintem per (ticker, dzień).

### Changes Required

#### 1. Plik migracji SQL

**File:** `database/migrations/004_create_cvs_snapshots.sql`

**Intent:** Nowa tabela przechowująca dzienne snapshoty CVS dla całego mechanizmu
re-scoringu — źródło danych dla S-02 (track record), S-03 (screener), S-04 (alerty).

**Contract:**
```sql
CREATE TABLE IF NOT EXISTS cvs_snapshots (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticker        VARCHAR(20)  NOT NULL,
    score_date    DATE         NOT NULL,
    scored_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cvs_swing     DECIMAL(5,2) NULL,
    cvs_fund      DECIMAL(5,2) NULL,
    reco_swing    VARCHAR(60)  NULL,
    reco_fund     VARCHAR(60)  NULL,
    golden_signal VARCHAR(20)  NULL,
    quality_gate  TINYINT(1)   NOT NULL DEFAULT 0,
    gate_failures JSON         NULL,
    pillar_scores JSON         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticker_day (ticker, score_date),
    INDEX idx_score_date (score_date),
    INDEX idx_ticker_date (ticker, score_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (brak zmian PHP)
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- Migracja wykonana na CF: `mysql ... < database/migrations/004_create_cvs_snapshots.sql`
- `SHOW CREATE TABLE cvs_snapshots` — tabela istnieje z UNIQUE KEY i indeksami

---

## Phase 2: Repozytoria i rozszerzenia

### Overview

Tworzy `CvsSnapshotRepository` + dodaje `UserRepository::findAll()` i
`WatchlistRepository::findAllDistinctTickers()`.

### Changes Required

#### 1. CvsSnapshotRepository

**File:** `src/TrackRecord/CvsSnapshotRepository.php` (namespace `CVS\TrackRecord`)

**Intent:** Jedyne miejsce w kodzie, które zapisuje i czyta snapshoty CVS.
Używane przez `bin/rescore.php` do zapisu i przez przyszłe S-02/S-03/S-04 do odczytu.

**Contract:** Klasa z czterema metodami publicznymi:
- `save(string $ticker, array $cvsResultArray): void` — insert + ON DUPLICATE KEY UPDATE;
  przyjmuje wynik `CVSResult::toArray()`, wyodrębnia pola, wstawia snapshot dla
  bieżącej daty; nie rzuca wyjątku — łapie PDOException i loguje przez `error_log`.
- `findLatestByTicker(string $ticker): ?array` — ostatni snapshot dla tickera
  (po `score_date DESC LIMIT 1`); zwraca null jeśli brak.
- `findAllLatest(): array` — najnowszy snapshot per ticker dla całego zbioru
  (sub-select MAX(score_date) per ticker); dla S-03 screener.
- `findByTickerSince(string $ticker, \DateTimeImmutable $since): array` — historia
  per ticker od daty (dla S-02 track record).

#### 2. UserRepository::findAll()

**File:** `src/Auth/UserRepository.php`

**Intent:** Zwróć wszystkich zarejestrowanych userów; używane przez `bin/rescore.php`
do iteracji po wszystkich watchlistach.

**Contract:** `public function findAll(): array` — `SELECT id, email FROM users
ORDER BY id`; zwraca `array<int, array{id: int, email: string}>`.

#### 3. WatchlistRepository::findAllDistinctTickers()

**File:** `src/Watchlist/WatchlistRepository.php`

**Intent:** Zwróć unikalne tickery ze wszystkich watchlist wszystkich userów —
unia watchlisty wejściowej dla silnika re-scoringu.

**Contract:** `public function findAllDistinctTickers(): array` — `SELECT DISTINCT
ticker FROM watchlist ORDER BY ticker`; zwraca `string[]`.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (dodać testy dla 3 nowych metod)
- `vendor/bin/phpstan analyse` zielony (strict types, typed returns)

#### Manual Verification
- `CvsSnapshotRepository::save()` wstawia wiersz do lokalnej bazy testowej
  (lub weryfikacja przez unit test z mock PDO)

---

## Phase 3: bin/rescore.php — CLI entry point

### Overview

Główny skrypt CLI uruchamiany przez cron. Łączy wszystkie komponenty,
przetwarza unię watchlisty ticket-po-tickerze, zapisuje snapshoty.

### Changes Required

#### 1. CLI entry point

**File:** `bin/rescore.php`

**Intent:** Idempotentny skrypt CLI do codziennego re-scoringu. Bootstrapuje
środowisko PHP bez HTTP, pobiera unię watchlisty, re-scoruje każdy ticker,
zapisuje snapshot, loguje wynik każdego ticker i podsumowanie końcowe.

**Contract:**
```
<?php declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
// CLI SAPI guard — nie uruchamiać przez HTTP
if (PHP_SAPI !== 'cli') { exit(1); }

require ROOT_PATH . '/vendor/autoload.php';
// ładowanie .env (jak w public/index.php)
// $config = require ROOT_PATH . '/config/app.php';
// $_SESSION = [];   ← krytyczne: FinancialDataFetcher używa $_SESSION
```

Algorytm:
1. Bootstrap: `.env` + autoload + `$_SESSION = []`
2. `WatchlistRepository::findAllDistinctTickers()` → `$tickers[]`
3. Jeśli brak tickerów: `error_log('rescore: watchlist empty')`, exit 0
4. Dla każdego `$ticker` w `$tickers`:
   - `FinancialDataFetcher::fetch($ticker)` → `$financials`
   - Jeśli null: `error_log("rescore: fetch failed for $ticker")`, `$failed[]`, continue
   - `CVSModel::calculate($ticker, $financials)` → `$result`
   - `CvsSnapshotRepository::save($ticker, $result->toArray())`
   - `$success[]`
5. Podsumowanie: `error_log("rescore: done. success=N failed=M")` (do logów CF)
6. exit 0

Skrypt ma pozostać krótki (<100 linii). Cała logika domenowa żyje w klasach.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- `php -l bin/rescore.php` bez błędów składni

#### Manual Verification
- Lokalne uruchomienie (z prawdziwym `.env`):
  `php bin/rescore.php` → log pokazuje `rescore: done. success=N failed=M`
- Po uruchomieniu SELECT z `cvs_snapshots` pokazuje wiersze z `score_date = today`

---

## Phase 4: Deploy + konfiguracja crona

### Overview

Wdróż zmiany na CF, uruchom migrację, skonfiguruj 2 cron joby i zweryfikuj
że snapshoty pojawiają się w bazie.

### Changes Required

#### 1. Migracja na serwerze

**SSH command:**
```bash
mysql -h <DB_HOST> -u <DB_USER> -p'<DB_PASS>' <DB_NAME> \
  < /home/<cf-user>/sites/cvs.timeflow.fun/database/migrations/004_create_cvs_snapshots.sql
```

#### 2. Test manualny CLI na CF

```bash
/usr/local/bin/php84 /home/<cf-user>/sites/cvs.timeflow.fun/bin/rescore.php
```

Oczekiwany output w logach: `rescore: done. success=N failed=M`.

#### 3. Konfiguracja crona w panelu CF

Panel cyber_Admin → Zadania cykliczne CRON → Dodaj zadanie.
Typ: **Ścieżka**, PHP: `/usr/local/bin/php84`.

Dwa joby:
- **Rano (po otwarciu NYSE ~9:30 EST = 15:30 CET):**
  `0 15 * * 1-5  /usr/local/bin/php84 /home/<cf-user>/sites/cvs.timeflow.fun/bin/rescore.php`
- **Wieczór (po zamknięciu NYSE ~16:00 EST = 22:00 CET):**
  `0 22 * * 1-5  /usr/local/bin/php84 /home/<cf-user>/sites/cvs.timeflow.fun/bin/rescore.php`

(Tylko dni robocze, pon–pt.)

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (brak regresji)
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- `SELECT * FROM cvs_snapshots ORDER BY scored_at DESC LIMIT 5` — wiersze istnieją
- Cron uruchomiony manualnie przez panel CF → nowe wiersze w tabeli
- HTTP smoke test: https://cvs.timeflow.fun/ zwraca 200 (brak regresji)
- Drugi cron tego samego dnia → `score_date` to samo, `scored_at` aktualizowane

---

## Testing Strategy

### Unit Tests

- `CvsSnapshotRepository`: save() idempotency (insert + update), findLatest, findAllLatest
- `UserRepository::findAll()`: zwraca wszystkich userów
- `WatchlistRepository::findAllDistinctTickers()`: zwraca distinct tickers

### Integration Tests

Brak automatycznych dla CLI/cron — weryfikacja manualna przez bezpośrednie
uruchomienie i inspekcję bazy.

### Manual Testing Steps

1. Deploy + migracja na CF
2. `ssh ... '/usr/local/bin/php84 /home/.../bin/rescore.php'` — check exit 0 + logi
3. `SELECT COUNT(*), score_date FROM cvs_snapshots GROUP BY score_date` — wiersze
4. Uruchomienie 2x tego samego dnia → COUNT(*) nie rośnie (idempotencja)
5. Sprawdź że dashboard/analiza/watchlist na https://cvs.timeflow.fun/ działa OK

## Performance Considerations

Przy ~50 unikatowych tickerach w watchlistach: 50 × 25s timeout = max ~21 min
w patologicznym scenariuszu (wszystkie timeouty). Normalnie <5 min.
Typ „Ścieżka" CF bez `max_execution_time` — OK dla takiego batcha.
Yahoo Finance może throttlować; obsługujemy to przez skip+log per ticker.

## Migration Notes

Migracja `004_create_cvs_snapshots.sql` jest addytywna — nie zmienia istniejących
tabel. Bezpieczna do uruchomienia na żywej bazie. Rollback: `DROP TABLE cvs_snapshots`.

## References

- Roadmap: `context/foundation/roadmap.md` (F-04)
- PRD: `context/foundation/prd.md` (FR-010)
- Istniejący fetcher: `src/Api/FinancialDataFetcher.php` (cache przez `$_SESSION`)
- CVSModel: `src/CVS/CVSModel.php:58`
- CVSResult::toArray(): `src/CVS/CVSResult.php:152`
- UserRepository: `src/Auth/UserRepository.php`
- WatchlistRepository: `src/Watchlist/WatchlistRepository.php`
- Database singleton: `src/Core/Database.php`
- CLAUDE.md Phase 2 conventions: nowe namespace'y `CVS\TrackRecord\` etc.

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Migracja — tabela cvs_snapshots

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony — f73113e
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — f73113e

#### Manual
- [x] 1.3 Migracja wykonana na CF, tabela istnieje z UNIQUE KEY i indeksami

### Phase 2: Repozytoria i rozszerzenia

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony (testy nowych metod) — 6959455
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — 6959455

#### Manual
- [x] 2.3 `CvsSnapshotRepository::save()` wstawia wiersz poprawnie

### Phase 3: bin/rescore.php — CLI entry point

#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — 3dc26fe
- [x] 3.2 `vendor/bin/phpstan analyse` zielony — 3dc26fe
- [x] 3.3 `php -l bin/rescore.php` bez błędów składni — 3dc26fe

#### Manual
- [x] 3.4 Lokalne uruchomienie: `php bin/rescore.php` loguje `done. success=N failed=M`
- [x] 3.5 Wiersze pojawiają się w `cvs_snapshots` z `score_date = today`

### Phase 4: Deploy + konfiguracja crona

#### Automated
- [x] 4.1 `vendor/bin/phpunit` zielony
- [x] 4.2 `vendor/bin/phpstan analyse` zielony

#### Manual
- [x] 4.3 `SELECT COUNT(*), score_date FROM cvs_snapshots GROUP BY score_date` — 9 wierszy 2026-06-01
- [x] 4.4 Cron manualny przez panel CF → nowe wiersze w tabeli
- [x] 4.5 Drugi run tego samego dnia → idempotencja (COUNT nie rośnie, 9=9)
- [x] 4.6 HTTP smoke test: https://cvs.timeflow.fun/ 200, brak regresji
