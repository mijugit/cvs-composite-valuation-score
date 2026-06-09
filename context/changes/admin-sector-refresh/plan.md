# Admin: Panel Odświeżania Sektorów — Implementation Plan

## Overview

Nowa strona `/admin/sectors` dostępna wyłącznie dla adminów. Wyświetla tabelę wszystkich sektorów z tabeli `batch_schedule` (config), pokazuje stan indeksowania ich median peer-group z tabeli `peer_medians` (data, liczba spółek, mediany EV/FCF / EV/Sales / GM%), i pozwala wymusić odświeżenie dowolnego sektora jednym kliknięciem — niezależnie od harmonogramu cron.

## Current State Analysis

- `/admin/pro` (`ProController`) istnieje, ale brak linku w nawigacji — tylko bezpośredni URL.
- `peer_medians` ma dane dla 4 sektorów (Basic Materials, Consumer Cyclical, Consumer Defensive, Industrials). Brakuje: Technology, Healthcare, Financial Services, Communication Services, Energy, Utilities, Real Estate — 7 sektorów niezaindeksowanych.
- `bin/refresh_peer_medians.php`: logika inline, guard `PHP_SAPI !== 'cli'`, czyta `batch_schedule` → `$todaysSectors` z `date('N')`. Nie przyjmuje argumentów — nie można go celować w konkretny sektor z zewnątrz.
- `PeerMedianRepository::findAllByVersion()` zwraca płaską listę wierszy — brak metody agregującej per sektor do wyświetlenia.
- `AuthController::login()` zapisuje do sesji tylko `user_id` — brak `is_admin` w sesji, więc `layout.php` nie może warunkowo pokazać linku admin.

## Desired End State

Admin otwiera `/admin/sectors` (link widoczny w nawigacji tylko dla admina). Widzi tabelę wszystkich ~11 sektorów: zaindeksowane mają daty, mediany i N spółek; niezaindeksowane mają szare wiersze z badge "Niezaindeksowany". Każdy wiersz sektora jest rozkładalny (accordion) i pokazuje podsektory (industries). Przycisk "Odśwież" przy każdym sektorze wywołuje AJAX POST → PHP uruchamia `exec()` fire-and-forget → admin widzi toast "Odświeżanie [Sektor] uruchomiono".

### Key Discoveries

- `src/Core/routes.php:69-73` — wzorzec routingu admin; dodać analogicznie GET+POST dla `/admin/sectors`.
- `src/Pro/ProController.php:223-231` — `requireAdmin()` do reużycia w nowym kontrolerze.
- `templates/pro/admin.php` — szablon admina; nowa strona idzie do `templates/admin/sectors.php`.
- `public/css/app.css:676-726` — klasy `ai-modal`, `ai-modal__inner`, `company-modal__inner` — reużywalne jako wzorzec. Nowa strona NIE używa modalu — to pełna strona jak `/admin/pro`.
- `public/js/app.js:36-53` — wzorzec AJAX POST z CSRF (`X-CSRF-Token` header + `_csrf` w body).
- `batch_schedule` w `config/cvs-weights.php:46-59` — 11 sektorów, dzień 1–5 (dni 6,7 puste). To jedyne kanoniczne źródło listy sektorów.
- Cron na CF używa `/usr/local/bin/php84` — ta sama ścieżka w `exec()`.
- `Database::reconnect()` w `bin/refresh_peer_medians.php:178` — skrypt już obsługuje długie połączenia.

## What We're NOT Doing

- Nie refaktoryzujemy logiki refresh do osobnej klasy serwisowej (zostaje inline w bin).
- Nie dodajemy polling / auto-reload po kliknięciu Odśwież — admin widzi toast i sam odświeża stronę kiedy chce.
- Nie dodajemy progressbara ani live-logu z przebiegu refresh.
- Nie zmieniamy harmonogramu cron ani batch_schedule.
- Nie dodajemy refresh per podsektor (industry) — tylko per sektor.
- Nie dodajemy osobnego wpisu PSR-4 dla `CVS\Admin\` — istniejący mapping `"CVS\\": "src/"` w composer.json automatycznie pokrywa `src/Admin/SectorsController.php`.

## Implementation Approach

Cztery fazy sekwencyjne. Faza 1 (bin script) jest prereq dla fazy 4 (exec() call w kontrolerze). Fazy 2 i 3 są niezależne i mogą iść równolegle, ale ze względu na jedno-osobowy workflow idą sekwencyjnie. Faza 4 (template) zamyka całość widokiem i JS.

## Critical Implementation Details

**exec() na CF web PHP — ZWERYFIKOWANE ✅ (2026-06-05):** SSH → `grep disable_functions /opt/alt/php84/etc/php.ini` zwróciło `disable_functions =` (puste). Brak per-user ani per-domain overrides. exec() i shell_exec() są dostępne zarówno dla CLI jak i web PHP. Krok 3.4 pozostaje jako potwierdzenie live, ale ryzyko jest już wyeliminowane.

**Sesja is_admin vs DB lookup w layout:** Layout PHP jest renderowany przy każdym requescie. DB lookup `is_admin` per request jest zbędnym obciążeniem. Rozwiązanie: zapisać `$_SESSION['is_admin']` przy logowaniu (AuthController) i odczytywać z sesji w layout — bez DB call.

**Budowanie listy sektorów w kontrolerze:** Lista wszystkich ~11 sektorów pochodzi z `array_unique(array_merge(...array_values($config['batch_schedule'])))` — wyklucza puste dni (sob/niedz). DB zwraca tylko zaindeksowane — merge w PHP po stronie kontrolera, nie SQL LEFT JOIN.

---

## Phase 1: Bin Script — Argv Sector Override

### Overview

Modyfikacja `bin/refresh_peer_medians.php` tak, by akceptował opcjonalny argument `--sector=Technology`. Gdy podany — przetwarza tylko ten sektor zamiast dzisiejszego harmonogramu. Gdy brak — zachowanie bez zmian (cron działa jak dotychczas). Backward-compatible.

### Changes Required

#### 1. Argv parsing w refresh_peer_medians.php

**File:** `bin/refresh_peer_medians.php`

**Intent:** Po sekcji bootstrap (po liniach ładowania .env/config), dodaj parsing argv który nadpisuje `$todaysSectors` gdy `--sector=X` podany. Wstaw przed blokiem "Resolve which sectors to process today" (~linia 61).

**Contract:**
```php
// argv override: --sector=Technology
$forceSector = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--sector=')) {
        $forceSector = trim(substr($arg, 9));
        break;
    }
}

$dayOfWeek     = (int) date('N');
$schedule      = $config['batch_schedule'] ?? [];
$todaysSectors = $forceSector !== null
    ? [$forceSector]
    : ($schedule[$dayOfWeek] ?? []);
```

Podmień istniejący blok (linie ~62–70) który przypisuje `$todaysSectors`.

**Walidacja sektora:** Sprawdź czy `$forceSector` istnieje w `$schedule` (spłaszczona lista). Jeśli nie — `error_log()` + `exit(1)`. Chroni przed literówkami z web UI.

### Success Criteria

#### Automated Verification

- PHPStan (`composer stan`) przechodzi bez nowych błędów
- Testy PHPUnit (`composer test`) — brak regresji (bin script nie ma własnych testów — weryfikacja manualna)

#### Manual Verification

- `php84 bin/refresh_peer_medians.php --sector=Technology` przetwarza tylko Technology (widać w logu i w `peer_medians.computed_at` po uruchomieniu)
- Uruchomienie bez `--sector` przetwarza sektory zaplanowane na dziś (jak dotychczas)
- Próba `--sector=InvalidName` wypisuje error i kończy z exit code 1

**Pause po fazie 1:** zweryfikuj ręcznie na lokalnym env lub przez SSH na CF.

---

## Phase 2: Admin Nawigacja — is_admin w Sesji

### Overview

Dwa małe zmiany: `AuthController::login()` zapisuje `is_admin` do `$_SESSION` po zalogowaniu; `templates/layout.php` warunkowo pokazuje link "Sektory" (i "Panel PRO") gdy `$_SESSION['is_admin']`.

### Changes Required

#### 1. AuthController — zapisz is_admin do sesji

**File:** `src/Auth/AuthController.php`

**Intent:** W metodzie `login()`, po pomyślnym zalogowaniu i zapisaniu `$_SESSION['user_id']`, pobierz user z DB i zapisz `$_SESSION['is_admin'] = (bool) $user['is_admin']`.

**Contract:** `$user` array z `findByEmail()` w login() już zawiera kolumnę `is_admin` (SELECT * z tabeli users) — nie trzeba dodatkowego `findById()`. Dodaj `$_SESSION['is_admin'] = (bool) $user['is_admin'];` zaraz po `$_SESSION['user_id'] = $user['id']` (linia 72). To samo w metodzie `register()` (linia 125) — tam również ustawiane jest `$_SESSION['user_id']`, więc analogicznie dodaj is_admin. Sesja jest już wystartowana (session_start() w bootstrap).

#### 2. Layout — warunkowy link admina w nawigacji

**File:** `templates/layout.php`

**Intent:** Dla zalogowanych użytkowników (`$_SESSION['user_id']`), gdy `$_SESSION['is_admin'] ?? false`, dodaj dwa linki w `<nav>`: "Panel PRO" (`/admin/pro`) i "Sektory" (`/admin/sectors`). Aktualnie brak jakiegokolwiek linku admina w nawigacji.

**Contract:** Linki admin otoczone `<?php if (!empty($_SESSION['is_admin'])): ?>` ... `<?php endif; ?>`. Stylizacja spójna z istniejącymi linkami nawigacji (ta sama klasa `<a>`).

#### 3. Wylogowanie czyści is_admin

**File:** `src/Auth/AuthController.php`

**Intent:** W metodzie `logout()`, upewnij się że `session_destroy()` lub `$_SESSION = []` czyści też `is_admin`. Sprawdź czy obecna implementacja używa `session_destroy()` — jeśli tak, nic nie trzeba robić (sesja jest niszczona w całości).

### Success Criteria

#### Automated Verification

- PHPStan przechodzi
- Testy AuthController (jeśli są) — brak regresji

#### Manual Verification

- Zaloguj jako `admin@amjsystem.eu` → w nawigacji widoczne "Panel PRO" i "Sektory"
- Zaloguj jako zwykły user → linki admin niewidoczne
- Wyloguj jako admin → linki znikają, kolejne logowanie jako admin → linki wracają

---

## Phase 3: SectorsController + Routes + Data Layer

### Overview

Nowy kontroler `src/Admin/SectorsController.php` obsługuje dwa endpointy: `GET /admin/sectors` (widok tabeli) i `POST /admin/sectors/refresh` (AJAX trigger odświeżenia). Nowa metoda w `PeerMedianRepository` agreguje dane per sektor do wyświetlenia. Rejestracja w `routes.php`.

### Changes Required

#### 1. PeerMedianRepository — metoda findSectorStats

**File:** `src/CVS/Valuation/PeerMedianRepository.php`

**Intent:** Nowa metoda `findSectorStats(string $modelVersion): array` zwracająca zagregowane dane per (level, bucket_key) potrzebne do wyświetlenia w tabeli admina.

**Contract:** SQL query na `peer_medians` z filtrem `model_version = ?`, GROUP BY `level, bucket_key`, MAX(`computed_at`), SUM(`sample_count`) / COUNT(`metric_type`), oraz pivot median per metric_type. Zwraca tablicę asocjacyjną:

```php
// Struktura wyniku:
[
  'sector' => [
    'Technology' => [
      'computed_at'  => '2026-06-02 14:00:09',  // MAX(computed_at)
      'sample_count' => 87,                       // z wiersza ev_fcf (jeden sample_count per bucket)
      'ev_fcf'       => 28.4,                     // median_value gdzie metric_type='ev_fcf'
      'ev_sales'     => 6.1,
      'gm'           => 52.3,
    ],
    // ...
  ],
  'industry' => [
    'Software—Application' => [
      'parent_sector' => 'Technology',
      'computed_at'   => '...',
      'sample_count'  => 23,
      'ev_fcf'        => 31.2,
      'ev_sales'      => 7.4,
      'gm'            => 58.1,
    ],
    // ...
  ],
]
```

Implementacja: dwa osobne selecty (jeden per `level`) lub jeden z GROUP_CONCAT — preferuj dwa proste selecty dla czytelności i compat z MySQL/SQLite.

#### 2. SectorsController

**File:** `src/Admin/SectorsController.php` (nowy plik; katalog `src/Admin/` nie istnieje — utwórz go przed zapisem pliku)

**Intent:** Kontroler z dwiema metodami: `index()` buduje dane do widoku (lista sektorów z batch_schedule + dane z DB), `refresh()` waliduje admin, przyjmuje `sector` z POST body, uruchamia exec() fire-and-forget, zwraca JSON.

**Contract:**

`index()`:
- `requireAdmin()` (skopiuj wzorzec z `ProController:223-231`, albo wyodrębnij do traitu/helpera — na razie kopiuj, DRY osobno)
- Pobierz listę wszystkich sektorów: `array_unique(array_merge(...array_values($config['batch_schedule'])))` — filtruj puste
- Pobierz `$stats = $repo->findSectorStats($config['model_version'] ?? '3.0')`
- Przekaż do widoku: `sectors` (lista z batch_schedule + dzień tygodnia), `sectorStats` (dane z DB), `industryStats`

`refresh()`:
- `requireAdmin()`
- Waliduj CSRF: `$request->verifyCsrf()`
- Pobierz `$sector = $request->input('sector')` — sanitize przez `strip_tags()` + weryfikacja że istnieje w batch_schedule (whitelist check — krytyczne dla exec() injection prevention)
- Construct command: `$cmd = '/usr/local/bin/php84 ' . ROOT_PATH . '/bin/refresh_peer_medians.php --sector=' . escapeshellarg($sector) . ' >> /home/amjsystem/cron_rescore.txt 2>&1'`
- `exec($cmd . ' &')` — fire and forget
- Zwróć `Response::json(['ok' => true, 'message' => "Odświeżanie $sector uruchomiono"])`
- Jeśli exec() wyłączone (sprawdź `function_exists('exec')`): zwróć JSON z błędem `exec_disabled`

**Kluczowy guard bezpieczeństwa:** Nigdy nie przekazuj `$sector` bezpośrednio do `exec()` bez whitelisty. Whitelist = klucze z `batch_schedule` (array_merge wszystkich sektorów). Nawet po `escapeshellarg()` — whitelist jako pierwsza linia obrony.

#### 3. Rejestracja routingu

**File:** `src/Core/routes.php`

**Intent:** Dodaj dwie trasy admin dla nowego kontrolera, analogicznie do `/admin/pro`.

**Contract:**
```php
$router->get('/admin/sectors',         fn($req) => $sectors->index($req));
$router->post('/admin/sectors/refresh', fn($req) => $sectors->refresh($req));
```
Instancja `$sectors = new SectorsController(...)` tworzona przed blokiem route definitions, jak istniejące kontrolery.

#### 4. Diagnostyczny krok — weryfikacja exec() na CF

**Intent:** Przed deployem na CF, przez SSH sprawdź czy `exec()` dostępne w web PHP:

```bash
# przez SSH
php84 -r "echo ini_get('disable_functions');"
```

Jeśli `exec` na liście `disable_functions` → plan wymaga alternatywy (np. tabela `refresh_jobs` w DB + cron co minutę). Jeśli nie ma → exec() działa i plan jest kompletny.

### Success Criteria

#### Automated Verification

- PHPStan przechodzi (nowa klasa w `src/Admin/`; `"CVS\\": "src/"` w composer.json automatycznie pokrywa `CVS\Admin\` — żaden nowy wpis PSR-4 nie jest potrzebny)
- Testy PHPUnit — brak regresji

#### Manual Verification

- SSH: `php84 -r "echo ini_get('disable_functions');"` — `exec` NIE jest na liście (prerequisite dla całej fazy)
- GET `/admin/sectors` jako admin → strona się ładuje (nawet bez pełnego stylu)
- POST `/admin/sectors/refresh` z `{sector: "Technology"}` przez curl/Postman → JSON `{ok: true}`
- W logu `/home/amjsystem/cron_rescore.txt` pojawia się nowy wpis po kilku sekundach

---

## Phase 4: Admin Sectors Template + JS

### Overview

Pełny widok `/admin/sectors`: tabela sektorów z accordion, kolumny diagnostyczne, przyciski Odśwież, toast feedback, obsługa stanu "Niezaindeksowany". Spójny stylistycznie z `templates/pro/admin.php`.

### Changes Required

#### 1. Template admin/sectors.php

**File:** `templates/admin/sectors.php` (nowy plik)

**Intent:** Tabela z listą wszystkich sektorów. Każdy wiersz sektora jest klikawy (accordion toggle) i rozwija się pokazując wiersze industry. Kolumny: Sektor, Dzień crona, Status, Aktualizacja, N spółek, EV/FCF, EV/Sales, GM%, Akcja.

**Contract:**

Dane z kontrolera:
- `$sectors` — lista sektorów z batch_schedule, każdy z polem `day_name` (Pon/Wt/..) i `day_num` (1–5)
- `$sectorStats` — keyed by sector name z DB (może nie zawierać wszystkich sektorów)
- `$industryStats` — keyed by industry name, `parent_sector` pozwala grupować

Status badge logic:
```php
$indexed = isset($sectorStats[$sectorName]);
// indexed → badge "Zaindeksowany" (signal-pill--strong)
// not indexed → badge "Niezaindeksowany" (szary, np. nowa klasa signal-pill--neutral)
```

Accordion: wiersz sektora ma `data-sector="Technology"` i `class="sector-row"`. Rozwinięte wiersze industry mają `class="industry-row industry-row--Technology"` + `hidden`. JS toggleuje `hidden`.

Wartości liczbowe: formatuj do 1 miejsca po przecinku. Null → "—".

Przycisk Odśwież: `<button class="btn btn--ghost btn--sm js-refresh-sector" data-sector="Technology">Odśwież</button>`. Po kliknięciu disabled + spinner, po odpowiedzi — toast.

#### 2. JS — accordion + refresh AJAX + toast

**File:** `public/js/app.js` (dodaj sekcję na końcu)

**Intent:** Trzy funkcje: toggle accordion per sektor, AJAX POST refresh sektora, wyświetl toast.

**Contract:**

Accordion:
```js
document.querySelectorAll('.sector-row').forEach(row => {
    row.addEventListener('click', e => {
        if (e.target.closest('.js-refresh-sector')) return; // nie toggle gdy klik w przycisk
        const sector = row.dataset.sector;
        document.querySelectorAll('.industry-row--' + sector)
            .forEach(r => r.hidden = !r.hidden);
        row.classList.toggle('sector-row--expanded');
    });
});
```

Refresh AJAX — wzorzec z `watchlistToggle()` (linia 36 app.js):
```js
document.querySelectorAll('.js-refresh-sector').forEach(btn => {
    btn.addEventListener('click', async e => {
        e.stopPropagation();
        const sector = btn.dataset.sector;
        btn.disabled = true;
        const csrf = getCsrf();
        const resp = await fetch('/admin/sectors/refresh', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf,
            },
            body: new URLSearchParams({ sector, _csrf: csrf }),
        });
        const data = await resp.json();
        showToast(data.ok
            ? `Odświeżanie ${sector} uruchomiono`
            : `Błąd: ${data.message ?? 'exec() niedostępne'}`
        );
    });
});
```

Toast `showToast(msg)`: jeśli już istnieje taka funkcja w app.js — reużyj. Jeśli brak — prosta implementacja z `div.toast` pojawiającym się na 4s (wzorzec z alert flash w layout).

#### 3. CSS — nowe klasy

**File:** `public/css/components.css`

**Intent:** Dwie nowe klasy: `signal-pill--neutral` (szary badge dla "Niezaindeksowany") i `sector-row--expanded` (wizualny indicator rozwinięcia, np. strzałka obrócona).

**Contract:** `signal-pill--neutral` analogiczna do innych `signal-pill--*` (background: var(--c-border), color: var(--c-text-muted)). `sector-row` kursor pointer + hover bg. `sector-row--expanded` może dodawać `::after` ze strzałką w górę.

### Success Criteria

#### Automated Verification

- PHPStan przechodzi
- Testy PHPUnit — brak regresji (`composer test`)
- `php -l templates/admin/sectors.php` — brak błędów składni

#### Manual Verification

- `/admin/sectors` wyświetla tabelę wszystkich ~11 sektorów
- Zaindeksowane sektory (Basic Materials, Consumer Cyclical, Consumer Defensive, Industrials) mają daty i wartości median
- Niezaindeksowane (Technology, Healthcare, itp.) mają szary badge "Niezaindeksowany" i "—" w kolumnach
- Kliknięcie w wiersz sektora rozwija podsektory (industries) — accordion działa
- Przycisk "Odśwież" → toast "Odświeżanie Technology uruchomiono" + przycisk disabled
- Po ~2-3 minutach odśwież stronę → Technology zmienia status na "Zaindeksowany" z aktualnymi danymi
- Zwykły user próbując `/admin/sectors` → redirect na /dashboard
- Link "Sektory" widoczny w nav tylko dla admina

---

## Testing Strategy

### Unit Tests

- `PeerMedianRepositoryTest` — dodaj test dla `findSectorStats()` z fixture danych w SQLite in-memory: sprawdź że pivot działa poprawnie dla wszystkich 3 metric_type
- `SectorsControllerTest` — mock `exec` nie jest możliwy w PHP; testuj `index()` z mock repo + sprawdź że `refresh()` z nieprawidłowym sektorem zwraca 400

### Integration Tests

Brak — analogicznie do innych kontrolerów admina (ProController nie ma integration tests).

### Manual Testing Steps

1. SSH: `php84 -r "echo ini_get('disable_functions');"` — exec NIE na liście
2. Zaloguj jako admin → sprawdź nav
3. Otwórz `/admin/sectors` → sprawdź tabelę
4. Kliknij accordion na zaindeksowanym sektorze → sprawdź podsektory
5. Kliknij Odśwież na niezaindeksowanym sektorze → sprawdź toast
6. Sprawdź log: `tail -f /home/amjsystem/cron_rescore.txt` przez SSH
7. Po zakończeniu refresh → sprawdź DB: `SELECT * FROM peer_medians WHERE bucket_key='Technology' LIMIT 5`
8. Odśwież `/admin/sectors` → sprawdź że sektor zmienił status

## Performance Considerations

`findSectorStats()` skanuje ~150 wierszy `peer_medians` — tabela mała, query szybkie. Brak optymalizacji potrzebnych.

## Migration Notes

Brak nowych migracji. Tabela `peer_medians` i `batch_schedule` w config już istnieją.

Jedyna zmiana infrastrukturalna: po deployments odświeżyć sesję admina (wyloguj → zaloguj) żeby `$_SESSION['is_admin']` się pojawiło.

## References

- Istniejący admin kontroler: `src/Pro/ProController.php:223-231` (requireAdmin pattern)
- Modal/AJAX wzorzec: `public/js/app.js:36-53` (watchlistToggle)
- CSS modal: `public/css/app.css:676-726`
- batch_schedule: `config/cvs-weights.php:46-59`
- PeerMedianRepository: `src/CVS/Valuation/PeerMedianRepository.php`
- bin refresh script: `bin/refresh_peer_medians.php`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Bin Script Argv Override

#### Automated

- [x] 1.1 PHPStan przechodzi bez nowych błędów — a5dd611
- [x] 1.2 PHPUnit — brak regresji — a5dd611

#### Manual

- [x] 1.3 `php84 bin/refresh_peer_medians.php --sector=Technology` przetwarza tylko Technology — a5dd611
- [x] 1.4 Bez `--sector` — przetwarza sektory zaplanowane na dziś (bez zmian) — a5dd611
- [x] 1.5 `--sector=InvalidName` → error_log + exit 1 — a5dd611

### Phase 2: Admin Nawigacja — is_admin w Sesji

#### Automated

- [x] 2.1 PHPStan przechodzi — 55c8c1a
- [x] 2.2 PHPUnit — brak regresji — 55c8c1a

#### Manual

- [x] 2.3 Admin widzi "Panel PRO" i "Sektory" w nawigacji po zalogowaniu — 55c8c1a
- [x] 2.4 Zwykły user — linki admin niewidoczne — 55c8c1a
- [x] 2.5 Wylogowanie + ponowne logowanie jako admin — linki wracają — 55c8c1a

### Phase 3: SectorsController + Routes + Data Layer

#### Automated

- [x] 3.1 PHPStan przechodzi (namespace CVS\Admin\ zarejestrowany) — b2d7952
- [x] 3.2 PHPUnit — brak regresji — b2d7952
- [x] 3.3 Nowe testy PeerMedianRepositoryTest::findSectorStats() przechodzą — b2d7952

#### Manual

- [x] 3.4 SSH: exec() NIE na liście disable_functions — b2d7952
- [x] 3.5 GET `/admin/sectors` jako admin → 200, strona się ładuje — b2d7952
- [x] 3.6 POST `/admin/sectors/refresh` z `{sector: "Technology"}` → JSON `{ok: true}` — b2d7952
- [x] 3.7 Log `/home/amjsystem/cron_rescore.txt` — nowy wpis po kilku sekundach — b2d7952

### Phase 4: Template + JS

#### Automated

- [x] 4.1 PHPStan przechodzi — 4a4207a
- [x] 4.2 PHPUnit — brak regresji (`composer test`) — 4a4207a
- [x] 4.3 `php -l templates/admin/sectors.php` — brak błędów — 4a4207a

#### Manual

- [x] 4.4 Tabela pokazuje ~11 sektorów; zaindeksowane mają daty i mediany — 4a4207a
- [x] 4.5 Niezaindeksowane — szary badge "Niezaindeksowany", "—" w kolumnach — 4a4207a
- [x] 4.6 Accordion — kliknięcie wiersza sektora rozwija podsektory — 4a4207a
- [x] 4.7 Przycisk Odśwież → toast + disabled state — 4a4207a
- [x] 4.8 Po refresh (2–3 min) → strona pokazuje zaktualizowane dane sektora — 4a4207a
- [ ] 4.9 Zwykły user → `/admin/sectors` redirect na /dashboard
