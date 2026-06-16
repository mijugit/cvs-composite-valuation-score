---
change_id: email-verification
title: Weryfikacja email przy rejestracji — magiczny link aktywacyjny
status: implemented
created: 2026-06-16
updated: 2026-06-16
---

## Summary

Po rejestracji użytkownik dostaje link weryfikacyjny na email. Bez kliknięcia linku nie może włączyć alertów email. Nowi użytkownicy nie są logowani od razu — sesja zakładana dopiero po weryfikacji.

## Motivation

RODO art. 6(1)(a) — zgoda jako podstawa prawna alertów email wymaga potwierdzenia, że adres email należy do użytkownika. Bez weryfikacji moglibyśmy wysyłać maile na adres, który ktoś podał przez pomyłkę lub złośliwie.

## Decisions

- Token: kolumny w DB (`email_verify_token VARCHAR(64)`, `email_verify_expires_at DATETIME`, `email_verified_at DATETIME`) — migracja 021
- UX: po rejestracji strona `/auth/check-email`, sesja zakładana dopiero po kliknięciu linku
- Blokada: tylko globalny toggle alertów (przycisk 🔔 ON/OFF w dashboardzie)
- Istniejący użytkownicy: `email_verified_at = created_at` w migracji (grandfathering)
- Expiry tokenu: 48 godzin
- Re-send: na stronie check-email + baner na dashboardzie (defensive)
- Błąd wygasłego tokenu: osobna strona z przyciskiem "Wyślij nowy link"
- Email: styl HTML zgodny z alertami (tabela, #1e3a5f), temat "CVS — potwierdź adres e-mail"
