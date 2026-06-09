# F-05: Dostęp PRO — Implementation Plan

## Overview

Infrastruktura dostępu PRO: trzy nowe tabele (`users.is_admin`, `pro_codes`,
`ai_usage_log`), repozytoria, serwis bramy (`ProGate`), panel admina
(`/admin/pro`) do wydawania/odbierania kodów, endpoint sesyjny
`POST /pro/activate` walidujący kod i cachujący go w sesji, limity dzienne
(10) i miesięczne (100) per user z konfiguracją w `config/ai.php`.
F-05 dostarcza infrastrukturę; S-01 wbuduje w nią przycisk i samo wywołanie AI.

## Current State Analysis

- `AuthController::requireAuth()` — tylko binarny login check, zero ról/PRO.
- Tabela `users` — id/email/password_hash/created_at, bez `is_admin`.
- `AiResult` już wystawia `$usage` (tokeny input+output) — gotowe do logowania.
- Brak jakichkolwiek tabel pro_codes, ai_usage_log, bramy ani UI PRO.
- Patterny: PDO injection w repozytoriach (WatchlistRepository, CvsSnapshotRepository)
  → stosujemy ten sam wzorzec. Auth guard przez `AuthController::requireAuth()`.

## Desired End State

- Admin (is_admin=1) loguje się, wchodzi na `/admin/pro`, widzi listę użytkowników
  i kodów, może dodać/unieważnić kod.
- User wchodzi na `/analysis/{ticker}`, widzi przycisk „Generuj analizę AI"
  (przycisku samego w sobie jeszcze nie ma — S-01 go doda; F-05 wystawia
  `$canGenerateAi` i `$aiUsage` do szablonu).
- Kliknięcie przycisku (S-01) wyzwala modal → user wpisuje kod → AJAX do
  `POST /pro/activate` → kod walidowany, jeśli OK → `$_SESSION['pro_code']`
  ustawiony → generacja AI możliwa do końca sesji (lub do przekroczenia limitu).
- `AiUsageRepository::log()` zapisuje każde wywołanie AI (S-01 wywoła go
  po sukcesie ClaudeClient).
- PHPUnit + PHPStan nadal zielone.

### Key Discoveries

- `UserRepository::findById()` zwraca tylko `id, email` — po dodaniu `is_admin`
  trzeba rozszerzyć SELECT (`src/Auth/UserRepository.php:61`).
- `config/ai.php` już istnieje (F-02) → tam dodajemy limity PRO (`pro.daily_limit`,
  `pro.monthly_limit`).
- Namespace nowy: `CVS\Pro\` (per CLAUDE.md Phase 2 conventions).
- `AnalysisController::show()` (`src/CVS/AnalysisController.php:111`) renderuje
  widok szczegółów — tam przekażemy `$canGenerateAi` i `$aiUsage` dla S-01.
- `$_SESSION['pro_code']` jako cache kodu w sesji — prosta kopia po wzorze
  `$_SESSION['user_id']` z AuthController.

## What We're NOT Doing

- Generowania samej analizy AI (tekstu, narracji) — to S-01.
- Przycisk „Generuj analizę AI" na stronie detalu — S-01 go doda.
- Pełnego panelu admina (zarządzanie userami, historia itp.) — tylko kody PRO.
- Self-service rejestracji PRO / płatności.
- Twardego blokowania kodu po N tokenach (limity dotyczą liczby wywołań, nie tokenów).

## Implementation Approach

4 fazy w kolejności zależności:
1. Migracje SQL (is_admin, pro_codes, ai_usage_log) + seed admina.
2. `ProRepository` + `AiUsageRepository` + `ProGate` serwis.
3. Panel admina `/admin/pro` (controller + template + route).
4. Endpoint `POST /pro/activate` + zmienne do szablonu w `AnalysisController::show()`.

## Critical Implementation Details

**Walidacja kodu — dwa tryby.** `ProRepository::findActiveCode(string $code, int $userId)`:
sprawdza najpierw kod globalny (WHERE code = ? AND user_id IS NULL AND is_active = 1),
potem indywidualny (... AND user_id = ?). W fazie globalnej wystarczy pierwsze zapytanie.

**Limit check.** `ProGate::canGenerate(int $userId): bool` musi sprawdzić
zarówno sesję (czy kod jest aktywny) JAK I limity dzienne/miesięczne. S-01
wywoła tę metodę przed wywołaniem ClaudeClient.

**is_admin a SELECT.** `UserRepository::findById()` zwraca tylko `id, email` —
rozszerzyć o `is_admin`. Bez tej zmiany `AdminController` nie będzie wiedział
kto jest adminem po zalogowaniu.

---

## Phase 1: Migracje SQL + seed admina

### Overview

Trzy migracje addytywne + instrukcja ręcznego ustawienia admina.

### Changes Required

#### 1. Migracja: is_admin na tabeli users

**File:** `database/migrations/005_add_is_admin_to_users.sql`

**Intent:** Dodać kolumnę `is_admin` do istniejącej tabeli users — jedyny sposób
na rozróżnienie admina od zwykłego usera bez dodatkowej tabeli ról.

**Contract:** `ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0`;
brak zmian łamiących, wszyscy istniejący userzy dostają 0.

#### 2. Migracja: pro_codes

**File:** `database/migrations/006_create_pro_codes.sql`

**Intent:** Tabela przechowująca kody PRO: globalny (`user_id IS NULL`) lub
przypisany do konkretnego usera. Jeden aktywny globalny kod = jeden wiersz
z `user_id = NULL, is_active = 1`.

**Contract:**
```sql
CREATE TABLE pro_codes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(64)  NOT NULL,
    user_id     INT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code),
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
)
```

#### 3. Migracja: ai_usage_log

**File:** `database/migrations/007_create_ai_usage_log.sql`

**Intent:** Log każdego wywołania AI — user_id, użyty kod, liczba tokenów,
timestamp. Podstawa do sprawdzania limitów dziennych i miesięcznych.

**Contract:**
```sql
CREATE TABLE ai_usage_log (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED  NOT NULL,
    pro_code      VARCHAR(64)   NOT NULL,
    tokens_input  INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_output INT UNSIGNED  NOT NULL DEFAULT 0,
    generated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_user_date (user_id, generated_at),
    INDEX idx_daily (user_id, (DATE(generated_at)))
)
```

#### 4. UserRepository: rozszerzenie findById o is_admin

**File:** `src/Auth/UserRepository.php`

**Intent:** Metoda `findById()` zwraca `id, email` — po dodaniu is_admin kolumny
musi ją też zwracać, żeby controller admina mógł sprawdzić uprawnienia.

**Contract:** Zmień SELECT na `SELECT id, email, is_admin FROM users WHERE id = ?`.

#### 5. Konfiguracja limitów PRO

**File:** `config/ai.php`

**Intent:** Dodać limity dzienne i miesięczne dla AI per user — konfigurowalne
(nie hardcoded, zgodnie z FR-010 spirit), zarządzane z jednego miejsca.

**Contract:** Dodać klucz `'pro' => ['daily_limit' => 10, 'monthly_limit' => 100]`
do istniejącej tablicy konfiguracyjnej.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- Migracje 005/006/007 wykonane na CF bez błędów
- `SHOW COLUMNS FROM users LIKE 'is_admin'` — kolumna istnieje
- `SHOW CREATE TABLE pro_codes` — tabela istnieje z UNIQUE KEY na code
- `SHOW CREATE TABLE ai_usage_log` — tabela istnieje z indeksem user_date
- Seed: `UPDATE users SET is_admin = 1 WHERE email = 'admin@example.com'` na CF

---

## Phase 2: ProRepository + AiUsageRepository + ProGate

### Overview

Trzy klasy w namespace `CVS\Pro\` — repozytoria i serwis bramy.

### Changes Required

#### 1. ProRepository

**File:** `src/Pro/ProRepository.php`

**Intent:** Persystencja kodów PRO — tworzenie, unieważnianie, walidacja.
Używany przez ProGate (walidacja) i ProController (admin CRUD).

**Contract:** Klasa z PDO injection. Metody:
- `findActiveCode(string $code, int $userId): bool` — sprawdza kod globalny
  (user_id IS NULL) LUB przypisany do userId; zwraca true jeśli aktywny.
- `create(string $code, ?int $userId, string $description): void`
- `revoke(int $id): void` — sets is_active = 0.
- `findAll(): array` — wszystkie kody (dla panelu admina).

#### 2. AiUsageRepository

**File:** `src/Pro/AiUsageRepository.php`

**Intent:** Logowanie wywołań AI i liczenie limitów. Używany przez ProGate
(sprawdzenie limitu) i S-01 (zapis po sukcesie ClaudeClient).

**Contract:** Klasa z PDO injection. Metody:
- `log(int $userId, string $proCode, int $tokensIn, int $tokensOut): void`
- `countToday(int $userId): int` — SELECT COUNT(*) WHERE user_id = ? AND DATE(generated_at) = CURDATE()
- `countThisMonth(int $userId): int` — WHERE user_id = ? AND generated_at >= pierwszego dnia bieżącego miesiąca.
- `findByUser(int $userId, int $limit = 20): array` — ostatnie wywołania (dla przyszłego UI).

#### 3. ProGate

**File:** `src/Pro/ProGate.php`

**Intent:** Jeden punkt decyzyjny: czy dany user może teraz generować AI?
Odpytywany przez S-01 przed wywołaniem ClaudeClient; odczytuje sesję.

**Contract:** Klasa przyjmująca `ProRepository` + `AiUsageRepository` + config array.
- `hasValidCode(int $userId): bool` — sprawdza `$_SESSION['pro_code']` i waliduje
  przez `ProRepository::findActiveCode()`.
- `canGenerate(int $userId): bool` — `hasValidCode() && !isOverDailyLimit() && !isOverMonthlyLimit()`.
- `activateCode(string $code, int $userId): bool` — waliduje kod, jeśli OK ustawia
  `$_SESSION['pro_code'] = $code`, zwraca true/false.
- `getUsage(int $userId): array` — `['today' => N, 'month' => M, 'daily_limit' => X, 'monthly_limit' => Y]`.
- `isOverDailyLimit(int $userId): bool` i `isOverMonthlyLimit(int $userId): bool` — protected helpers.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (nowe testy dla ProRepository, AiUsageRepository, ProGate)
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- `ProGate::activateCode('invalid', 1)` → false
- `ProGate::canGenerate(1)` bez aktywnego kodu → false

---

## Phase 3: Panel admina /admin/pro

### Overview

Chroniona trasa `/admin/pro` widoczna tylko dla is_admin=1. Formularz:
lista kodów, dodaj nowy, unieważnij.

### Changes Required

#### 1. ProController

**File:** `src/Pro/ProController.php`

**Intent:** Obsługa GET /admin/pro (lista kodów + formularz) i POST /admin/pro
(dodaj/unieważnij kod). Sprawdza is_admin z sesji przed każdą akcją.

**Contract:**
- `index(Request $req): void` — `requireAuth()` + is_admin guard (jeśli nie admin
  → redirect `/dashboard`); przekazuje do widoku: listę kodów, listę userów.
- `store(Request $req): void` — POST handler: CSRF + is_admin; waliduje code
  (niepuste, unikalne); tworzy przez `ProRepository::create()`; redirect z flash.
- `revoke(Request $req): void` — POST handler: CSRF + is_admin; unieważnia przez
  `ProRepository::revoke(id)`; redirect z flash.

#### 2. Template admina

**File:** `templates/pro/admin.php`

**Intent:** Prosta strona admina — tabela aktywnych kodów (code, user, description,
created_at, status, przycisk Unieważnij) + formularz dodania nowego kodu.

**Contract:** Używa layout.php (przez `Response::view`); komponenty z components.css
(card, table, form, btn). Stałe: `$codes` (array z findAll()), `$users` (array z
UserRepository::findAll()), opcjonalny `$flash` (string komunikat sukcesu/błędu).

#### 3. Trasa /admin/pro

**File:** `src/Core/routes.php`

**Intent:** Zarejestrować GET i POST dla /admin/pro; chronione przez is_admin
wewnątrz kontrolera (nie middleware, zgodnie z istniejącym wzorcem).

**Contract:**
```php
$router->get('/admin/pro',  fn($req) => $pro->index($req));
$router->post('/admin/pro', fn($req) => $pro->store($req));
$router->post('/admin/pro/revoke', fn($req) => $pro->revoke($req));
```

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- Trasy `/admin/pro` zarejestrowane w routes.php (grep)

#### Manual Verification
- Zalogowany jako admin → `/admin/pro` renderuje listę + formularz
- Zalogowany jako zwykły user → redirect na `/dashboard`
- Dodanie kodu przez formularz → pojawia się w tabeli
- Unieważnienie kodu → is_active = 0

---

## Phase 4: Endpoint aktywacji + zmienne dla S-01

### Overview

Endpoint `POST /pro/activate` waliduje kod i ustawia sesję.
`AnalysisController::show()` przekazuje `$canGenerateAi` i `$aiUsage`
do szablonu — S-01 doda do niego przycisk i logikę generowania.

### Changes Required

#### 1. Endpoint POST /pro/activate

**File:** `src/Pro/ProController.php` (nowa metoda `activate`)

**Intent:** AJAX endpoint wywoływany przez modal JS (S-01 go wdroży). Przyjmuje
`code` w body POST, waliduje przez `ProGate::activateCode()`, zwraca JSON.

**Contract:** `activate(Request $req): void` — `requireAuth()` + CSRF;
`ProGate::activateCode($code, $userId)` → `Response::json(['ok' => true/false, 'message' => '...'])`.
Trasa: `POST /pro/activate`.

#### 2. AnalysisController::show() — rozszerzenie o dane PRO

**File:** `src/CVS/AnalysisController.php`

**Intent:** Przekazać do widoku szczegółów informacje o dostępie PRO i zużyciu,
żeby S-01 mógł warunkowo pokazać przycisk bez zmian w kontrolerze.

**Contract:** W metodzie `show()` po `requireAuth()` zbudować `ProGate` i
przekazać do `Response::view('analysis', [..., 'canGenerateAi' => $gate->canGenerate($userId), 'aiUsage' => $gate->getUsage($userId)])`.

#### 3. Trasa POST /pro/activate

**File:** `src/Core/routes.php`

**Contract:** `$router->post('/pro/activate', fn($req) => $pro->activate($req));`

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- Trasa `/pro/activate` zarejestrowana (grep)

#### Manual Verification
- `POST /pro/activate` z nieprawidłowym kodem → `{"ok":false,"message":"..."}`
- `POST /pro/activate` z prawidłowym kodem → `{"ok":true}` + `$_SESSION['pro_code']` ustawiony
- `/analysis/AAPL` — widok ładuje się normalnie, żadna regresja
- `$canGenerateAi` false dla usera bez kodu w sesji
- `$canGenerateAi` true dla usera z aktywnym kodem i limitem nie przekroczonym

---

## Testing Strategy

### Unit Tests

- `ProRepository`: findActiveCode (globalny i per-user), create, revoke
- `AiUsageRepository`: log, countToday, countThisMonth
- `ProGate`: canGenerate (brak kodu, kod nieważny, kod ważny, limit dzienny, limit miesięczny)

### Manual Testing Steps

1. Zrób migracje na CF: 005, 006, 007; `UPDATE users SET is_admin = 1 WHERE email = 'admin@example.com'`
2. Zaloguj jako admin → `/admin/pro` → dodaj globalny kod (np. `CVS-BETA-2026`)
3. Zaloguj jako zwykły user → `/analysis/AAPL` → potwierdź `$canGenerateAi = false`
4. Wróć jako admin → upewnij się `/admin/pro` widzi kod jako aktywny
5. Unieważnij kod → is_active = 0 → `/analysis/AAPL` zwykłego usera: canGenerateAi false

## Performance Considerations

Dwa dodatkowe SELECT per render `/analysis/{ticker}` (`countToday`, `countThisMonth`) —
pomijalny koszt przy small-scale (<10 userów, kilka wywołań/dzień).
Indeksy `idx_user_date` i `idx_daily` na `ai_usage_log` to pokrywają.

## Migration Notes

Wszystkie migracje addytywne. Rollback:
- `ALTER TABLE users DROP COLUMN is_admin`
- `DROP TABLE pro_codes`
- `DROP TABLE ai_usage_log`

## References

- Roadmap: `context/foundation/roadmap.md` (F-05)
- PRD: `context/foundation/prd.md` (FR-003, FR-004)
- AuthController (requireAuth + is_admin pattern): `src/Auth/AuthController.php:160`
- UserRepository (findById do rozszerzenia): `src/Auth/UserRepository.php:61`
- AiResult (tokeny do logowania): `src/Ai/AiResult.php`
- Config AI (limity): `config/ai.php`
- Wzorzec repozytorium: `src/TrackRecord/CvsSnapshotRepository.php`
- CLAUDE.md Phase 2 conventions: namespace `CVS\Pro\`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Migracje SQL + seed admina

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony — 9bddd08
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — 9bddd08

#### Manual
- [x] 1.3 Migracje 005/006/007 wykonane na CF, tabele istnieją
- [x] 1.4 Kolumna `is_admin` na tabeli `users` — widoczna w SHOW COLUMNS
- [x] 1.5 Seed: `admin@example.com` ma `is_admin = 1` na CF

### Phase 2: ProRepository + AiUsageRepository + ProGate

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony (testy nowych klas) — 05f7ca1
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — 05f7ca1

#### Manual
- [x] 2.3 `ProGate::activateCode('invalid', 1)` → false
- [x] 2.4 `ProGate::canGenerate(1)` bez aktywnego kodu → false

### Phase 3: Panel admina /admin/pro

#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — 4eee7e1
- [x] 3.2 `vendor/bin/phpstan analyse` zielony — 4eee7e1
- [x] 3.3 Trasy `/admin/pro` zarejestrowane w `routes.php` — 4eee7e1

#### Manual
- [x] 3.4 Admin widzi `/admin/pro` z formularzem i listą kodów
- [x] 3.5 Zwykły user redirectowany z `/admin/pro` na `/dashboard`
- [x] 3.6 Dodanie i unieważnienie kodu działa

### Phase 4: Endpoint aktywacji + zmienne dla S-01

#### Automated
- [x] 4.1 `vendor/bin/phpunit` zielony — e17389c
- [x] 4.2 `vendor/bin/phpstan analyse` zielony — e17389c
- [x] 4.3 Trasa `POST /pro/activate` zarejestrowana — e17389c

#### Manual
- [x] 4.4 POST /pro/activate z błędnym kodem → `{"ok":false}`
- [x] 4.5 POST /pro/activate z poprawnym kodem → `{"ok":true}`, sesja ustawiona
- [x] 4.6 `/analysis/AAPL` ładuje się normalnie, brak regresji
- [x] 4.7 `$canGenerateAi` false bez kodu, true z aktywnym kodem i limitem OK
