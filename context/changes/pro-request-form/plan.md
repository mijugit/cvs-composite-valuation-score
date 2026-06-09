# S-05: Formularz prośby o kod PRO — Implementation Plan

## Overview

Rozszerza istniejący modal PRO na `/analysis/{ticker}` o sekcję
„Napisz do admina". User podaje imię (opcjonalne) + wiadomość (opcjonalne,
max 500 znaków); email pobieramy z sesji. Po kliknięciu „Wyślij prośbę"
wysyłamy mail do admina przez `MailService::sendToAdmin()` i wyświetlamy
flash message. Przycisk „Wyślij prośbę" blokuje się w sesji by zapobiec
spamowi.

## Current State Analysis

- Modal PRO (`#pro-modal` w `templates/analysis.php`) ma już formularz
  z polem na kod + przycisk „Aktywuj". Endpoint `POST /pro/activate`
  obsługuje aktywację.
- `MailService::sendToAdmin()` gotowy (F-03) — wystarczy wywołać.
- `ProController` ma metody: `index`, `store`, `revoke`, `activateCode`,
  `activate` — wzorzec jasny, dodajemy metodę `sendRequest()`.
- CSRF: `$req->verifyCsrf()` + `$_SESSION['csrf_token']` w każdym formularzu.
- Flash: brak globalnego systemu flash — używamy `$_SESSION['_flash']` (wzorzec
  z ProController::store/revoke).
- Session flag: `$_SESSION['pro_request_sent']` = true → blokada w bieżącej sesji.

## Desired End State

- Modal PRO na stronie analizy ma dwie sekcje:
  1. „Mam kod PRO" — pole + Aktywuj (już istnieje)
  2. „Nie mam kodu" — pole imienia + pole wiadomości + Wyślij prośbę
- Po wysłaniu: `$_SESSION['pro_request_sent'] = true`, flash „Prośba wysłana",
  przycisk zamienia się na „Prośba wysłana ✓".
- Admin dostaje mail z: kto prosi (email z sesji + podane imię), wiadomość,
  link do `/admin/pro`.
- PHPUnit + PHPStan zielone.

### Key Discoveries

- `templates/analysis.php` — `#pro-modal` inline script, `#btn-pro-submit`
  obsługiwany przez AJAX do `/pro/activate`. Nowy formularz „wyślij prośbę"
  pójdzie do `POST /pro/request` (synchroniczny POST + redirect, nie AJAX —
  prostsze, wyniki widoczne w całym HTML).
- `$_SESSION['_flash']` wzorzec już w `ProController` — flash po redirect.
- Alergia na AJAX dla prośby: synchroniczny POST → redirect back z flash jest
  prostszy i nie wymaga dodatkowego JS.

## What We're NOT Doing

- Bazy danych / tabeli na prośby (wystarczy mail + log).
- Rate limiting per IP (dla ~10 znajomych niepotrzebne).
- Powiadomienia email dla usera po otrzymaniu kodu (admin odpowie ręcznie).
- Widoku listy prośb dla admina (wystarczy skrzynka mailowa).

## Implementation Approach

1 faza — wszystko razem: endpoint `ProController::sendRequest()`,
trasa, update modalu w `analysis.php`, update `layout.php` dla
flash message (opcjonalnie — albo inline w response).

---

## Phase 1: Endpoint + modal + mail

### Overview

Nowa metoda w ProController, trasa POST /pro/request, rozszerzenie modalu PRO
w analysis.php o formularz prośby.

### Changes Required

#### 1. ProController::sendRequest()

**File:** `src/Pro/ProController.php`

**Intent:** Obsłuż POST /pro/request — waliduj CSRF, pobierz email z sesji,
zbuduj mail HTML, wyślij przez MailService, ustaw flash + session flag,
redirect back.

**Contract:** Nowa metoda `public function sendRequest(Request $req): void`.
- `requireAuth()` + `verifyCsrf()` → 403 redirect na fail
- Sprawdź `$_SESSION['pro_request_sent']` → jeśli true, redirect z flash
  „Prośba już wysłana"
- `$name = substr(trim($req->input('name', '')), 0, 100)`
- `$message = substr(trim($req->input('message', '')), 0, 500)`
- `$userEmail = $_SESSION['user_email'] ?? ''` (dodać email do sesji przy loginie
  — patrz niżej)
- Zbuduj HTML mail z: kto (email + imię), wiadomość, link `/admin/pro`
- `$mailSvc->sendToAdmin('Prośba o kod PRO', $html)` → bool
- Ustaw `$_SESSION['pro_request_sent'] = true`
- Ustaw `$_SESSION['_flash'] = 'Prośba wysłana — admin skontaktuje się wkrótce.'`
- `Response::redirect($_SERVER['HTTP_REFERER'] ?? '/dashboard')`

#### 2. Sesja — user email przy loginie

**File:** `src/Auth/AuthController.php`

**Intent:** Przy udanym logowaniu zapisać email do sesji żeby `sendRequest()` mógł
go użyć w mailu bez dodatkowego DB lookup.

**Contract:** W metodzie `login()`, po `$_SESSION['user_id'] = $user['id']` dodać
`$_SESSION['user_email'] = $user['email']`.

#### 3. MailService w ProController

**File:** `src/Pro/ProController.php` (konstruktor)

**Intent:** Wstrzyknąć `MailService` do ProController żeby `sendRequest()` mógł
go wywołać bez singletonowej konstrukcji.

**Contract:** Dodać `private MailService $mail;` i w konstruktorze
`$this->mail = new MailService(null, require ... . '/config/mail.php');`.
Import `use CVS\Mail\MailService;`.

#### 4. Trasa POST /pro/request

**File:** `src/Core/routes.php`

**Contract:** Dodać `$router->post('/pro/request', fn($req) => $pro->sendRequest($req));`
w sekcji PRO access.

#### 5. Rozszerzenie modalu PRO w analysis.php

**File:** `templates/analysis.php` (`#pro-modal`)

**Intent:** Pod formularzem aktywacji kodu dodać poziomą linię + sekcję
„Nie masz kodu? Napisz do admina" z formularzem synchronicznym (nie AJAX).

**Contract:** W `#pro-modal` po przycisku „Aktywuj/Anuluj" dodać:
```html
<hr style="border-color:var(--c-border);margin:1rem 0;">
<p style="font-size:var(--text-sm);color:var(--c-muted);margin-bottom:.75rem;">
    Nie masz kodu? Wyślij prośbę do admina.
</p>
<!-- Show request sent state from session -->
<?php if (!empty($_SESSION['pro_request_sent'])): ?>
<p style="color:var(--c-success);font-size:var(--text-sm);">✓ Prośba wysłana — admin skontaktuje się wkrótce.</p>
<?php else: ?>
<form method="POST" action="/pro/request">
    <input type="hidden" name="_csrf" value="...csrf...">
    <div class="form-group" style="margin-bottom:.5rem;">
        <input type="text" name="name" placeholder="Twoje imię (opcjonalne)" maxlength="100">
    </div>
    <div class="form-group" style="margin-bottom:.75rem;">
        <textarea name="message" rows="2" placeholder="Do czego chcesz używać PRO? (opcjonalne)" maxlength="500"></textarea>
    </div>
    <button type="submit" class="btn btn--secondary btn--sm" style="width:100%;">Wyślij prośbę do admina</button>
</form>
<?php endif; ?>
```

#### 6. Flash display w layout.php (opcjonalne)

**File:** `templates/layout.php`

**Intent:** Wyświetlić flash message z `$_SESSION['_flash']` na górze strony
po redirect (wzorzec już istnieje w ProController ale nigdzie nie jest
renderowany globalnie).

**Contract:** Po `<main class="site-main">` dodać:
```php
<?php if (!empty($_SESSION['_flash'])): ?>
<div class="container" style="padding-top:.75rem;">
    <div class="alert alert--success"><?= htmlspecialchars($_SESSION['_flash']) ?></div>
</div>
<?php unset($_SESSION['_flash']); endif; ?>
```

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- Trasa `POST /pro/request` zarejestrowana w `routes.php`

#### Manual Verification
- Na `/analysis/AAPL` kliknij „Wprowadź kod PRO" → modal ma sekcję „Napisz do admina"
- Wypełnij imię + wiadomość → kliknij „Wyślij prośbę" → flash „Prośba wysłana"
- Admin dostaje mail z emailem usera, imieniem, wiadomością i linkiem `/admin/pro`
- Ponowne kliknięcie (ta sama sesja) → modal pokazuje „✓ Prośba wysłana"
- Brak regresji aktywacji kodu (AJAX modal nadal działa)

---

## Testing Strategy

### Unit Tests

Brak nowych (logika prosta, przepływ manualny wystarczy). PHPStan i PHPUnit 
muszą pozostać zielone.

### Manual Testing Steps

1. Zaloguj jako user bez PRO
2. Wejdź na `/analysis/AAPL`, kliknij „Wprowadź kod PRO"
3. W modalu powinna pojawić się sekcja prośby
4. Wypełnij pola i kliknij „Wyślij"
5. Sprawdź inbox admina (`blog@timeflow.fun`) — mail z prośbą
6. Odśwież stronę, kliknij znów modal — pokaż „✓ Prośba wysłana"

## Performance Considerations

Synchroniczny POST + redirect, wolumen maks. kilka prośb/miesiąc. Pomijalny.

## Migration Notes

Brak zmian DB. Rollback: usunąć metodę, trasę i sekcję formularza.

## References

- Roadmap: `context/foundation/roadmap.md` (S-05)
- PRD: `context/foundation/prd.md` (FR-006)
- ProController: `src/Pro/ProController.php` (wzorzec store/revoke)
- MailService: `src/Mail/MailService.php` (sendToAdmin)
- Modal PRO: `templates/analysis.php` (#pro-modal)
- Flash wzorzec: `$_SESSION['_flash']` w ProController

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Endpoint + modal + mail

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony — 7a8cf2d
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — 7a8cf2d
- [x] 1.3 Trasa `POST /pro/request` zarejestrowana — 7a8cf2d

#### Manual
- [x] 1.4 Modal PRO ma sekcję „Napisz do admina"
- [x] 1.5 Wysłanie prośby → mail do admina z imieniem, wiadomością, linkiem
- [x] 1.6 Ponowne kliknięcie w sesji → komunikat „✓ Prośba wysłana"
- [x] 1.7 Aktywacja kodu PRO nadal działa (brak regresji)
