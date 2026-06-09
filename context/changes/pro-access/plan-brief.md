# F-05: Dostęp PRO — Plan Brief

> Full plan: `context/changes/pro-access/plan.md`

## What & Why

Infrastruktura dostępu PRO: kody wydawane przez admina, brama walidująca kod
z sesji przed generowaniem AI, śledzenie zużycia (limit 10/dzień, 100/miesiąc
per user). Bez tego S-01 (analiza AI = North Star fazy 2) nie może być chroniony
— każdy zalogowany mógłby generować dowolnie i przepalić budżet API.

## Starting Point

Auth to binarny login (is_logged_in), zero ról. Tabela `users` bez `is_admin`.
Brak tabel pro_codes, ai_usage_log, brak jakiejkolwiek logiki PRO w kodzie.
`AiResult` już wystawia tokeny — gotowe do logowania.

## Desired End State

Admin wchodzi na `/admin/pro`, dodaje globalny kod (np. `CVS-BETA-2026`).
User wpisuje go w modalu (wdrożonym przez S-01) → `POST /pro/activate` waliduje
i cachuje kod w sesji. `AnalysisController::show()` przekazuje `$canGenerateAi`
i `$aiUsage` do szablonu — S-01 używa tych zmiennych do pokazania/ukrycia
przycisku i wywołania AI.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| Aktywacja kodu | Modal per-wywołanie + session cache | UX: user wpisuje raz per sesję, nie per generację |
| Schema kodu | `user_id NULL` = globalny | Jeden schemat obsługuje teraz i przyszłość bez migracji |
| Admin UI | Strona `/admin/pro` | Admin nie musi logować się do DB |
| Admin guard | Kolumna `users.is_admin` | Czystszy model niż hardcoded email |
| Limity | 10/dzień + 100/miesiąc per user, w config | Kontrola kosztu API; konfigurowalne |
| Tracking | ai_usage_log (user+code+tokeny+timestamp) | FR-004; brak auto-blokady po tokenach |
| Granica F-05/S-01 | F-05 = infrastruktura; S-01 = przycisk + wywołanie AI | Nie blokujemy S-01 budowaniem UI przed logiką |

## Scope

**In scope:** `005_add_is_admin.sql`, `006_create_pro_codes.sql`,
`007_create_ai_usage_log.sql`, `UserRepository::findById` z is_admin,
`ProRepository`, `AiUsageRepository`, `ProGate`, `/admin/pro` panel,
`POST /pro/activate`, zmienne `$canGenerateAi`/`$aiUsage` w `AnalysisController::show()`,
limity w `config/ai.php`.

**Out of scope:** Przycisk "Generuj AI" na stronie detalu (S-01), wywołanie
ClaudeClient (S-01), self-service rejestracji PRO, billing, zarządzanie userami.

## Architecture / Approach

```
$_SESSION['pro_code']  ←  POST /pro/activate (ProGate::activateCode)
                                    ↑
                           modal JS (S-01 wdroży)

AnalysisController::show()
  └─ ProGate::canGenerate(userId)  → $canGenerateAi
  └─ ProGate::getUsage(userId)     → $aiUsage
       ├─ AiUsageRepository::countToday()
       └─ AiUsageRepository::countThisMonth()

/admin/pro (ProController)
  └─ ProRepository::findAll() / create() / revoke()
  └─ is_admin guard (users.is_admin = 1)

S-01 wywoła:
  └─ ProGate::canGenerate() → jeśli true → ClaudeClient → AiUsageRepository::log()
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Migracje + config | 3 tabele, is_admin kolumna, limity w config/ai.php | Migracja na żywej bazie CF |
| 2. Repozytoria + ProGate | Logika walidacji, limitów, logowania | PHPStan strict types na nullable user_id |
| 3. /admin/pro | Panel admina z formularzem i listą kodów | is_admin guard — test że zwykły user nie dostaje się |
| 4. Endpoint + zmienne S-01 | POST /pro/activate, $canGenerateAi w szablonie | Regresja AnalysisController::show() |

**Prerequisites:** F-02 (ClaudeClient gotowy), F-01 (components.css dla UI admina).
**Estimated effort:** ~1-2 sesje, 4 fazy.

## Open Risks & Assumptions

- `idx_daily` na `ai_usage_log` z `DATE(generated_at)` — MySQL 5.7 nie obsługuje
  functional indexes; bezpieczniejszy fallback to zwykły index na kolumnie
  `generated_at` (wystarczy dla COUNT per dzień na małej tabeli).
- Session cache kodu — jeśli admin unieważni kod, user z aktywną sesją nadal
  może generować do końca sesji. Akceptowalne przy ~10 userach.

## Success Criteria (Summary)

- Admin dodaje/usuwa kod PRO przez UI bez dotykania bazy.
- `ProGate::canGenerate()` zwraca false gdy brak kodu w sesji, kod nieaktywny
  lub limit przekroczony.
- `POST /pro/activate` z prawidłowym kodem → sesja ustawiona → `canGenerate` = true.
