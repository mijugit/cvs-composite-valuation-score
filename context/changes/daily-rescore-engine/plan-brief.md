# F-04: Dzienny silnik re-scoringu — Plan Brief

> Full plan: `context/changes/daily-rescore-engine/plan.md`

## What & Why

Cron job uruchamiany 2× dziennie (po otwarciu i zamknięciu NYSE) re-scoruje
unię watchlisty wszystkich userów i zapisuje pełne snapshoty CVS do nowej tabeli
`cvs_snapshots`. Jeden mechanizm karmi trzy przyszłe slice'y: S-02 track record,
S-03 screener, S-04 alerty. Snapshoty trzeba zbierać od teraz — im wcześniej,
tym pełniejszy track record.

## Starting Point

Aplikacja oblicza CVS on-demand (na żądanie usera); brak gromadzenia historii
maszynowej, brak tabeli snapshotów, brak CLI entry pointa w `bin/`. `FinancialDataFetcher`
cache'uje przez `$_SESSION` — w CLI trzeba to obejść.

## Desired End State

`bin/rescore.php` uruchamiany przez cron na CF re-scoruje cały zbiór obserwowanych
tickerów i zapisuje do `cvs_snapshots` (jeden wiersz per ticker per dzień, nadpisywany
przy drugim uruchomieniu). Po kilku tygodniach tabela zawiera wystarczającą historię
żeby S-02/S-03/S-04 zaczęły mieć sens.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego | Źródło |
|---|---|---|---|
| Cache CLI | `$_SESSION = []` na starcie skryptu | Zero zmian w fetcher, in-memory per run | Plan |
| Idempotencja | `UNIQUE(ticker, score_date)` + `ON DUPLICATE KEY UPDATE` | Baza gwarantuje, drugi run nadpisuje świeższymi danymi | Plan |
| Cron schedule | 2× dziennie pon–pt (15:30 + 22:00 CET) | PRD FR-010: po otwarciu i po zamknięciu NYSE | PRD |
| Błąd tickera | Skip + error_log, kontynuuj batch | Jeden nieudany ticker nie zatrzymuje całego re-scoringu | Plan |
| Storage | Wyłącznie `cvs_snapshots` (nie `analysis_history`) | Czysta separacja: historia usera vs dane maszynowe | Plan |
| Schema | Pełny snapshot (dual CVS, reko, signal, gate, JSON pilarów) | Jeden schemat karmi S-02/03/04 bez późniejszych migracji | Plan |
| Lazy-trigger | Pominięty w tej fazie | Cron CF potwierdzony; prostota > fallback którego nie potrzeba | Plan |

## Scope

**In scope:** `database/migrations/004_create_cvs_snapshots.sql`, `src/TrackRecord/CvsSnapshotRepository.php`,
`UserRepository::findAll()`, `WatchlistRepository::findAllDistinctTickers()`,
`bin/rescore.php`, konfiguracja 2 cron jobów na CF.

**Out of scope:** alerty mailowe (S-04), screener UI (S-03), track record widok (S-02),
lazy-trigger HTTP, refaktor FinancialDataFetcher.

## Architecture / Approach

```
Cron (CF, 2× dziennie)
  └─ /usr/local/bin/php84 bin/rescore.php
       ├─ $_SESSION = []   ← workaround dla CLI
       ├─ WatchlistRepository::findAllDistinctTickers()  → [AAPL, MSFT, ...]
       └─ foreach ticker:
            ├─ FinancialDataFetcher::fetch(ticker)
            ├─ CVSModel::calculate(ticker, financials)
            └─ CvsSnapshotRepository::save(ticker, result)
                 └─ INSERT ... ON DUPLICATE KEY UPDATE (cvs_snapshots)
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Migracja SQL | Tabela `cvs_snapshots` z UNIQUE KEY | Brak — addytywna migracja |
| 2. Repozytoria | `CvsSnapshotRepository`, `findAll()`, `findAllDistinctTickers()` | PHPStan strict types na JSON NULL |
| 3. bin/rescore.php | CLI skrypt łączący wszystko, testowalny lokalnie | `$_SESSION` w CLI, Yahoo throttling |
| 4. Deploy + cron | Dwa cron joby na CF, wiersze w bazie | Ścieżka PHP84 na CF, max_execution_time |

**Prerequisites:** Brak (F-04 jest niezależnym fundamentem).
**Estimated effort:** ~1–2 sesje, 4 fazy.

## Open Risks & Assumptions

- Yahoo Finance throttling przy >20 tickerach może wydłużyć run; skip+log
  per ticker minimalizuje ryzyko zablokowania całego batcha.
- `pillar_scores` i `gate_failures` jako JSON NULL — PHPStan może wymagać
  jawnego rzutowania przy odczycie.

## Success Criteria (Summary)

- `bin/rescore.php` uruchamiany manualnie przez SSH loguje `done. success=N failed=M`.
- Po uruchomieniu `SELECT COUNT(*) FROM cvs_snapshots WHERE score_date = CURDATE()`
  zwraca > 0.
- Drugi run tego samego dnia nie duplikuje wierszy (idempotencja przez UNIQUE KEY).
