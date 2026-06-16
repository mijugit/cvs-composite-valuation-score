# Plan: email-verification

## Overview

Weryfikacja adresu email przy rejestracji przez magiczny link. Token losowy (64-char hex) przechowywany w DB, ważny 48h. Istniejący użytkownicy grandfatherowani w migracji. Jedyna blokada: globalny toggle alertów email.

## Current State Analysis

- `users` table: `id, email, password_hash, created_at, is_admin` — brak kolumn weryfikacyjnych
- `AuthController::register()`: tworzy użytkownika → natychmiast loguje (sesja) → redirect `/dashboard`
- `AuthController::login()`: brak check weryfikacji — każdy z poprawnym hasłem wchodzi
- `AlertController::toggleGlobal()`: czysty toggle, brak jakichkolwiek warunków
- `MailService::send()`: gotowy, graceful fail, HTML+plaintext, nigdy nie rzuca
- Wzorzec HMAC istnieje w `AlertService` — tu używamy losowego tokenu w DB (wymaga możliwości unieważnienia przy resend)
- `AnalysisController::dashboard()`: zwraca `$alertsEnabled` do widoku; nie zwraca `$emailVerified`
- Ostatnia migracja: `020_create_peer_medians_history.sql` → następna: `021`

## What We're NOT Doing

- Blokada per-ticker toggle, analizy AI, watchlistu, screener — tylko globalny toggle alertów
- Weryfikacja przy zmianie emaila (brak tej funkcji)
- Rate limiting resend
- Powiadomienie emailem istniejącym użytkownikom

---

## Phase 1: Migracja 021 — kolumny weryfikacyjne

### Overview
Dodanie 3 kolumn do tabeli `users`. UPDATE grandfatheruje istniejących użytkowników.

### Changes Required

- Nowy plik `database/migrations/021_add_email_verification.sql`:
  ```sql
  ALTER TABLE users
      ADD COLUMN email_verify_token      VARCHAR(64)  NULL DEFAULT NULL,
      ADD COLUMN email_verify_expires_at DATETIME     NULL DEFAULT NULL,
      ADD COLUMN email_verified_at       DATETIME     NULL DEFAULT NULL;

  UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;
  ```

### Success Criteria

#### Automated Verification
- `composer stan` — bez nowych błędów (migracja to SQL, nie PHP)
- Plik SQL istnieje w `database/migrations/021_add_email_verification.sql`

#### Manual Verification
- Uruchomić SQL na lokalnej bazie developerskiej (lub produkcji) i sprawdzić `DESCRIBE users` → 3 nowe kolumny obecne
- Sprawdzić `SELECT id, email, email_verified_at FROM users LIMIT 5` → wartości nie-NULL dla istniejących

---

## Phase 2: UserRepository — metody weryfikacyjne

### Overview
4 nowe metody obsługujące cykl życia tokenu weryfikacyjnego.

### Changes Required

W `src/Auth/UserRepository.php` — dodać po sekcji `Writes`:

```php
// Verification token —————————————————————————————————————————————

public function setVerifyToken(int $id, string $token, string $expiresAt): void
{
    $stmt = $this->db->prepare(
        'UPDATE users SET email_verify_token = ?, email_verify_expires_at = ? WHERE id = ?'
    );
    $stmt->execute([$token, $expiresAt, $id]);
}

/** @return array{id:int, email:string}|null — null gdy token nieznany lub wygasł */
public function findByVerifyToken(string $token): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, email FROM users
         WHERE email_verify_token = ? AND email_verify_expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

public function setEmailVerified(int $id): void
{
    $stmt = $this->db->prepare(
        'UPDATE users SET email_verified_at = NOW(),
                          email_verify_token = NULL,
                          email_verify_expires_at = NULL
         WHERE id = ?'
    );
    $stmt->execute([$id]);
}

public function isEmailVerified(int $id): bool
{
    $stmt = $this->db->prepare(
        'SELECT COUNT(*) FROM users WHERE id = ? AND email_verified_at IS NOT NULL'
    );
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn() > 0;
}
```

### Success Criteria

#### Automated Verification
- `composer stan` — 0 błędów

#### Manual Verification
- (brak — testowanie przez integrację w fazie 3)

---

## Phase 3: AuthController — przepływ weryfikacji

### Overview
`register()` przestaje logować od razu — generuje token i kieruje na check-email. `login()` blokuje niezweryfikowanych. 3 nowe akcje. Prywatna metoda HTML emaila.

### Changes Required

W `src/Auth/AuthController.php`:

**1. Dodać use + property `$mail`:**
```php
use CVS\Mail\MailService;
// ...
private UserRepository $users;
private MailService    $mail;

public function __construct()
{
    $this->users = new UserRepository();
    $this->mail  = new MailService();
}
```

**2. Zmienić `register()` — końcówkę po `$id = $this->users->create(...)`:**
```php
// Usuń: session_regenerate_id / $_SESSION['user_id'] / redirect('/dashboard')
// Zastąp:
$token   = bin2hex(random_bytes(32));
$expires = (new \DateTime('+48 hours'))->format('Y-m-d H:i:s');
$this->users->setVerifyToken($id, $token, $expires);
$verifyUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/auth/verify?token=' . $token;
$this->mail->send($email, 'CVS — potwierdź adres e-mail', $this->buildVerificationHtml($email, $verifyUrl));
$_SESSION['pending_verification_email'] = $email;
Response::redirect('/auth/check-email');
```

**3. Zmienić `login()` — po pomyślnym `password_verify()`:**
```php
// Dodaj check PRZED session_regenerate_id:
if (!$this->users->isEmailVerified((int) $user['id'])) {
    $token   = bin2hex(random_bytes(32));
    $expires = (new \DateTime('+48 hours'))->format('Y-m-d H:i:s');
    $this->users->setVerifyToken((int) $user['id'], $token, $expires);
    $verifyUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/auth/verify?token=' . $token;
    $this->mail->send((string) $user['email'], 'CVS — potwierdź adres e-mail',
        $this->buildVerificationHtml((string) $user['email'], $verifyUrl));
    $_SESSION['pending_verification_email'] = $user['email'];
    Response::redirect('/auth/check-email');
    return;
}
```

**4. Nowa akcja `showCheckEmail()`:**
```php
public function showCheckEmail(Request $req): void
{
    $email = (string) ($_SESSION['pending_verification_email'] ?? '');
    Response::view('auth/check-email', ['email' => $email]);
}
```

**5. Nowa akcja `resendVerification()`:**
```php
public function resendVerification(Request $req): void
{
    if (!$req->verifyCsrf()) {
        Response::redirect('/auth/check-email');
        return;
    }
    $email = (string) ($_SESSION['pending_verification_email'] ?? '');
    if ($email === '') {
        Response::redirect('/login');
        return;
    }
    $user = $this->users->findByEmail($email);
    if ($user === null || $this->users->isEmailVerified((int) $user['id'])) {
        Response::redirect('/login');
        return;
    }
    $token   = bin2hex(random_bytes(32));
    $expires = (new \DateTime('+48 hours'))->format('Y-m-d H:i:s');
    $this->users->setVerifyToken((int) $user['id'], $token, $expires);
    $verifyUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/auth/verify?token=' . $token;
    $this->mail->send($email, 'CVS — potwierdź adres e-mail', $this->buildVerificationHtml($email, $verifyUrl));
    $_SESSION['_flash'] = 'Nowy link weryfikacyjny został wysłany.';
    Response::redirect('/auth/check-email');
}
```

**6. Nowa akcja `verify()`:**
```php
public function verify(Request $req): void
{
    $token = (string) ($req->query('token') ?? '');
    if ($token === '') {
        Response::view('auth/verify-error', ['email' => '']);
        return;
    }
    $user = $this->users->findByVerifyToken($token);
    if ($user === null) {
        // Token nieznany lub wygasł — przekaż email z sesji jeśli dostępny
        $pendingEmail = (string) ($_SESSION['pending_verification_email'] ?? '');
        Response::view('auth/verify-error', ['email' => $pendingEmail]);
        return;
    }
    $this->users->setEmailVerified((int) $user['id']);
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    // is_admin — fetch full row
    $fullUser = $this->users->findById((int) $user['id']);
    $_SESSION['is_admin']   = (bool) ($fullUser['is_admin'] ?? false);
    unset($_SESSION['csrf_token'], $_SESSION['pending_verification_email']);
    $_SESSION['_flash'] = 'Adres e-mail potwierdzony! Witaj w CVS.';
    Response::redirect('/dashboard');
}
```

**7. Prywatna metoda `buildVerificationHtml()`:**
```php
private function buildVerificationHtml(string $email, string $verifyUrl): string
{
    return '
        <h2 style="color:#1e3a5f;">Potwierdź adres e-mail w CVS</h2>
        <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
            <tr>
                <td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Adres e-mail:</td>
                <td style="padding:8px;">' . htmlspecialchars($email) . '</td>
            </tr>
        </table>
        <p style="margin-top:16px;">
            Kliknij przycisk poniżej, aby potwierdzić adres e-mail i aktywować konto CVS:
        </p>
        <p>
            <a href="' . htmlspecialchars($verifyUrl) . '"
               style="background:#1e3a5f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-block;">
                Potwierdź adres e-mail →
            </a>
        </p>
        <p style="color:#888;font-size:12px;margin-top:12px;">
            Link ważny przez 48 godzin. Jeśli nie rejestrowałeś/-aś się w CVS, zignoruj tę wiadomość.
        </p>
        <p style="color:#aaa;font-size:10px;margin-top:8px;">
            CVS Composite Valuation Score — <a href="https://cvs.timeflow.fun" style="color:#aaa;">cvs.timeflow.fun</a>
        </p>
    ';
}
```

### Success Criteria

#### Automated Verification
- `composer stan` — 0 błędów

#### Manual Verification
- Rejestracja nowego konta → brak sesji (no `$_SESSION['user_id']`), redirect na `/auth/check-email`
- Email weryfikacyjny dociera na skrzynkę
- Kliknięcie linku → zalogowany, flash "Adres e-mail potwierdzony!", dashboard
- Próba logowania na niezweryfikowane konto → redirect na `/auth/check-email` z nowym emailem
- Stary link (wymuszony wygasłość przez ręczną zmianę `email_verify_expires_at` na przeszłość w DB) → strona `verify-error`

---

## Phase 4: AlertController — gate weryfikacji

### Overview
`toggleGlobal()` blokuje **włączanie** alertów gdy email niezweryfikowany. Wyłączanie zawsze dozwolone.

### Changes Required

W `src/Alerts/AlertController.php`:

**Dodać `isEmailVerified` check po obliczeniu `$new`:**
```php
$current = $this->repo->isGlobalEnabled($userId);
$new     = !$current;

// Blokada włączania alertów bez weryfikacji email
if ($new === true && !$this->users->isEmailVerified($userId)) {
    Response::json([
        'ok'                => false,
        'message'           => 'Potwierdź adres e-mail, by włączyć alerty.',
        'needs_verification' => true,
    ]);
    return;
}
```

**Dodać obsługę `needs_verification` w `dashboard.php` JS:**
```js
.then(function (d) {
    if (!d.ok) {
        if (d.needs_verification) {
            // Mały komunikat pod przyciskiem (nie crash)
            var msg = document.getElementById('alerts-verify-msg');
            if (!msg) {
                msg = document.createElement('span');
                msg.id = 'alerts-verify-msg';
                msg.style = 'font-size:.75rem;color:var(--c-signal-sell,#e05252);margin-left:.5rem;';
                btn.parentNode.appendChild(msg);
            }
            msg.textContent = '⚠ ' + d.message;
        }
        return;
    }
    // ...reszta toggle logic
```

### Success Criteria

#### Automated Verification
- `composer stan` — 0 błędów

#### Manual Verification
- Konto z `email_verified_at = NULL` (test: ręczne SET w DB): kliknięcie 🔕 OFF → przycisk nie włącza się, pojawia się napis "Potwierdź adres e-mail..."
- Konto zweryfikowane: toggle działa normalnie
- Wyłączanie alertów na zweryfikowanym koncie: działa

---

## Phase 5: Szablony, trasy, dashboard baner

### Overview
Dwa nowe szablony, 3 nowe trasy, baner na dashboardzie (defensive).

### Changes Required

**1. Nowy plik `templates/auth/check-email.php`:**
```php
<?php declare(strict_types=1); ?>
<div style="max-width:480px;margin:4rem auto;padding:2rem;background:rgba(14,27,47,.55);
            backdrop-filter:blur(4px);border-radius:var(--radius);text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">📬</div>
    <h1 style="font-size:var(--text-xl);margin-bottom:.5rem;">Sprawdź skrzynkę e-mail</h1>
    <?php if (!empty($email)): ?>
    <p style="color:var(--c-text-muted);margin-bottom:1.5rem;">
        Wysłaliśmy link aktywacyjny na adres<br>
        <strong style="color:var(--c-text);"><?= htmlspecialchars($email) ?></strong>
    </p>
    <?php endif; ?>
    <p style="color:var(--c-text-muted);font-size:var(--text-sm);margin-bottom:1.5rem;">
        Link jest ważny przez 48 godzin. Sprawdź też folder spam.
    </p>
    <form method="POST" action="/auth/resend-verification">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="btn btn--ghost btn--sm">
            Wyślij link ponownie
        </button>
    </form>
    <p style="margin-top:2rem;font-size:var(--text-sm);">
        <a href="/login" style="color:var(--c-text-muted);">← Wróć do logowania</a>
    </p>
</div>
```

**2. Nowy plik `templates/auth/verify-error.php`:**
```php
<?php declare(strict_types=1); ?>
<div style="max-width:480px;margin:4rem auto;padding:2rem;background:rgba(14,27,47,.55);
            backdrop-filter:blur(4px);border-radius:var(--radius);text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">⏱</div>
    <h1 style="font-size:var(--text-xl);margin-bottom:.5rem;">Link weryfikacyjny wygasł</h1>
    <p style="color:var(--c-text-muted);margin-bottom:1.5rem;">
        Link weryfikacyjny jest nieprawidłowy lub wygasł (ważność: 48h).<br>
        Wyślij nowy link lub zaloguj się, by kontynuować.
    </p>
    <?php if (!empty($email)): ?>
    <form method="GET" action="/auth/resend-verification" style="margin-bottom:1rem;">
        <button type="submit" class="btn btn--primary">
            Wyślij nowy link
        </button>
    </form>
    <?php endif; ?>
    <p style="font-size:var(--text-sm);">
        <a href="/login" style="color:var(--c-text-muted);">← Wróć do logowania</a>
    </p>
</div>
```

**3. Trasy w `src/Core/routes.php`** — dodać w sekcji public routes:
```php
$router->get('/auth/check-email',          fn($req) => $auth->showCheckEmail($req));
$router->post('/auth/resend-verification', fn($req) => $auth->resendVerification($req));
$router->get('/auth/verify',               fn($req) => $auth->verify($req));
```

**4. `AnalysisController::dashboard()`** — dodać `$emailVerified` do danych widoku:
```php
// Dodać po $alertsEnabled:
$userRepo      = new \CVS\Auth\UserRepository();
$emailVerified = $userRepo->isEmailVerified($userId);

// Dodać do tablicy Response::view('dashboard', [...]):
'emailVerified' => $emailVerified,
```

**5. `templates/dashboard.php`** — baner dla niezweryfikowanych (po `<section class="dashboard">`):
```php
<?php if (!($emailVerified ?? true)): ?>
<div class="alert" style="margin-bottom:1rem;background:rgba(224,82,82,.15);border:1px solid rgba(224,82,82,.3);border-radius:var(--radius);padding:.75rem 1rem;font-size:var(--text-sm);">
    ⚠ <strong>Potwierdź adres e-mail</strong>, by włączyć alerty.
    <a href="/auth/resend-verification" style="margin-left:.75rem;color:var(--c-primary);">Wyślij link ponownie</a>
</div>
<?php endif; ?>
```

### Success Criteria

#### Automated Verification
- `composer stan` — 0 błędów
- Szablony istnieją: `templates/auth/check-email.php`, `templates/auth/verify-error.php`
- Trasy zarejestrowane w `routes.php`
- `dashboard()` zwraca `$emailVerified` do szablonu
- `php -l templates/auth/check-email.php && php -l templates/auth/verify-error.php` — 0 błędów

#### Manual Verification
- GET `/auth/check-email` → strona "Sprawdź skrzynkę" widoczna (bez wymaganego logowania)
- "Wyślij link ponownie" → reload strony z komunikatem "Nowy link został wysłany."
- GET `/auth/verify?token=WYGASLY` → strona "Link weryfikacyjny wygasł"
- Dashboard dla konta z NULL `email_verified_at`: widoczny baner z linkiem
- Dashboard dla konta zweryfikowanego: baner niewidoczny

---

## Progress

### Phase 1: Migracja 021 — kolumny weryfikacyjne

#### Automated
- [x] 1.1 Plik `database/migrations/021_add_email_verification.sql` istnieje
- [x] 1.2 composer stan — 0 błędów (migracja SQL, PHP bez zmian)

#### Manual
- [x] 1.3 Migracja wykonana na DB, 3 kolumny w `users`, istniejący użytkownicy mają `email_verified_at` NOT NULL

### Phase 2: UserRepository — metody weryfikacyjne

#### Automated
- [ ] 2.1 `setVerifyToken()` dodana
- [ ] 2.2 `findByVerifyToken()` dodana
- [ ] 2.3 `setEmailVerified()` dodana
- [ ] 2.4 `isEmailVerified()` dodana
- [ ] 2.5 `composer stan` — 0 błędów

#### Manual
- [ ] 2.6 (testowanie przez integrację w fazie 3)

### Phase 3: AuthController — przepływ weryfikacji

#### Automated
- [ ] 3.1 `MailService $mail` property + `use CVS\Mail\MailService` dodane
- [ ] 3.2 `register()` generuje token + wysyła email + redirect check-email (brak logowania)
- [ ] 3.3 `login()` blokuje niezweryfikowanych → resend + redirect check-email
- [ ] 3.4 `showCheckEmail()` dodana
- [ ] 3.5 `resendVerification()` dodana
- [ ] 3.6 `verify()` dodana — sukces: sesja + redirect dashboard z flash
- [ ] 3.7 `buildVerificationHtml()` dodana (private)
- [ ] 3.8 `composer stan` — 0 błędów

#### Manual
- [ ] 3.9 Rejestracja → brak sesji, redirect check-email, email dociera
- [ ] 3.10 Kliknięcie linku → zalogowany, flash, dashboard
- [ ] 3.11 Logowanie na niezweryfikowane → redirect check-email z nowym mailem
- [ ] 3.12 Wygasły link → strona verify-error

### Phase 4: AlertController — gate weryfikacji

#### Automated
- [ ] 4.1 Gate `isEmailVerified` w `toggleGlobal()` dodany (blokuje `$new === true`)
- [ ] 4.2 Obsługa `needs_verification` w JS dashboardu (inline message)
- [ ] 4.3 `composer stan` — 0 błędów

#### Manual
- [ ] 4.4 Konto z NULL `email_verified_at`: toggle 🔕→🔔 zablokowany, pojawia się komunikat
- [ ] 4.5 Konto zweryfikowane: toggle działa normalnie

### Phase 5: Szablony, trasy, dashboard baner

#### Automated
- [ ] 5.1 `templates/auth/check-email.php` istnieje
- [ ] 5.2 `templates/auth/verify-error.php` istnieje
- [ ] 5.3 3 trasy dodane w `routes.php` (GET check-email, POST resend, GET verify)
- [ ] 5.4 `dashboard()` zwraca `$emailVerified` do szablonu
- [ ] 5.5 `composer stan` — 0 błędów
- [ ] 5.6 `php -l` na nowych szablonach — 0 błędów

#### Manual
- [ ] 5.7 GET `/auth/check-email` renderuje się poprawnie
- [ ] 5.8 "Wyślij link ponownie" → flash "Nowy link wysłany" na check-email
- [ ] 5.9 GET `/auth/verify?token=WYGASLY` → strona verify-error
- [ ] 5.10 Dashboard: baner dla NULL, brak banera dla zweryfikowanego
