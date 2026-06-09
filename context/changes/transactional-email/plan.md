# F-03: Serwis maili transakcyjnych — Implementation Plan

## Overview

Jeden serwis maili transakcyjnych (`CVS\Mail\MailService`) oparty na
PHPMailer + SMTP, konfigurowany wyłącznie z `.env`. Wzorzec przepisany z
`C:\python\blog\api\mailer.php` (sprawdzony na CF/timeflow.fun). Graceful
failure: SMTP nie skonfigurowany lub błąd wysyłki → `error_log` + return
false, nigdy wyjątek propagujący do HTTP. Fundament pod S-04 i S-05.

## Current State Analysis

- **PHPMailer:** nie ma w `composer.json` — trzeba zainstalować.
- **SMTP .env:** brak zmiennych `MAIL_*` — trzeba dodać lokalnie i na CF.
- **Brak `src/Mail/`** — namespace `CVS\Mail\` nie istnieje.
- **Wzorzec:** `C:\python\blog\api\mailer.php` — PHPMailer, Message-ID,
  X-Mailer suppressed, AltBody auto-generated, bool graceful failure.
- **CF SMTP:** te same credentials co blog (timeflow.fun, hosting CF).
- **Graceful failure wzorzec:** `src/Ai/ClaudeClient.php` — nigdy nie rzuca,
  każdy błąd mapowany na typed result; dla maila wystarczy bool+log.

## Desired End State

- `composer.json` zawiera `phpmailer/phpmailer ^6.9`.
- `src/Mail/MailService.php` implementuje `send()`, `sendToAdmin()`,
  `htmlToPlainText()`; akceptuje `?PHPMailer` dla injekcji testowej.
- `config/mail.php` czyta SMTP config z `$_ENV`.
- `.env` (lokalne + CF) ma komplet zmiennych `MAIL_*`.
- PHPUnit + PHPStan zielone.
- Mail testowy wysłany na `admin@example.com` przez SSH.

### Key Discoveries

- Blog wzorzec: guard `SMTP_HOST === ''` → return false zanim `createMailer()`.
  To samo w `MailService`: jeśli `$_ENV['MAIL_SMTP_HOST']` pusty → log + false.
- PHPMailer `SMTPSecure`: `'tls'` (STARTTLS, port 587) lub `'ssl'` (SMTPS, port 465).
  CF używa zazwyczaj port 587/tls — potwierdzić w panelu.
- Test injection: konstruktor `__construct(?PHPMailer $mailer = null)` — gdy null
  tworzy z configa, gdy podany używa go (test mode). W testach brak env →
  guard wypali → return false — wystarczy do coverage graceful path.
- `src/Mail/` tworzy nowy namespace `CVS\Mail\`; PSR-4 auto-load przez `composer.json`.

## What We're NOT Doing

- Bulk/marketing maili.
- Systemu kolejkowania maili (synchroniczne send, wolumen niski).
- HTML template engine — inline PHP strings wystarczą.
- Panel UI do zarządzania mailami.
- Retry logiki (send fail → log + false, caller retry jeśli chce).

## Implementation Approach

2 fazy: (1) kod lokalny — PHPMailer install + MailService + config + testy;
(2) deploy + konfiguracja CF .env + weryfikacja live.

---

## Phase 1: PHPMailer + MailService + config + testy

### Overview

Instalacja PHPMailer przez Composer, serwis mail, config, testy jednostkowe.

### Changes Required

#### 1. Composer install

**File:** `composer.json` (pośrednio — przez `composer require`)

**Intent:** Dodać PHPMailer jako zależność produkcyjną.

**Contract:** `composer require phpmailer/phpmailer` — dodaje
`"phpmailer/phpmailer": "^6.9"` do sekcji `require` i aktualizuje `composer.lock`.

#### 2. MailService

**File:** `src/Mail/MailService.php` (namespace `CVS\Mail`)

**Intent:** Centralny punkt do wysyłki maili transakcyjnych w CVS. Migruje
wzorzec funkcyjny z bloga na klasy PSR-4. Akceptuje opcjonalny `?PHPMailer`
dla injekcji testowej; gdy null, buduje instancję z configa.

**Contract:**
```
class MailService {
    __construct(?PHPMailer $mailer = null, array $config = [])

    send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $altBody = null,
        ?string $unsubscribeUrl = null
    ): bool

    sendToAdmin(string $subject, string $htmlBody): bool

    private createMailer(): ?PHPMailer   // null gdy SMTP nie skonfigurowany
    private htmlToPlainText(string $html): string
}
```

Kluczowe guardrails (mirroring bloga):
- `createMailer()` sprawdza `$config['smtp_host'] !== ''`; jeśli pusty →
  `error_log('[Mail] SMTP not configured')` + return null.
- `send()` sprawdza `createMailer() === null` → return false.
- Catch `\Exception` → `error_log('[Mail] Failed: ...')` + return false.
- Message-ID: `bin2hex(random_bytes(16)) . '@' . $domain`
- `$mail->XMailer = ' '` (suppress default)
- AltBody: `$altBody ?? $this->htmlToPlainText($htmlBody)`

#### 3. Config mail

**File:** `config/mail.php`

**Intent:** Jeden plik konfiguracyjny czytający SMTP z `$_ENV`, wzorzec identyczny
jak `config/ai.php`.

**Contract:** Zwraca array (nazwy zmiennych identyczne jak w blogu — już uzupełnione na CF):
```php
return [
    'smtp_host'       => (string) ($_ENV['SMTP_HOST']       ?? ''),
    'smtp_port'       => (int)    ($_ENV['SMTP_PORT']        ?? 465),
    'smtp_user'       => (string) ($_ENV['SMTP_USER']        ?? ''),
    'smtp_pass'       => (string) ($_ENV['SMTP_PASSWORD']    ?? ''),
    'smtp_encryption' => (string) ($_ENV['SMTP_ENCRYPTION']  ?? 'ssl'),
    'from_email'      => (string) ($_ENV['SMTP_FROM_EMAIL']  ?? ''),
    'from_name'       => (string) ($_ENV['SMTP_FROM_NAME']   ?? 'CVS Composite Valuation Score'),
    'admin_email'     => (string) ($_ENV['ADMIN_EMAIL']      ?? ''),
];
```

#### 4. Zmienne .env (lokalne — puste, CF — uzupełnione)

**File:** `.env` (lokalny, na CF przez SSH)

**Intent:** Dodać blok `MAIL_*` do pliku .env. Lokalnie puste (maile nie
idą w dev) — graceful failure. Na CF uzupełnione prawdziwymi danymi z panelu
CF → Serwer WWW → Konta email.

**Contract:** 8 nowych zmiennych:
```
MAIL_SMTP_HOST=
MAIL_SMTP_PORT=587
MAIL_SMTP_USER=
MAIL_SMTP_PASS=
MAIL_SMTP_ENCRYPTION=tls
MAIL_FROM_EMAIL=noreply@example.com
MAIL_FROM_NAME=CVS Composite Valuation Score
MAIL_ADMIN_EMAIL=admin@example.com
```
Na CF: uzupełnić MAIL_SMTP_HOST, MAIL_SMTP_USER, MAIL_SMTP_PASS z panelu.

#### 5. Testy

**File:** `tests/Mail/MailServiceTest.php`

**Intent:** Weryfikacja graceful failure (SMTP nie skonfigurowany → false),
poprawności adresu admin i htmlToPlainText.

**Contract:** Testy:
- `test_send_returns_false_when_smtp_not_configured()` — brak env → false
- `test_send_to_admin_returns_false_when_admin_email_empty()` — brak MAIL_ADMIN_EMAIL → false
- `test_html_to_plain_text_strips_tags()` — weryfikacja konwersji

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (nowe testy Mail)
- `vendor/bin/phpstan analyse` zielony
- `grep -r "phpmailer" composer.json` — PHPMailer widoczny

#### Manual Verification
- `php -r "require 'vendor/autoload.php'; use CVS\Mail\MailService; $s = new CVS\Mail\MailService(); var_dump($s->send('test@test.com','Test','<p>Test</p>'));"` → false (brak SMTP)

---

## Phase 2: Deploy + konfiguracja CF .env + weryfikacja

### Overview

Deploy kodu na CF, uzupełnienie SMTP credentials w .env, wysłanie testowego
maila przez SSH, potwierdzenie że mail dotarł.

### Changes Required

#### 1. CF .env — uzupełnienie SMTP

**File:** `.env` na serwerze CF (przez SSH printf)

**Intent:** Dodać prawdziwe SMTP credentials z panelu CF. Dane z:
CF panel → Serwer WWW → Konta email → (wybierz/stwórz konto noreply@example.com).

**Contract:** Zaktualizować .env na CF przez SSH `printf` (bez BOM):
```
MAIL_SMTP_HOST=<z panelu CF, np. <mail-host> lub smtp.cyberfolks.pl>
MAIL_SMTP_USER=noreply@example.com
MAIL_SMTP_PASS=<hasło z panelu CF>
```
Pozostałe zmienne (PORT, ENCRYPTION, FROM_*, ADMIN_EMAIL) z defaultów.

#### 2. Weryfikacja live przez SSH

**Krok manualny:** Po deploy uruchomić test-send przez SSH:
```bash
/usr/local/bin/php84 -r "
define('ROOT_PATH', '/home/<cf-user>/sites/cvs.timeflow.fun');
require ROOT_PATH . '/vendor/autoload.php';
// Load .env
foreach (file(ROOT_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as \$line) {
  if (!str_starts_with(trim(\$line), '#') && str_contains(\$line, '='))
    [\$k, \$v] = explode('=', \$line, 2);
    \$_ENV[trim(\$k)] = trim(\$v);
}
\$config = require ROOT_PATH . '/config/mail.php';
\$svc = new CVS\Mail\MailService(null, \$config);
var_dump(\$svc->sendToAdmin('Test CVS Mail', '<p>Test wysylki maili z CVS.</p>'));
"
```

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- SSH test-send → `bool(true)` (nie `false`)
- Mail dotarł na `admin@example.com` — widoczny w skrzynce

---

## Testing Strategy

### Unit Tests

- `MailService::send()` gdy SMTP nie skonfigurowany → false + error_log
- `MailService::sendToAdmin()` gdy brak admin_email → false
- `MailService::htmlToPlainText()` — linki, tagi, whitespace normalization

### Manual Testing Steps

1. Po deploy + .env CF: uruchom snippet SSH powyżej
2. Sprawdź inbox `admin@example.com` — mail z tematem "Test CVS Mail"
3. Sprawdź spam — jeśli tam, może być problem z SPF/DKIM dla noreply@example.com

## Performance Considerations

Synchroniczne wysyłanie, wolumen maks. ~10-20 maili/dzień dla ~10 userów.
Brak kolejkowania — akceptowalne. Graceful failure nigdy nie blokuje HTTP flow.

## Migration Notes

Brak zmian DB. Rollback: usunąć phpmailer z composer.json + src/Mail/ + config/mail.php.

## References

- Roadmap: `context/foundation/roadmap.md` (F-03)
- PRD: `context/foundation/prd.md` (FR-005)
- Wzorzec: `C:\python\blog\api\mailer.php` (sendMail, createMailer, htmlToPlainText)
- CLAUDE.md: sekcja "Transactional email" (linia ~64)
- Config wzorzec: `config/ai.php`
- Graceful failure wzorzec: `src/Ai/ClaudeClient.php:40-84`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: PHPMailer + MailService + config + testy

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony (nowe testy Mail) — d1b6a0c
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — d1b6a0c
- [x] 1.3 `grep -r phpmailer composer.json` — PHPMailer widoczny — d1b6a0c

#### Manual
- [x] 1.4 Lokalne wywołanie MailService bez SMTP → zwraca false

### Phase 2: Deploy + konfiguracja CF .env + weryfikacja

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony — d1b6a0c
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — d1b6a0c

#### Manual
- [x] 2.3 SSH test-send → bool(true)
- [x] 2.4 Mail dotarł na admin@example.com (2026-06-02 10:22)
