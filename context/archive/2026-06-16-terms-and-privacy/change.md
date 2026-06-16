---
change_id: terms-and-privacy
title: Regulamin, Polityka Prywatności i link wypisania z alertów
status: archived
archived_at: 2026-06-16T09:58:20Z
created: 2026-06-16
updated: 2026-06-16
---

## Motywacja

Aplikacja CVS nie posiada Regulaminu ani Polityki Prywatności, co jest wymagane przez RODO (UE 2016/679) i ePrivacy. Alerty e-mail nie zawierają linku do wypisania się, co jest wymagane przez art. 7 ust. 3 RODO (wycofanie zgody tak samo łatwe jak udzielenie).

## Zakres

- Szablon `/terms-of-service` (publiczna trasa, bez logowania)
- Szablon `/privacy-policy` (publiczna trasa, bez logowania)
- Linki do obu stron w stopce layout + na formularzu rejestracji
- Endpoint `GET /alerts/unsubscribe?uid=X&token=Y` (bez logowania)
- Stopka każdego maila alertowego z linkiem wypisania (HMAC-SHA256)
- `APP_SECRET` w `.env.example` do podpisywania tokenów HMAC

## Kluczowe decyzje

- **Cookie consent**: NIE wymagany — CVS używa tylko strictly necessary cookies (sesja PHP + CSRF)
- **Unsubscribe token**: HMAC-SHA256(key=APP_SECRET, data="unsub:{userId}:{email}") — brak migracji DB
- **Dane osobowe do USA**: Anthropic (Claude API) przetwarza tickery i dane CVS, nie dane osobowe; transfer na podstawie SCC
- **Podstawa prawna alertów**: art. 6 ust. 1 lit. a RODO (zgoda), wycofywalna w każdej chwili
