# Plan Brief: email-verification

## Problem

Użytkownicy mogą włączyć alerty email bez potwierdzenia, że adres email do nich należy. To narusza RODO art. 6(1)(a) — podstawa prawna alertów to zgoda, a zgoda wymaga zweryfikowanego kanału.

## Desired End State

1. Nowy użytkownik po rejestracji **nie jest zalogowany** — trafia na stronę "Sprawdź skrzynkę".
2. Email z linkiem `/auth/verify?token=...` (48h ważności) czeka w skrzynce.
3. Po kliknięciu linku → sesja zakładana, redirect do dashboardu z flash "Email potwierdzony!".
4. Próba włączenia alertów bez weryfikacji → JSON error z `needs_verification: true`.
5. Istniejący użytkownicy: bez zmian (grandfatherowani w migracji).

## Scope

**In scope:**
- Migracja 021 — 3 kolumny w `users`
- `UserRepository` — 4 nowe metody weryfikacyjne
- `AuthController` — zmiana `register()`, `login()`, + 3 nowe akcje (`showCheckEmail`, `resendVerification`, `verify`)
- `AlertController::toggleGlobal()` — gate (blokada włączania alertów)
- Szablony: `auth/check-email.php`, `auth/verify-error.php`
- Trasy: 3 nowe GET-y w `routes.php`
- `AnalysisController::dashboard()` — przekazanie `$emailVerified` do szablonu
- `dashboard.php` — baner dla niezweryfikowanych + obsługa odpowiedzi `needs_verification`

**Out of scope:**
- Weryfikacja przy zmianie adresu email (brak takiej funkcji)
- Per-ticker alert toggle blokada (tylko globalny)
- Blokada analizy AI lub watchlistu (tylko alerty)
- Rate limiting resend (nie ma potrzeby przy małej liczbie użytkowników)

## Key Technical Constraints

- Wzorzec HMAC już istnieje (unsubscribe) — tu jednak token losowy w DB, bo potrzebujemy możliwości unieważnienia (resend kasuje stary token)
- `MailService::send()` — graceful fail (nigdy nie rzuca wyjątku); email może nie dotrzeć bez blokowania rejestracji
- `Response::view()` / `Response::redirect()` / `Response::json()` — wszystkie auto-exit
- `AuthController` musi dostać `MailService` jako zależność (nowa w konstruktorze)
- Szablony w `templates/auth/` — `Response::view('auth/check-email')` → `templates/auth/check-email.php`
