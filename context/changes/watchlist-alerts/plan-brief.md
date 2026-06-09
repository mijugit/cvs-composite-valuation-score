# S-04: Alerty watchlisty — Plan Brief

> Full plan: `context/changes/watchlist-alerts/plan.md`

## What & Why

Mail do usera gdy spółka z jego watchlisty zmieni rekomendację CVS lub złoty sygnał.
Bez alertów user musi manualnie sprawdzać każdą spółkę — system powinien go informować
o istotnych zmianach, nie zalewać codziennym spamem.

## Starting Point

`MailService` gotowy (F-03), `cvs_snapshots` zasilane codziennie (F-04).
Brakuje: tabel preferencji (user_alert_settings, user_alert_ticker, alert_sent),
`AlertRepository`, `AlertService` i UI toggles.

## Desired End State

User włącza alerty na dashboardzie (domyślnie OFF). Po każdym rescorze, gdy
reko lub sygnał zmienił się względem ostatnio wysłanego — mail z `stara reko → nowa reko`
+ CVS Swing + link. Kolejne rescory bez zmiany → brak maila. Per-ticker można
wyłączyć z poziomu analizy spółki.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| Trigger | Zmiana reco_swing LUB golden_signal | Oba typy zmian są istotne |
| Deduplikacja | Tabela alert_sent z last_reco/last_signal | Precyzyjna — mail tylko gdy ZMIANA stanu |
| Preferencje | Global ON/OFF + per-ticker disable | Dwa poziomy kontroli bez nadkompleksowania |
| Default | OFF | GDPR-friendly, brak niechcianego spamu |
| Trigger point | bin/rescore.php po save() każdego tickera | Jeden cron, naturalny flow |
| Mail content | stary→nowy stan + CVS Swing + link /analysis/{ticker} | Konkretna info, nie klik na ciemno |

## Scope

**In scope:** migrations 011 (3 tabele), AlertRepository, AlertService (state change + mail),
rescore.php integration, dashboard toggle, per-ticker toggle na analizie, trasy AJAX.

**Out of scope:** alerty cenowe (live), kolejkowanie maili, historia alertów w UI,
push notifications.

## Architecture / Approach

```
bin/rescore.php (per ticker loop):
  save(ticker, result, price, sector)
  └─ AlertService::checkAndNotify(ticker, result)
       ├─ AlertRepository::findUsersWatchingTicker(ticker)  [JOIN watchlist + settings]
       └─ foreach user:
            ├─ getLastSent(user_id, ticker) → last_reco, last_signal
            ├─ Compare vs current reco/signal → NO CHANGE = skip
            ├─ isTickerDisabled(user_id, ticker) → skip
            ├─ MailService::send(user_email, html_mail)
            └─ upsertSent(user_id, ticker, new_reco, new_signal)
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Migracje + Repo | 3 tabele, AlertRepository (prefs + dedup) | UPSERT pattern (MySQL/SQLite compat) |
| 2. AlertService + rescore | Logika detekcji zmiany, mail, integracja | MailService w CLI rescore (config/mail.php) |
| 3. UI | Dashboard toggle + per-ticker toggle na analizie | AJAX + wyszarzenie gdy global OFF |

**Prerequisites:** F-03 ✅ (MailService), F-04 ✅ (cvs_snapshots z danymi)
**Estimated effort:** ~1-2 sesje, 3 fazy.

## Open Risks & Assumptions

- Pierwszy rescore po włączeniu alertów wyśle mail nawet bez poprzedniej zmiany
  (brak `alert_sent` = state unknown → alert). Akceptowalne jako "potwierdzenie aktualnego stanu".
- Rescore musi działać stabilnie (cron działa) — alerty zależą od cyklicznego scoringu.

## Success Criteria (Summary)

- User włącza alerty → po rescorze ze zmianą stanu dostaje mail z konkretną informacją.
- Brak zmiany stanu → brak maila (deduplikacja działa).
- Per-ticker toggle pozwala wyciszyć jedną spółkę bez wyłączania wszystkich alertów.
