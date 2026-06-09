# S-04: Alerty watchlisty — Implementation Plan

## Overview

Mail do zalogowanego usera gdy spółka z jego watchlisty zmieni rekomendację
(`reco_swing`) lub złoty sygnał (`golden_signal`). Deduplikacja przez tabelę
`alert_sent` — mail wysyłany tylko gdy stan różni się od ostatniego wysłanego.
Globalny ON/OFF per user (domyślnie OFF) + opcjonalne per-ticker wyłączenie.
Logika wyzwalana w `bin/rescore.php` po każdym `save()`.

## Current State Analysis

- `cvs_snapshots` — daily scoring z `reco_swing`, `golden_signal` (F-04) ✅
- `MailService::send()` — gotowy (F-03) ✅
- `UserRepository::findAll()` — zwraca `id`, `email` ✅
- `WatchlistRepository::findByUser()` — tickers per user ✅
- `bin/rescore.php` — pętla po tickerach, `save()` per ticker ✅
- **Brak tabel:** `user_alert_settings`, `user_alert_ticker`, `alert_sent`
- **Brak:** `AlertRepository`, `AlertService`
- Nie ma mechanizmu porównania bieżącego snapshotu z poprzednim stanem alertu

## Desired End State

- User wchodzi na dashboard → widzi toggle „Alerty: ON/OFF".
- Na `/analysis/{ticker}` — przycisk „Alerty dla tej spółki: ON/OFF" (wygaszone gdy global OFF).
- Gdy global ON + ticker nie wyciszony → mail wysyłany po rescorze gdy stan się zmienił.
- Mail zawiera: Ticker, stara reko → nowa reko, nowy CVS Swing, golden signal (jeśli), link.
- Drugie uruchomienie rescoreru tego samego dnia bez zmiany stanu → brak maila.
- PHPUnit + PHPStan zielone.

### Key Discoveries

- `bin/rescore.php` ma pętlę per ticker — po `save()` można wpiąć `AlertService::check()`.
  Przekazujemy `$ticker` + `$result->toArray()` do serwisu.
- State change detection: porównaj `reco_swing` i `golden_signal` z `alert_sent.last_reco`
  i `alert_sent.last_golden`. Jeśli różne → alert.
- `alert_sent` ma UNIQUE(user_id, ticker) → UPSERT (MySQL ON DUPLICATE KEY / SQLite fallback).
- Per-ticker disable: tabela `user_alert_ticker` z flagą `disabled`. Brak wiersza = enabled.
  Prostsze niż „per-ticker enabled" — user wyłącza, nie włącza per ticker.
- MailService musi zostać zainicjowany w `bin/rescore.php` (CLI kontekst — ładujemy `config/mail.php`).
- `global_enabled DEFAULT 0` → user musi świadomie włączyć.
- Alert jest wywoływany PER TICKER w pętli rescore, nie per user — dlatego pobieramy
  listę userów obserwujących dany ticker i iterujemy po nich.

## What We're NOT Doing

- Alertów o cenie (live data) — tylko CVS score z dziennego snapshotu.
- Kolejkowania maili (synchroniczne send w pętli, wolumen mały).
- Ustawień na poziomie cron (nie nowy cron — to samo co rescore).
- Alertów dla admin@ lub admina jako super-user.
- Historii wysłanych alertów dla usera w UI (tylko admin widzi logi).

## Implementation Approach

3 fazy: (1) migracje + AlertRepository; (2) AlertService + integracja rescore;
(3) UI (dashboard toggle + per-ticker toggle na analizie).

## Critical Implementation Details

**Inicjalizacja MailService w CLI (rescore.php):** `$_SESSION = []` już istnieje w rescore.
`MailService(null, require '.../config/mail.php')` — identyczny wzorzec jak w ProController.

**UPSERT alert_sent:** MySQL: `INSERT ... ON DUPLICATE KEY UPDATE`. SQLite: INSERT + catch UNIQUE.
Identyczny wzorzec jak w `CvsSnapshotRepository::save()` i `AiAnalysisRepository::save()`.

**Kolejność w pętli rescore:** po `save()` → `$alertSvc->checkAndNotify($ticker, $result->toArray())`.
`AlertService` wewnętrznie pobiera userów obserwujących ticker i iteruje.

---

## Phase 1: Migracje + AlertRepository

### Overview

3 nowe tabele i repozytorium do zarządzania preferencjami i historią alertów.

### Changes Required

#### 1. Migracje SQL

**File:** `database/migrations/011_create_alert_tables.sql`

**Intent:** Trzy addytywne tabele dla systemu alertów.

**Contract:**
```sql
-- Global alert preference per user (default OFF)
CREATE TABLE IF NOT EXISTS user_alert_settings (
    user_id      INT UNSIGNED NOT NULL,
    enabled      TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
);

-- Per-ticker opt-out (absence = enabled, row = disabled)
CREATE TABLE IF NOT EXISTS user_alert_ticker (
    user_id  INT UNSIGNED NOT NULL,
    ticker   VARCHAR(20)  NOT NULL,
    disabled TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, ticker)
);

-- Alert sent log + last known state for deduplication
CREATE TABLE IF NOT EXISTS alert_sent (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    ticker       VARCHAR(20)  NOT NULL,
    last_reco    VARCHAR(60)  NULL,
    last_signal  VARCHAR(20)  NULL,
    sent_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_ticker (user_id, ticker),
    INDEX idx_ticker (ticker)
);
```

#### 2. AlertRepository

**File:** `src/Alerts/AlertRepository.php` (namespace `CVS\Alerts`)

**Intent:** Jedyne miejsce zarządzające preferencjami i deduplikacją alertów.

**Contract:** Klasa z PDO injection. Metody:
- `isGlobalEnabled(int $userId): bool` — czy user ma alerty włączone.
- `setGlobalEnabled(int $userId, bool $enabled): void` — toggle globalny.
- `isTickerDisabled(int $userId, string $ticker): bool` — czy per-ticker wyłączone.
- `setTickerDisabled(int $userId, string $ticker, bool $disabled): void`.
- `getLastSent(int $userId, string $ticker): ?array` — ostatni wysłany (last_reco, last_signal).
- `upsertSent(int $userId, string $ticker, ?string $reco, ?string $signal): void` —
  zapisz/zaktualizuj po wysłaniu alertu (wzorzec ON DUPLICATE KEY).
- `findUsersWatchingTicker(string $ticker): array` — lista user_id obserwujących ticker;
  JOIN watchlist + user_alert_settings WHERE enabled=1.

#### 3. Testy AlertRepository

**File:** `tests/Alerts/AlertRepositoryTest.php`

**Contract:** Testy z SQLite: `test_default_disabled()`, `test_enable_toggle()`,
`test_ticker_disabled()`, `test_upsert_dedup()`, `test_find_users_watching()`.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (nowe testy AlertRepository)
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- Migracja 011 na CF — 3 tabele istnieją
- `SELECT * FROM user_alert_settings` — pusta (brak defaultów)

---

## Phase 2: AlertService + rescore.php integration

### Overview

Serwis detekcji zmiany stanu i wysyłki maila; integracja z pętlą rescore.

### Changes Required

#### 1. AlertService

**File:** `src/Alerts/AlertService.php` (namespace `CVS\Alerts`)

**Intent:** Jeden punkt logiki alertów — sprawdza czy stan się zmienił dla każdego
usera obserwującego ticker i wysyła mail jeśli tak.

**Contract:**
```php
public function __construct(
    AlertRepository $alertRepo,
    MailService $mail,
    UserRepository $users
)

public function checkAndNotify(string $ticker, array $cvsResult): int
// Zwraca liczbę wysłanych alertów (0 = brak zmian lub brak aktywnych userów)
```

Logika `checkAndNotify()`:
1. `$currentReco   = $cvsResult['swing']['recommendation'] ?? null`
2. `$currentSignal = $cvsResult['golden_signal'] ?? null`
3. `$userIds = $alertRepo->findUsersWatchingTicker($ticker)` — tylko z enabled=1
4. Dla każdego `$userId`:
   - Sprawdź `$alertRepo->isTickerDisabled($userId, $ticker)` → skip jeśli true
   - `$last = $alertRepo->getLastSent($userId, $ticker)`
   - Sprawdź zmianę: `$last === null` lub `$last['last_reco'] !== $currentReco`
     lub `$last['last_signal'] !== $currentSignal`
   - Jeśli zmiana: pobierz `$user = $users->findById($userId)`, wyślij mail
   - Po wysłaniu (niezależnie od bool): `$alertRepo->upsertSent($userId, $ticker, $currentReco, $currentSignal)`
   - Inkrementuj licznik

Prywatna metoda `buildHtml(string $ticker, ?string $oldReco, ?string $newReco,
?string $oldSignal, ?string $newSignal, float $cvsSwing): string` — HTML maila.

#### 2. bin/rescore.php — integracja alertów

**File:** `bin/rescore.php`

**Intent:** Po zapisaniu snapshotu wywołać `AlertService::checkAndNotify()`.

**Contract:** Inicjalizuj `AlertService` przed pętlą:
```php
$alertSvc = new AlertService(
    new AlertRepository(),
    new MailService(null, require ROOT_PATH . '/config/mail.php'),
    new UserRepository()
);
```
W pętli po `save()`:
```php
$alerted = $alertSvc->checkAndNotify($ticker, $result->toArray());
if ($alerted > 0) {
    error_log(sprintf('rescore: alert sent for %s to %d user(s)', $ticker, $alerted));
}
```

#### 3. Testy AlertService

**File:** `tests/Alerts/AlertServiceTest.php`

**Contract:** Unit testy z FakeTransport/mock MailService: `test_no_alert_when_no_change()`,
`test_alert_sent_on_reco_change()`, `test_alert_sent_on_signal_change()`,
`test_no_alert_when_ticker_disabled()`, `test_updates_last_sent_after_alert()`.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- `php -l bin/rescore.php` bez błędów

#### Manual Verification
- Rescore na CF → sprawdź `cron_rescore.txt` czy logi są OK (brak błędów PHP)
- Gdy alert wyśle: `SELECT * FROM alert_sent` — wiersz z user_id, ticker, last_reco

---

## Phase 3: UI — toggle globalny + per-ticker

### Overview

Toggle alertów globalny na dashboardzie + per-ticker na stronie analizy.
Endpoint AJAX dla obu.

### Changes Required

#### 1. AlertController

**File:** `src/Alerts/AlertController.php` (namespace `CVS\Alerts`)

**Intent:** Obsługuje POST /alerts/global i POST /alerts/ticker — AJAX toggling.

**Contract:**
- `toggleGlobal(Request $req): void` — `requireAuth()`, CSRF, odczyt current state,
  toggle `setGlobalEnabled()`, `Response::json(['ok'=>true, 'enabled'=>$newState])`.
- `toggleTicker(Request $req): void` — `requireAuth()`, CSRF, `$ticker = $req->input('ticker')`,
  toggle `setTickerDisabled()` (disable jeśli enabled, enable jeśli disabled),
  `Response::json(['ok'=>true, 'disabled'=>$newState])`.

#### 2. Trasy

**File:** `src/Core/routes.php`

**Contract:**
```php
$router->post('/alerts/global', fn($req) => $alert->toggleGlobal($req));
$router->post('/alerts/ticker', fn($req) => $alert->toggleTicker($req));
```

#### 3. Toggle globalny na dashboardzie

**File:** `templates/dashboard.php`

**Intent:** Przycisk „Alerty: ON/OFF" na dashboardzie informujący usera o stanie
i pozwalający go zmienić przez AJAX.

**Contract:** Pod lub obok watchlist section dodać:
```html
<div class="alerts-toggle-wrap">
    <span>Alerty watchlisty:</span>
    <button id="btn-alerts-global" class="btn btn--sm ..."
            data-enabled="<?= $alertsEnabled ? '1' : '0' ?>">
        <?= $alertsEnabled ? 'ON ✓' : 'OFF' ?>
    </button>
</div>
```
JS: AJAX POST `/alerts/global` z CSRF → toggle klasy + tekstu przycisku.

#### 4. Toggle per-ticker na stronie analizy

**File:** `templates/analysis.php`

**Intent:** Przycisk „Alerty: ON/OFF" w nagłówku analizy (obok watchlist + Historia CVS),
wygaszony gdy global OFF.

**Contract:** `$alertsEnabled`, `$tickerAlertDisabled` przekazane z `AnalysisController::show()`.
Przycisk szary gdy global OFF (tooltip: „Włącz alerty globalnie"). JS: AJAX POST `/alerts/ticker`.

#### 5. AnalysisController::show() — dane alertów

**File:** `src/CVS/AnalysisController.php`

**Intent:** Przekazać stan alertów per-ticker do widoku.

**Contract:** Dodać `AlertRepository` + pobierz `$alertsEnabled`, `$tickerAlertDisabled`
i przekaż do `Response::view('analysis', [...])`.

#### 6. DashboardController (przez AnalysisController::dashboard())

**File:** `src/CVS/AnalysisController.php` — metoda `dashboard()`

**Intent:** Przekazać globalny stan alertów.

**Contract:** Dodaj `$alertsEnabled = (new AlertRepository())->isGlobalEnabled($userId)`
i przekaż do widoku dashboardu.

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony
- `vendor/bin/phpstan analyse` zielony
- Trasy `/alerts/global` i `/alerts/ticker` zarejestrowane

#### Manual Verification
- Dashboard: toggle „Alerty: OFF" → klik → zmiana na „ON"; odśwież → stan zachowany
- `/analysis/AAPL`: toggle per-ticker działa (gdy global ON)
- Toggle per-ticker wyszarzony gdy global OFF
- Po włączeniu alertów + rescore (manualny): mail z alertem gdy zmiana stanu
- Ponowny rescore bez zmiany → brak drugiego maila

---

## Testing Strategy

### Unit Tests

- `AlertRepository`: default disabled, enable/disable toggle, ticker disable,
  upsert dedup (same state = update sent_at), findUsersWatching
- `AlertService` (mock MailService): no alert when no change, alert on reco change,
  alert on signal change, no alert when ticker disabled, updates last_sent

### Manual Testing Steps

1. Deploy + migracja 011 na CF
2. Włącz alerty na dashboardzie
3. Uruchom rescore manualnie przez SSH
4. Sprawdź `alert_sent` — czy wiersz bez zmiany (first-time: NULL → current = alert)
5. Uruchom rescore ponownie → `cron_rescore.txt` bez "alert sent" (brak zmiany)
6. Sprawdź inbox `admin@example.com` czy jest mail alertowy

## Performance Considerations

Pętla alertów per ticker: O(U_watching × 1 SELECT + 1 UPSERT) gdzie U ≈ 1-3.
Przy ~16 tickerach i ~3 userach = ~100 zapytań — pomijalny koszt przy dziennym cronie.
Maile synchroniczne, ale wolumen < 50/dzień.

## Migration Notes

Migracja 011 addytywna (3 CREATE TABLE). Rollback: DROP wszystkich 3 tabel.

## References

- Roadmap: `context/foundation/roadmap.md` (S-04)
- PRD: `context/foundation/prd.md` (FR-011, FR-012)
- MailService: `src/Mail/MailService.php:51`
- AlertRepository wzorzec: `src/Pro/ProRepository.php` (UPSERT pattern)
- bin/rescore.php: `bin/rescore.php:69` (pętla foreach)
- WatchlistRepository: `src/Watchlist/WatchlistRepository.php:36`
- UserRepository::findAll(): `src/Auth/UserRepository.php:39`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Migracje + AlertRepository

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony (testy AlertRepository) — cd02760
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — cd02760

#### Manual
- [x] 1.3 Migracja 011 na CF — 3 tabele istnieją

### Phase 2: AlertService + rescore integration

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony (testy AlertService) — 03642ee
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — 03642ee
- [x] 2.3 `php -l bin/rescore.php` bez błędów — 03642ee

#### Manual
- [x] 2.4 Rescore na CF bez błędów PHP w logach
- [x] 2.5 alert_sent ma wiersz po pierwszym rescorze z alertami ON

### Phase 3: UI — toggle globalny + per-ticker

#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — 7278d9c
- [x] 3.2 `vendor/bin/phpstan analyse` zielony — 7278d9c
- [x] 3.3 Trasy /alerts/global i /alerts/ticker zarejestrowane — 7278d9c

#### Manual
- [x] 3.4 Dashboard: toggle globalny działa (ON/OFF, stan zachowany po reload)
- [x] 3.5 /analysis/{ticker}: toggle per-ticker działa; wyszarzony gdy global OFF
- [x] 3.6 Mail alertowy przyszedł na inbox po rescorze ze zmianą stanu
- [x] 3.7 Ponowny rescore bez zmiany → brak drugiego maila
