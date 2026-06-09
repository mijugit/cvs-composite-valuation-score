# S-05: Formularz prośby o kod PRO — Plan Brief

> Full plan: `context/changes/pro-request-form/plan.md`

## What & Why

Użytkownik bez kodu PRO klikając „Wprowadź kod PRO" widzi teraz dwie opcje:
wpisz kod (już istnieje) **lub** wyślij prośbę do admina. Bez tego nie ma
żadnego sposobu żeby poprosić o kod z poziomu aplikacji.

## Starting Point

Modal `#pro-modal` na `/analysis/{ticker}` ma pole kodu + „Aktywuj". `MailService`
gotowy (F-03). Brakuje formularza prośby i endpointu.

## Desired End State

Modal PRO rozdzielony na dwie sekcje: aktywacja kodu + formularz prośby
(imię + wiadomość, email z sesji). Po wysłaniu: flash + blokada w sesji.
Admin dostaje mail z kontekstem + linkiem do `/admin/pro`.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| Lokacja | Rozszerzenie istniejącego modalu | Naturalny flow — user chce PRO, od razu pyta |
| Pola | Imię + wiadomość (opcjonalne) | Admin ma kontekst, zero friction |
| Feedback | Flash + session flag (brak ponownego wysyłania) | Prosty anti-spam |
| Submit | Synchroniczny POST (nie AJAX) | Prostsze niż AJAX dla 1-off formularza |
| Mail | sendToAdmin() z email usera + imię + wiadomość + link /admin/pro | Kompletna info dla admina |

## Scope

**In scope:** `ProController::sendRequest()`, trasa `/pro/request`, update modalu,
zapis email do sesji przy loginie, flash w layout.php.

**Out of scope:** DB storage prośb, rate limiting, powiadomienie email dla usera.

## Architecture / Approach

```
User klika "Wyślij prośbę" (synchroniczny form POST)
  └─ POST /pro/request → ProController::sendRequest()
       ├─ requireAuth() + verifyCsrf()
       ├─ $_SESSION['pro_request_sent'] guard
       ├─ MailService::sendToAdmin(html z emailem+imieniem+wiadomością+linkiem)
       ├─ $_SESSION['pro_request_sent'] = true
       ├─ $_SESSION['_flash'] = 'Prośba wysłana...'
       └─ redirect back
Modal przy następnym otwarciu: pokazuje "✓ Prośba wysłana"
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Wszystko | Endpoint + modal + mail + flash | Brak regresji aktywacji kodu |

**Prerequisites:** F-03 ✅ (MailService gotowy)
**Estimated effort:** ~0.3 sesji, 1 faza.

## Success Criteria (Summary)

- Modal PRO ma sekcję „Napisz do admina" z formularzem.
- Wysłanie prośby → mail do admina + flash w UI.
- Ponowne wysłanie w tej samej sesji blokowane (komunikat „✓ Prośba wysłana").
