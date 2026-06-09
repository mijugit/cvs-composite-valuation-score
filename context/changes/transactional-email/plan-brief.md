# F-03: Serwis maili transakcyjnych — Plan Brief

> Full plan: `context/changes/transactional-email/plan.md`

## What & Why

Centralny serwis maili transakcyjnych (`CVS\Mail\MailService`) oparty na
PHPMailer + SMTP CF. Fundament pod alerty watchlisty (S-04) i formularz
prośby o kod PRO (S-05) — bez maila żadne z tych nie może działać.

## Starting Point

Brak PHPMailer w composer.json, brak zmiennych MAIL_* w .env, brak src/Mail/.
Sprawdzony wzorzec istnieje w C:\python\blog\api\mailer.php (działa na CF/timeflow.fun).

## Desired End State

`MailService::send()` i `::sendToAdmin()` działają na CF, mail testowy dociera
na admin@example.com. Brak SMTP → graceful false + error_log, bez wyjątków
propagujących do HTTP.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| SMTP | Istniejące konto CF/timeflow.fun | Dostarczalność zweryfikowana przez blog |
| Szablony | Inline PHP strings (HTML + plain text) | Zero dodatkowych zależności, wzorzec z bloga |
| Failure mode | bool false + error_log | PRD mówi "log + return false", prostsze niż typed result |
| Testy | ?PHPMailer injection, brak env = false | Graceful path testowalny bez SMTP |

## Scope

**In scope:** `composer require phpmailer/phpmailer`, `src/Mail/MailService.php`,
`config/mail.php`, zmienne MAIL_* w .env (lokalnie puste, CF uzupełnione),
testy jednostkowe.

**Out of scope:** Treść maili alertów (S-04), formularz PRO (S-05), kolejkowanie,
bulk mail, HTML template engine.

## Architecture / Approach

```
MailService::send(to, subject, htmlBody, altBody?, unsubscribeUrl?)
  └─ guard: smtp_host empty → error_log + false
  └─ createMailer() → PHPMailer z SMTP credentials z .env
  └─ Message-ID domain-aware, XMailer suppressed
  └─ AltBody = altBody ?? htmlToPlainText(html)
  └─ send() → true | catch Exception → error_log + false

MailService::sendToAdmin(subject, html)
  └─ guard: admin_email empty → false
  └─ send(admin_email, subject, html)
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Kod | PHPMailer install, MailService, config, testy | PHPStan strict types + ?PHPMailer injection |
| 2. Deploy | CF .env credentials, SSH test-send, mail w skrzynce | SMTP host CF — trzeba pobrać z panelu |

**Prerequisites:** Brak (F-03 jest niezależnym fundamentem).
**Estimated effort:** ~0.5 sesji, 2 fazy.

## Open Risks & Assumptions

- SMTP credentials CF (host/user/pass dla noreply@example.com) muszą być
  pobrane z panelu CF przed Fazą 2.
- Jeśli SPF/DKIM dla noreply@example.com nie jest skonfigurowane,
  mail może trafić do spamu — do sprawdzenia po wysłaniu.

## Success Criteria (Summary)

- `MailService::send()` bez SMTP → false (test jednostkowy zielony).
- `sendToAdmin('Test', '<p>Test</p>')` na CF → bool(true).
- Mail widoczny w inbox admin@example.com.
