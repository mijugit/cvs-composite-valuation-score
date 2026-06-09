# Admin: Panel Odświeżania Sektorów — Plan Brief

> Full plan: `context/changes/admin-sector-refresh/plan.md`

## What & Why

Nowa strona administracyjna `/admin/sectors` daje adminowi pełny wgląd w stan indeksowania median peer-group i możliwość ręcznego wymuszenia odświeżenia dowolnego sektora. Bez tego narzędzia jedynym sposobem na force-refresh jest modyfikacja pliku konfiguracyjnego na serwerze przez SSH — co blokowały zasady auto-mode.

## Starting Point

Tabela `peer_medians` zawiera dane dla 4 z ~11 sektorów (cron roluje po jednym sektorze dziennie od 2026-06-03). `bin/refresh_peer_medians.php` działa wyłącznie jako CLI z harmonogramem z `date('N')` — nie można go skierować na konkretny sektor ani wywołać z web. Panel `/admin/pro` istnieje, ale nie ma linku w nawigacji i nie ma żadnego narzędzia do diagnostyki sektorów.

## Desired End State

Admin loguje się → widzi "Sektory" w nawigacji → otwiera `/admin/sectors` → tabela pokazuje wszystkie ~11 sektorów z datami, medianami (EV/FCF, EV/Sales, GM%), liczbą spółek i statusem (zaindeksowany / niezaindeksowany). Kliknięcie wiersza rozwija podsektory. Przycisk "Odśwież" obok każdego sektora uruchamia refresh w tle i pokazuje toast potwierdzający.

## Key Decisions Made

| Decision | Choice | Why | Source |
|---|---|---|---|
| Mechanizm refresh z web | `exec()` fire-and-forget | Async, nie blokuje requesta; CF CLI PHP (`/usr/local/bin/php84`) już potwierdzone przez cron | Plan |
| Refaktor logiki bin | Nie — dodaj tylko argv | Minimalna zmiana, backward-compatible, ryzyko regresji niskie | Plan |
| Granularność widoku | Sektor + accordion podsektory | Admin widzi pełny obraz bez zbędnego scrollowania | Pytania |
| Feedback po kliknięciu | Toast bez auto-reload | Prostsze; refresh trwa 1–2 min — polling byłby mylący | Pytania |
| Brakujące sektory | Szary wiersz "Niezaindeksowany" | Admin widzi białe plamy i może je wypełnić jednym kliknięciem | Pytania |
| is_admin w nawigacji | Sesja (`$_SESSION['is_admin']`) | Zero DB call per request w layout | Plan |
| Bezpieczeństwo exec() | Whitelist sektorów z batch_schedule | `escapeshellarg()` + whitelist = ochrona przed injection | Plan |

## Scope

**In scope:**
- `bin/refresh_peer_medians.php` — argv `--sector=X` override
- `AuthController::login()` — zapis `is_admin` do sesji
- `templates/layout.php` — warunkowe linki admin w nav
- `src/Admin/SectorsController.php` — dwa endpointy (index + refresh)
- `PeerMedianRepository::findSectorStats()` — nowa metoda agregująca
- `src/Core/routes.php` — dwie nowe trasy
- `templates/admin/sectors.php` — pełny widok z accordion i JS
- `public/js/app.js` — accordion + AJAX + toast
- `public/css/components.css` — `signal-pill--neutral`, `sector-row` styles

**Out of scope:**
- Refaktor logiki refresh do osobnej klasy serwisowej
- Auto-reload / polling po kliknięciu Odśwież
- Refresh per podsektor (industry)
- Zmiany batch_schedule ani harmonogramu cron
- Live log / progress bar

## Architecture / Approach

Web request → `SectorsController::refresh()` → whitelist check + CSRF → `exec('nohup /usr/local/bin/php84 bin/refresh_peer_medians.php --sector=X ... &')` → JSON `{ok: true}`. Bin script (po zmianie) przyjmuje argv i procesuje tylko wskazany sektor. Dane do widoku: kontroler merge'uje listę sektorów z `batch_schedule` z wierszami `peer_medians` z DB — sektory bez danych w DB dostają status "Niezaindeksowany".

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Bin Script Argv | `--sector=X` override backward-compatible | Brak — minimalna zmiana izolowana |
| 2. Admin Nav | Link "Sektory" w nav dla admina, is_admin w sesji | Sesje istniejących adminów wymagają re-login |
| 3. Controller + Data | `/admin/sectors` + `/admin/sectors/refresh` działają | `exec()` może być wyłączone na CF web PHP |
| 4. Template + JS | Pełny UI: accordion, badges, toast, przyciski | Brak — wzorce z codebase wystarczają |

**Prerequisites:** Faza 3 musi potwierdzić że `exec()` działa na CF web PHP (SSH verify). Jeśli nie — potrzebna alternatywa (tabela `refresh_jobs` + cron co minutę).

**Estimated effort:** ~1 sesja robocza, 4 fazy sekwencyjne.

## Open Risks & Assumptions

- **`exec()` na CF web PHP:** Może być na `disable_functions`. Musi być zweryfikowane przez SSH przed deployem fazy 3. Jeśli wyłączone — wymagana alternatywa (job queue w DB).
- **Sesje istniejących adminów:** Po deploycie fazy 2 admin musi się wylogować i zalogować ponownie żeby `$_SESSION['is_admin']` się pojawiło.
- **Czas refresh sektora:** Technology to ~100 tickerów × ~0.5s/ticker = ~50s. CF może killować procesy w tle po pewnym czasie. Ryzyko niskie (rescore.php działa jako background process bez problemów).

## Success Criteria (Summary)

- Admin klika "Odśwież" dla Technology → toast "Odświeżanie Technology uruchomiono" → po 2–3 minutach strona pokazuje zaktualizowane mediany z aktualną datą
- Zwykły user trafiający na `/admin/sectors` → redirect na /dashboard
- Cron job działa bez zmian (faza 1 backward-compatible)
