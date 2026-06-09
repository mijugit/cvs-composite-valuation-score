# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## UserRepository SELECT musi zawierać wszystkie potrzebne kolumny

**Context:** `src/Auth/UserRepository.php`, `findByEmail()`.

**Problem:** `findByEmail()` miało `SELECT id, email, password_hash` — bez `is_admin`. Po dodaniu nowej kolumny `is_admin` do tabeli `users` i wpisaniu `$_SESSION['is_admin'] = (bool) $user['is_admin']` w login(), wartość była zawsze `false` mimo `is_admin=1` w DB, bo kolumna nie była pobierana.

**Rule:** Przy dodawaniu nowych kolumn do tabeli `users` (lub innej tabeli z wieloma SELECT-ami) — zawsze przejrzeć WSZYSTKIE metody w odpowiednim Repository i zaktualizować ich SELECT-y. `findByEmail()` i `findById()` mogą mieć różne pola.

**Applies to:** Każda zmiana schematu tabeli `users` (i innych tabel z wieloma read-methodami). Szczególnie gdy nowa kolumna ma być dostępna przy logowaniu — sprawdź `findByEmail()`, nie tylko `findById()`.

## Szablony PHP sprawdzać `php -l` przed deployem

**Context:** `templates/analysis.php`, hotfix `0eebbc4`.

**Problem:** Podwójny `<?php` w szablonie zepsuł produkcję. Deploy poszedł bez walidacji składni.

**Rule:** Przed każdym commitem zawsze uruchom `php -l templates/*.php` na zmienionych szablonach. Szczególnie ważne przy ręcznym mergowaniu zmian lub kopiowaniu fragmentów między plikami.

**Applies to:** Każda zmiana pliku `.php` w `templates/`.

## PowerShell heredoc: znak specjalny ▾ łamie `@'...'@`

**Context:** `git commit -m @'...'@` z emoji/unicode w treści.

**Problem:** Commit message zawierający ▾ (lub inne znaki spoza ASCII) w PowerShell here-string `@'...'@` powoduje błąd parsowania — git interpretuje część wiadomości jako pathspec.

**Rule:** Unikaj znaków specjalnych (emoji, strzałki unicode) w treści commit message pisanego przez PowerShell heredoc. Zamień na ASCII (`v`, `->`, `(dropdown)`) lub użyj wieloliniowego `-m` z backtick `n.

**Applies to:** Każdy `git commit` przez PowerShell z niestandardowymi znakami unicode.

## exec() fire-and-forget: dołącz ` &` do polecenia

**Context:** `src/Admin/SectorsController::refresh()`.

**Problem:** `exec($cmd)` blokuje request do zakończenia procesu PHP. Refresh peer-median trwa ~2-5 min.

**Rule:** Dla długich procesów CLI uruchamianych z web PHP: `exec($cmd . ' &')` — ampersand odcina proces od rodzica i natychmiast zwraca kontrolę. Bez niego request czeka.

**Applies to:** Każde `exec()` w kontrolerach webowych gdy czas wykonania > 1s.

## Filtruj shadow model_version przy każdym odczycie "latest snapshot"

- **Context**: Repozytoria czytające "latest snapshot" z cvs_snapshots (CvsSnapshotRepository, ScreenerRepository i każdy nowy odczyt MAX(score_date)).
- **Problem**: Gdy persystencja w trybie cieniowym (np. cvs-overlay-penalties, model_version 3.1) zaczyna pisać drugi wiersz per (ticker, score_date), niefiltrowany JOIN na MAX(score_date) zwraca OBA wiersze tego samego dnia — podwajając listingi (screener pokazał 68 zamiast 34 spółek, dashboard miał zdublowaną mapę watchlisty). Hotfix: commit 442689d, 2026-06-08.
- **Rule**: Każdy nowy lub istniejący odczyt "najnowszego snapshotu" (MAX(score_date) self-join na cvs_snapshots) MUSI przyjmować i filtrować po żywym model_version z config/cvs-weights.php — nigdy nie polegać na samym MAX(score_date), gdy w tabeli mogą współistnieć wiersze cieniowe (shadow) i live dla tej samej daty.
- **Applies to**: plan, implement, impl-review
