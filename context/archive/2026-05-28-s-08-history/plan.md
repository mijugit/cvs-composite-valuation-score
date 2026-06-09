---
id: s-08-history
title: "Historia analiz"
status: planned
created: 2026-05-28
updated: 2026-05-28
---

## Overview

Zapisujemy wyniki analiz do tabeli `analysis_history` i pokazujemy je
na dashboardzie jako klikalną listę (ostatnie 20, od najnowszej).
Klik → `/analysis/{ticker}` (live re-run, nie replay z bazy).

### Scope

- **IN:** nowa tabela, `HistoryRepository`, auto-save w `analyse()`,
  nowa sekcja na dashboardzie, config `max_history`
- **OUT:** replay pełnego wyniku z bazy, eksport CSV/PDF, filtrowanie/paginacja

### Data flow

```
POST /analysis
  → foreach ticker: fetch() → calculate()
  → HistoryRepository::save($userId, $ticker, $result->toArray())   ← NEW
  → Response::json(['results' => ...])

GET /dashboard
  → HistoryRepository::findByUser($userId, $maxHistory)             ← NEW
  → Response::view('dashboard', [..., 'history' => $history])
```

### Schema — analysis_history

```sql
id            INT UNSIGNED AUTO_INCREMENT PK
user_id       INT UNSIGNED NOT NULL  FK→users(id) ON DELETE CASCADE
ticker        VARCHAR(20)  NOT NULL
cvs_swing     DECIMAL(5,2) NULL      -- null jeśli gate fail
cvs_fund      DECIMAL(5,2) NULL
reco_swing    VARCHAR(50)  NULL
reco_fund     VARCHAR(50)  NULL
golden_signal VARCHAR(20)  NULL
quality_gate  TINYINT(1)   NOT NULL DEFAULT 0
analysed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
INDEX (user_id, analysed_at DESC)
```

---

## Phase 1: DB migration + HistoryRepository + testy

### Overview

Fundament: SQL + klasa repozytorium + testy SQLite in-memory.
Brak zmian w istniejących plikach.

### Changes Required

- `database/migrations/003_create_analysis_history.sql`
  — CREATE TABLE analysis_history (schemat jak wyżej)

- `src/History/HistoryRepository.php`
  — namespace `CVS\History`
  — constructor: `?PDO $db = null` (opcjonalna injekcja jak w WatchlistRepository)
  — `save(int $userId, string $ticker, array $result): void`
    · INSERT: ticker, cvs_swing, cvs_fund, reco_swing, reco_fund, golden_signal, quality_gate
    · Odczytuje pola z `$result['swing']['cvs']`, `$result['fundamental']['cvs']` itd.
    · quality_gate: `(int) ($result['quality_gate'] ?? 0)`
  — `findByUser(int $userId, int $limit): array`
    · SELECT ... ORDER BY analysed_at DESC LIMIT :limit
    · Zwraca array asoc z kluczami: ticker, cvs_swing, cvs_fund, reco_swing, reco_fund,
      golden_signal, quality_gate, analysed_at

- `tests/History/HistoryRepositoryTest.php`
  — SQLite in-memory (jak WatchlistRepositoryTest)
  — Schema CREATE TABLE dostosowany do SQLite (DECIMAL→REAL, TINYINT→INTEGER)
  — Testy:
    · `save_stores_gate_pass_result`
    · `save_stores_gate_fail_result`
    · `save_stores_null_scores_for_fail`
    · `find_returns_empty_for_new_user`
    · `find_returns_most_recent_first`
    · `find_respects_limit`
    · `find_is_scoped_to_user`
    · `save_multiple_same_ticker_allowed`

### Success Criteria

#### Automated
- [ ] `vendor/bin/phpunit` — 0 failed, ≥ 55 assertions total (8 nowych)

#### Manual
- [ ] Brak (faza czysto backendowa)

---

## Phase 2: Auto-save w AnalysisController

### Overview

Podpinamy zapis do istniejącego flow w `analyse()`.
Dwa pliki — controller i config.

### Changes Required

- `config/cvs-weights.php` → `data_source` section:
  — Dodaj `'max_history' => 20`

- `src/CVS/AnalysisController.php`:
  — `use CVS\History\HistoryRepository;`
  — `private HistoryRepository $history;`
  — W konstruktorze: `$this->history = new HistoryRepository();`
  — `private int $maxHistory;` ← `$config['data_source']['max_history'] ?? 20`
  — W `analyse()`, wewnątrz foreach, po `$result = $this->model->calculate(...)`:
    ```php
    $this->history->save($userId, $ticker, $result->toArray());
    ```
  — `$userId` pobrać przed pętlą: `$userId = (int) $_SESSION['user_id'];`
  — Jeśli `$financials === null` → NIE zapisujemy (brak danych = nie ma co zapisać)
  — W `dashboard()`: dodaj `$history = $this->history->findByUser($userId, $this->maxHistory);`
    i przekaż do widoku: `'history' => $history`

### Success Criteria

#### Automated
- [ ] `vendor/bin/phpunit` — 0 failed (brak nowych testów jednostkowych dla controllera)

#### Manual
- [ ] Wykonaj analizę AAPL przez dashboard → sprawdź w DB:
  `SELECT * FROM analysis_history ORDER BY analysed_at DESC LIMIT 5;`
- [ ] Wpis ma poprawne: ticker=AAPL, cvs_swing≠null, quality_gate=1

---

## Phase 3: Dashboard history UI

### Overview

Nowa sekcja na dashboardzie pokazująca ostatnie 20 analiz.
Dwa pliki — template + CSS.

### Changes Required

- `templates/dashboard.php`:
  — Dodaj sekcję `.history-section.card` MIĘDZY watchlist a formularzem analiz
  — Ukryta gdy `$history` pusta: `<?php if (!empty($history)): ?>`
  — Tabela z kolumnami: Ticker | CVS Swing | Rekomendacja | Data
  — Każda linia Ticker → link `<a href="/analysis/<?= urlencode($row['ticker']) ?>">`
  — CVS Swing: `number_format($row['cvs_swing'], 1)` lub `—` gdy null (gate fail)
  — Rekomendacja: `$row['reco_swing']` lub `Odrzucono` gdy quality_gate=0
  — Data: `date('d M', strtotime($row['analysed_at']))` (np. "28 maj")
  — Max `$maxHistory` wierszy (już ograniczone przez findByUser)

- `public/css/app.css`:
  — `.history-section` — analogicznie do `.watchlist-section`
  — `.history-table` — kompaktowa tabela: font-size .85rem, linia oddzielająca
  — `.history-table a` — link bez podkreślenia, kolor var(--c-primary)
  — `.history-table .gate-fail` — kolor var(--c-muted) dla odrzuconych wierszy

### Success Criteria

#### Automated
- [ ] `vendor/bin/phpunit` — 0 failed

#### Manual
- [ ] Zaloguj się, wykonaj kilka analiz → dashboard pokazuje historię
- [ ] Klik w ticker → otwiera `/analysis/{ticker}`
- [ ] Analiza odrzucona przez gate → wiersz z `—` w CVS i "Odrzucono" w reko
- [ ] Świeży user bez historii → sekcja ukryta (brak pustej karty)
- [ ] Analiza 21+ tickerów łącznie → lista pokazuje max 20, bez błędów

---

## Progress

### Phase 1: DB migration + HistoryRepository + testy

#### Automated
- [x] 1.1 Utwórz 003_create_analysis_history.sql — e23898e
- [x] 1.2 Utwórz src/History/HistoryRepository.php — e23898e
- [x] 1.3 Utwórz tests/History/HistoryRepositoryTest.php — e23898e
- [x] 1.4 Wszystkie testy zielone (≥55 assertions) — e23898e

#### Manual
- [x] 1.M1 Brak weryfikacji manualnej — faza backendowa — e23898e

### Phase 2: Auto-save w AnalysisController

#### Automated
- [x] 2.1 Dodaj max_history do config/cvs-weights.php — b7e679d
- [x] 2.2 Wstrzyknij HistoryRepository do AnalysisController — b7e679d
- [x] 2.3 Dodaj history->save() w pętli analyse() — b7e679d
- [x] 2.4 Przekaż $history do dashboard() — b7e679d
- [x] 2.5 Testy zielone — b7e679d

#### Manual
- [x] 2.M1 Weryfikacja zapisu w DB po analizie — b7e679d

### Phase 3: Dashboard history UI

#### Automated
- [x] 3.1 Dodaj sekcję historii do templates/dashboard.php — 645701b
- [x] 3.2 Dodaj style .history-section i .history-table do app.css — 645701b
- [x] 3.3 Testy zielone — 645701b

#### Manual
- [x] 3.M1 Weryfikacja UI historii na dashboardzie — 645701b
