# E2E — Playwright

## Uruchomienie

```bash
npm install
npx playwright install chromium   # tylko przy pierwszym uruchomieniu

# 1) Lokalna baza z aktualnym schematem (dowolna nazwa, tu: cvs_dev)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS cvs_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in database/migrations/*.sql; do mysql -u root cvs_dev < "$f"; done

# 2) .env: ustaw DB_NAME=cvs_dev (lub inną nazwę, patrz niżej)

# 3) Dev server
php -S localhost:8000 -t public

# 4) Testy
npx playwright test
```

Domyślne dane połączenia w `seed.spec.ts` (`root` / brak hasła / `127.0.0.1` / `cvs_dev`)
odpowiadają lokalnemu XAMPP/MySQL. Jeśli Twoja konfiguracja jest inna, nadpisz przez
zmienne środowiskowe: `E2E_DB_HOST`, `E2E_DB_USER`, `E2E_DB_PASSWORD`, `E2E_DB_NAME`,
`E2E_BASE_URL` (domyślnie `http://localhost:8000`).

## seed.spec.ts — ryzyko i scenariusz

**Ryzyko:** dostęp do dashboardu i utrzymanie sesji przechodzą przez wiele granic
systemu naraz — formularz → walidacja CSRF → zapis w bazie → bramka weryfikacji
e-mail → sesja PHP. Żaden z tych fragmentów osobno (test jednostkowy) nie
wykrywa błędu na styku granic, np. tego że sesja nie przetrwa odświeżenia strony
albo że niezweryfikowane konto jednak dostanie się na dashboard.

**Scenariusz (jeden spójny test, unikalny e-mail per przebieg, sprzątanie w `finally`):**

1. Rejestracja nowego konta przez UI (`/register`).
2. Asercja: niezweryfikowane konto ląduje na `/auth/check-email`, **nie** na dashboardzie.
3. Odczyt tokenu weryfikacyjnego bezpośrednio z bazy (lokalnie brak SMTP, więc
   nie ma prawdziwego maila) i wejście pod prawdziwy link `/auth/verify?token=...`
   — dokładnie to, co zrobiłby użytkownik klikający w mail.
4. Weryfikacja loguje automatycznie → asercja: dashboard, nagłówek "Panel analizy CVS".
5. Wylogowanie i ponowne logowanie przez formularz `/login` (osobna ścieżka niż
   auto-login po weryfikacji) → asercja: dashboard ponownie widoczny.
6. Odświeżenie strony (`page.reload()`) → asercja: sesja przetrwała, brak
   przekierowania na `/login`.
7. Cleanup: `DELETE FROM users WHERE email = ...` — kolejne przebiegi nie
   kolidują (unique constraint na `email`).

## VERIFY — dowód, że asercje faktycznie chronią to zachowanie

Test uruchomiony na zielono to za mało — zielony jest też test z naiwną asercją.
Żeby to sprawdzić, tymczasowo zepsuto `AuthController::requireAuth()`
(wymuszony redirect na `/login` niezależnie od stanu sesji) i uruchomiono test:

```
Error: page.goto: net::ERR_TOO_MANY_REDIRECTS at .../auth/verify?token=...
1 failed
```

Test poprawnie poszedł na czerwono. Zepsucie zostało natychmiast cofnięte
(`git diff` na `AuthController.php` jest puste) — nigdy nie trafiło do commita.
Po cofnięciu test wraca na zielono; uruchomiony dwukrotnie pod rząd nie
zostawia żadnych kont testowych w bazie (`email LIKE 'e2e-%'` → 0 wierszy).

## Ograniczenia

- Jeden scenariusz (seed test) — demonstruje konwencje (`getByRole`/`getByLabel`,
  czekanie na URL zamiast na czas, unikalne dane, cleanup), nie jest pełnym
  pokryciem ryzyk z `test-plan.md` (ten dokument jeszcze nie istnieje dla CVS).
- Nie podpięty do CI — uruchamiany lokalnie (`npx playwright test`).
- Wymaga lokalnej bazy MySQL z pełnym zestawem migracji; nie testuje przeciwko
  produkcyjnej/współdzielonej bazie.
