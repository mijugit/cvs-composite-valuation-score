# S-06 Watchlist + Ticker Autocomplete — Implementation Plan

## Overview

Dodanie watchlisty (max 20 tickerów/user) z togglem na kartach wynikowych i detail page,
oraz statycznego typeahead na głównej textarea dashboardu opartego na ~600 spółkach
(S&P 500 + NASDAQ 100) w pliku `public/data/tickers.json`.

## Current State Analysis

- `users` — jedyna tabela; `UserRepository` to wzorzec do powielenia
- `AuthController::requireAuth()` — static guard, wywołać na każdej chronionej akcji
- `$_SESSION['user_id']` — ID zalogowanego użytkownika dostępne po requireAuth
- `Request::verifyCsrf()` + `Response::json()` — gotowe do AJAX endpointów
- `AnalysisController::dashboard()` — przekazuje pusty array do widoku; tu dodać watchlistę
- `templates/dashboard.php` — prosty formularz; brak sekcji watchlisty
- `src/Core/routes.php` — jeden plik z routami, tu dodać nowe POST
- Nie ma żadnego wzorca autocomplete w projekcie — tworzymy od zera

## Desired End State

Po wdrożeniu S-06:
- Zalogowany user widzi sekcję "Obserwowane" z chipami tickerów nad formularzem (ukryta jeśli pusta)
- Klik chipu → ticker dopisuje się do textarea (bez kasowania istniejących; deduplication)
- Chip ma × do usunięcia z watchlisty (AJAX, bez przeładowania)
- Karta wynikowa: przycisk ⭐ gdy ticker nie jest obserwowany, × gdy jest → toggle przez AJAX
- `/analysis/{ticker}`: przycisk "Obserwuj / Usuń z obserwowanych"
- Textarea dashboardu: typeahead po wpisaniu ≥1 znaku ostatniego tokena → dropdown ze spółkami
- Endpoint `POST /watchlist/toggle` zwraca `{ok, action, ticker, count}`
- Wszystkie operacje watchlisty przechodzą przez CSRF

### Key Discoveries:

- `UserRepository` (src/Auth/UserRepository.php) — wzorzec repo do naśladowania 1:1
- `Database::connection()` (src/Core/Database.php) — PDO singleton, wywołać w konstruktorze
- `Request::verifyCsrf()` sprawdza `$_POST['_csrf']` LUB nagłówek `HTTP_X_CSRF_TOKEN`
- `app.js` wysyła token przez `X-CSRF-Token` header + body `_csrf` jednocześnie (linia ~43-45)
- `AnalysisController::dashboard()` przekazuje `[]` do widoku; wystarczy dodać `$watchlist`
- Ticker textarea ID: `#tickers` — autocomplete musi celować w ten element

## What We're NOT Doing

- Brak auto-analizy po kliknięciu chipu (user klika chip → textarea → klika Analizuj)
- Brak powiadomień emailowych gdy CVS osiągnie próg
- Brak sortowania / priorytetyzacji watchlisty
- Brak live validacji istnienia spółki przez Yahoo Finance (tylko format + dictionary)
- Brak kursów walut ani przeliczników
- Autocomplete nie obsługuje szczegółowego widoku spółki — tylko dashboard textarea

## Implementation Approach

Pionowe slices: DB → backend → dane → UI. Każda faza samodzielnie testowalna.
WatchlistController i WatchlistRepository w nowym namespace `CVS\Watchlist\`.
Autocomplete czysto client-side (statyczny JSON, fetch raz przy load, filtrowanie w pamięci).
Dashboard embeds watchlist state jako `data-watchlist` JSON attribute — JS go czyta,
nie potrzebuje dodatkowego AJAX na start.

## Critical Implementation Details

- **Toggle endpoint one-shot**: `POST /watchlist/toggle` zwraca `action: 'added'|'removed'`
  i `count: N`. Front-end aktualizuje lokalny stan w pamięci (tablicę `watchedSet`) —
  żaden dodatkowy fetch nie jest potrzebny po toggle.
- **CSRF z AJAX**: `Request::verifyCsrf()` akceptuje token z `HTTP_X_CSRF_TOKEN`
  (nagłówek) — wystarczy dodać go do fetch headers, tak samo jak w istniejącym `analyse()`.
- **Ticker format**: `strtoupper(trim($ticker))`, potem `preg_match('/^[A-Z0-9.]{1,10}$/')`.
  Backend nie sprawdza czy spółka istnieje — client-side dictionary to gwarantuje w 99%.
- **Autocomplete token detection**: textarea może mieć `"AAPL, MSF"` — autocomplete musi
  filtrować po ostatnim tokenie (`MSF`) i po wyborze zastąpić tylko ten token.

---

## Phase 1: Database Layer

### Overview

Nowa tabela `watchlist` i `WatchlistRepository` z pełnym API. Testy jednostkowe z SQLite in-memory.

### Changes Required:

#### 1. Migration SQL

**File**: `database/migrations/002_create_watchlist.sql`

**Intent**: Utwórz tabelę `watchlist` z FK do `users`, unique constraint na (user_id, ticker).

**Contract**:
```sql
CREATE TABLE IF NOT EXISTS watchlist (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    ticker     VARCHAR(20)  NOT NULL,
    added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_watchlist_user_ticker (user_id, ticker),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 2. WatchlistRepository

**File**: `src/Watchlist/WatchlistRepository.php`

**Intent**: Thin repository nad tabelą `watchlist`. Namespace `CVS\Watchlist`. Wzorzec identyczny z `UserRepository`.

**Contract**: Publiczne metody:
- `add(int $userId, string $ticker): void` — INSERT IGNORE
- `remove(int $userId, string $ticker): void` — DELETE WHERE user_id=? AND ticker=?
- `toggle(int $userId, string $ticker): string` — sprawdza isWatched; jeśli tak → remove → 'removed'; jeśli nie → add → 'added'
- `findByUser(int $userId): string[]` — SELECT ticker ORDER BY added_at ASC
- `isWatched(int $userId, string $ticker): bool`
- `countByUser(int $userId): int`

#### 3. WatchlistRepositoryTest

**File**: `tests/Watchlist/WatchlistRepositoryTest.php`

**Intent**: Testy jednostkowe repozytorium z SQLite in-memory (PDO dsn `sqlite::memory:`).
Stworzenie tabeli in-memory odtwarzającej schemat watchlist.

**Contract**: Każdy test tworzy świeże połączenie SQLite. Testowane przypadki:
`add()`, `remove()`, `toggle()` (add→removed, remove→added), `findByUser()` (kolejność), `isWatched()`, `countByUser()`, duplikat add nie rzuca wyjątku, `remove()` nieistniejącego — brak błędu.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/Watchlist/` — wszystkie testy zielone
- `vendor/bin/phpunit` — 0 regresji w istniejących testach

#### Manual Verification:

- SQL migracja wykonana na serwerze produkcyjnym bez błędów
- `DESCRIBE watchlist;` zwraca oczekiwane kolumny z FK

---

## Phase 2: Backend Endpoints

### Overview

`WatchlistController` z jednym endpointem `toggle`, wpięty w routes.php.

### Changes Required:

#### 1. WatchlistController

**File**: `src/Watchlist/WatchlistController.php`

**Intent**: Kontroler dla AJAX toggle watchlisty. Namespace `CVS\Watchlist`. Jeden public action: `toggle(Request $req)`.

**Contract**:
```
POST /watchlist/toggle
Body: ticker=AAPL&_csrf=<token>
Headers: X-CSRF-Token: <token>

Response 200: {"ok":true,"action":"added","ticker":"AAPL","count":3}
Response 422: {"ok":false,"error":"Nieprawidłowy ticker."}
Response 422: {"ok":false,"error":"Osiągnięto limit 20 obserwowanych."}
Response 403: {"ok":false,"error":"Nieprawidłowy token CSRF."}
```

Walidacja: `strtoupper(trim($ticker))`, `preg_match('/^[A-Z0-9.]{1,10}$/', $ticker)`.
Przy próbie add gdy count >= 20 → error 422.

#### 2. routes.php

**File**: `src/Core/routes.php`

**Intent**: Dodaj route `POST /watchlist/toggle` oraz import `WatchlistController`.

**Contract**: `$router->post('/watchlist/toggle', fn($req) => $watchlist->toggle($req));`
Dodaj `use CVS\Watchlist\WatchlistController;` i `$watchlist = new WatchlistController();`.

### Success Criteria:

#### Automated Verification:

- `curl -X POST .../watchlist/toggle -d "ticker=AAPL&_csrf=..." -H "X-CSRF-Token: ..."` → 200 `{ok:true}`
- Brak auth → redirect do /login (nie 200)

#### Manual Verification:

- Zalogowany user: POST /watchlist/toggle ticker=AAPL → action=added, drugi raz → action=removed
- Niezalogowany user: POST → redirect /login
- Ticker bez CSRF → 403
- Ticker 21. przy 20 na liście → 422 z komunikatem limitu

---

## Phase 3: Ticker Dictionary + Autocomplete

### Overview

Statyczny plik `public/data/tickers.json` (~600 spółek S&P 500 + NASDAQ 100) jako źródło autocomplete.
Typeahead czysto client-side: fetch raz przy load, filtrowanie w pamięci.

### Changes Required:

#### 1. Ticker dictionary

**File**: `public/data/tickers.json`

**Intent**: Statyczna lista tickerów jako tablica obiektów `{symbol, name}` dla S&P 500 + NASDAQ 100.
Plik generuje się raz ręcznie (skrypt Python lub publiczne CSV) i commituje.

**Contract**: Format:
```json
[
  {"symbol":"AAPL","name":"Apple Inc."},
  {"symbol":"MSFT","name":"Microsoft Corporation"},
  ...
]
```
Posortowane alfabetycznie po `symbol`. Docelowo ~600 wpisów.

**Jak wygenerować**: Skrypt Python pobierający listę z Wikipedia S&P 500 (`pandas` lub `requests`)
lub manualne złożenie z pliku CSV dostępnego publicznie (np. GitHub datasets/s-and-p-500-companies).

#### 2. Autocomplete w app.js

**File**: `public/js/app.js`

**Intent**: Dodaj moduł `Autocomplete` który:
1. Przy `DOMContentLoaded` pobiera `tickers.json` przez fetch (jeden raz, cache w zmiennej)
2. Nasłuchuje `input` na `#tickers` textarea
3. Wykrywa ostatni token (po ostatnim `,` lub spacji)
4. Filtruje tablicę tickerów (prefix match na `symbol` i substring na `name`, case-insensitive)
5. Renderuje dropdown `.ac-dropdown` z max 8 sugestiami
6. Klik sugestii → zastępuje ostatni token tickerem + dopisuje `, ` na końcu
7. Escape/blur → chowa dropdown

**Contract**: Dropdown: `<div class="ac-dropdown"><button class="ac-item" data-symbol="AAPL">AAPL — Apple Inc.</button>...</div>`
Pozycjonowany absolutnie względem textarea (parent `position:relative`).
Keyboard: `ArrowDown/ArrowUp` przenosi focus między `.ac-item`, `Enter` wybiera.

#### 3. CSS autocomplete

**File**: `public/css/app.css`

**Intent**: Style dla `.ac-wrapper` (position:relative na textarea), `.ac-dropdown`, `.ac-item`.

**Contract**: Dropdown z `position:absolute`, `z-index:100`, `background:var(--c-surface)`,
`border:1px solid var(--c-border)`, `border-radius:var(--radius)`.
`.ac-item:hover / :focus` → `background:var(--c-border)`.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit` — 0 regresji

#### Manual Verification:

- Wpisz `APP` w textarea → dropdown pokazuje AAPL, APPH itp.
- Wpisz `AAPL, MSF` → dropdown filtruje po `MSF` (ostatni token)
- Klik sugestii → `AAPL, MSFT` w textarea, kursor na końcu
- Escape → dropdown znika
- ArrowDown/Up/Enter działa klawiaturowo

---

## Phase 4: Dashboard Watchlist UI

### Overview

Sekcja "Obserwowane" z chipami nad formularzem, embed stanu watchlisty w HTML,
przycisk toggle ⭐/× na kartach wynikowych.

### Changes Required:

#### 1. AnalysisController::dashboard() — dostarcz watchlistę

**File**: `src/CVS/AnalysisController.php`

**Intent**: `dashboard()` pobiera watchlistę zalogowanego usera i przekazuje ją do widoku.

**Contract**: Dodaj `WatchlistRepository::findByUser($_SESSION['user_id'])` i przekaż jako `'watchlist'` do `Response::view('dashboard', [...])`.

#### 2. dashboard.php — sekcja watchlisty

**File**: `templates/dashboard.php`

**Intent**: Dodaj sekcję "Obserwowane" (`.watchlist-section`) nad formularzem analizy.
Ukryta gdy `$watchlist` jest pusta. Każdy ticker jako chip z przyciskiem ×.
Embed stanu jako `data-watchlist='[...]'` na elemencie sekcji.

**Contract**:
```html
<?php if (!empty($watchlist)): ?>
<div class="watchlist-section card" data-watchlist='<?= json_encode($watchlist) ?>'>
    <h3>Obserwowane</h3>
    <div class="watchlist-chips">
        <?php foreach ($watchlist as $t): ?>
        <span class="watchlist-chip" data-ticker="<?= htmlspecialchars($t) ?>">
            <?= htmlspecialchars($t) ?>
            <button class="watchlist-chip__remove" data-ticker="<?= htmlspecialchars($t) ?>"
                    aria-label="Usuń <?= htmlspecialchars($t) ?>">&times;</button>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
```

#### 3. app.js — chip interactions + card toggle button

**File**: `public/js/app.js`

**Intent**: Dwa nowe moduły:
1. `initWatchlistSection()` — ładuje stan z `data-watchlist`, obsługuje klik chipu (dopisuje do textarea) i klik × (AJAX toggle → remove chip z DOM)
2. Po `renderResults()`: dodaj przycisk toggle do każdej karty na podstawie `watchedSet` (Set z tickerami)

**Contract**:
- `watchedSet` — `new Set(JSON.parse(section.dataset.watchlist))` — globalny stan sesji
- Przycisk toggle na karcie: `<button class="watchlist-toggle-btn" data-ticker="...">⭐</button>` gdy nie obserwowane, `<button ...>×</button>` gdy obserwowane
- AJAX: `POST /watchlist/toggle` z `ticker` + CSRF header → po `action=removed`: usuń chip z sekcji watchlisty, zaktualizuj przycisk karty i `watchedSet`

#### 4. CSS

**File**: `public/css/app.css`

**Intent**: Style `.watchlist-section`, `.watchlist-chips`, `.watchlist-chip`, `.watchlist-chip__remove`, `.watchlist-toggle-btn`.

**Contract**: Chipy: `display:inline-flex; gap:.25rem; align-items:center; background:var(--c-surface); border:1px solid var(--c-border); border-radius:99px; padding:.2rem .6rem; font-size:.8rem`. `__remove`: małe × bez tła. `.watchlist-toggle-btn` pozycjonowany w `.result-card__header`.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit` — 0 regresji

#### Manual Verification:

- Dodaj AAPL przez analizę (Phase 2 test), odśwież dashboard → chip AAPL widoczny
- Klik chipu AAPL → `AAPL` dopisane do textarea
- Klik × chipu → AJAX remove, chip znika bez przeładowania
- Przycisk ⭐ na karcie AAPL → zmienia się w × po kliknięciu
- Drugi klik × → ⭐ wraca, ticker usunięty z watchlisty
- Nowy user (pusta watchlista) → sekcja "Obserwowane" niewidoczna

---

## Phase 5: Detail Page Integration

### Overview

Przycisk "Obserwuj / Usuń" na `/analysis/{ticker}`, stan ładowany przez isWatched().

### Changes Required:

#### 1. AnalysisController::show() — isWatched

**File**: `src/CVS/AnalysisController.php`

**Intent**: `show()` wywołuje `WatchlistRepository::isWatched($userId, $ticker)` i przekazuje `$isWatched` do widoku.

**Contract**: Wymagany import `WatchlistRepository`, wywołanie po `requireAuth()`.
Przekazać `'isWatched' => $isWatched` do `Response::view('analysis', [...])`.

#### 2. analysis.php — przycisk watchlisty

**File**: `templates/analysis.php`

**Intent**: W headerze strony wynikowej dodaj przycisk toggle watchlisty.
Stan initial oparty na `$isWatched`.

**Contract**:
```html
<button class="watchlist-detail-btn btn btn--sm"
        data-ticker="<?= htmlspecialchars($ticker) ?>"
        data-watched="<?= $isWatched ? '1' : '0' ?>">
    <?= $isWatched ? '× Usuń z obserwowanych' : '⭐ Obserwuj' ?>
</button>
```
Poniżej `<h1>Analiza: ...</h1>`, przed kartą wynikową.

#### 3. app.js lub inline script — toggle na detail page

**File**: `templates/analysis.php` (inline script) lub `public/js/app.js`

**Intent**: Skrypt nasłuchuje klik `.watchlist-detail-btn` → AJAX POST /watchlist/toggle → aktualizuje tekst i `data-watched`.

**Contract**: Logika identyczna jak w `app.js` — reużyj tej samej funkcji `watchlistToggle(ticker)` jeśli obie strony ją ładują. Jeśli detail page ma swój inline script (jak radar) — dodaj tam krótki handler.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit` — 0 regresji

#### Manual Verification:

- Przejdź na `/analysis/AAPL` gdy AAPL na watchliście → przycisk "× Usuń z obserwowanych"
- Klik → "⭐ Obserwuj", ticker zniknął z watchlisty (weryfikuj przez powrót do dashboardu)
- Przejdź na `/analysis/MSFT` gdy MSFT nie na watchliście → "⭐ Obserwuj"
- Klik → "× Usuń z obserwowanych", chip pojawia się na dashboardzie

---

## Testing Strategy

### Unit Tests:

- `WatchlistRepositoryTest` — pełne pokrycie wszystkich metod z SQLite in-memory
- Istniejące testy CVSModel pozostają zielone

### Manual Testing Steps:

1. Uruchom migrację na dev i produkcji
2. Zaloguj się, przeanalizuj AAPL → klik ⭐ → dashboard pokazuje chip
3. Klik × chipu → chip znika
4. Przejdź do `/analysis/MELI` → klik Obserwuj → wróć na dashboard → chip MELI
5. Wpisz `APP` w textarea → dropdown autocomplete
6. Limit: dodaj 20 tickerów → 21. próba → komunikat błędu

## Migration Notes

Wykonaj na produkcji przez SSH:
```bash
cd /home/amjsystem/sites/cvs.timeflow.fun && \
  mysql -u$DB_USER -p$DB_PASS $DB_NAME < database/migrations/002_create_watchlist.sql
```

## References

- `src/Auth/UserRepository.php` — wzorzec repozytorium
- `src/Core/Request.php:80-96` — verifyCsrf (akceptuje header X-CSRF-Token)
- `public/js/app.js:38-46` — wzorzec AJAX fetch z CSRF header
- `templates/dashboard.php` — template do rozszerzenia

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: DB + WatchlistRepository

#### Automated

- [x] 1.1 vendor/bin/phpunit tests/Watchlist/ — wszystkie testy zielone — e9eae87
- [x] 1.2 vendor/bin/phpunit — 0 regresji w istniejących testach — e9eae87

#### Manual

- [ ] 1.3 Migracja SQL wykonana na produkcji bez błędów

### Phase 2: WatchlistController + Routes

#### Automated

- [x] 2.1 vendor/bin/phpunit — 0 regresji — bbe22a9

#### Manual

- [ ] 2.2 POST /watchlist/toggle ticker=AAPL → action=added, drugi raz → action=removed
- [ ] 2.3 Niezalogowany POST → redirect /login
- [ ] 2.4 Ticker bez CSRF → 403

### Phase 3: Ticker Dictionary + Autocomplete

#### Automated

- [x] 3.1 vendor/bin/phpunit — 0 regresji — 2e00f73

#### Manual

- [ ] 3.2 Wpisz APP → dropdown autocomplete
- [ ] 3.3 Klik sugestii → zastępuje ostatni token w textarea
- [ ] 3.4 Escape → dropdown znika, ArrowDown/Up/Enter działa

### Phase 4: Dashboard Watchlist UI

#### Automated

- [x] 4.1 vendor/bin/phpunit — 0 regresji — e8842b7

#### Manual

- [ ] 4.2 Chip watchlisty widoczny po dodaniu tickera
- [ ] 4.3 Klik chipu → ticker w textarea
- [ ] 4.4 Klik × chipu → AJAX remove, brak przeładowania
- [ ] 4.5 Przycisk ⭐/× na kartach wynikowych toggle działa
- [ ] 4.6 Nowy user — sekcja "Obserwowane" niewidoczna

### Phase 5: Detail Page

#### Automated

- [x] 5.1 vendor/bin/phpunit — 0 regresji — 3e9cc82

#### Manual

- [ ] 5.2 /analysis/AAPL gdy obserwowane → przycisk "× Usuń z obserwowanych"
- [ ] 5.3 Klik → zmiana tekstu + usunięcie z watchlisty
- [ ] 5.4 /analysis/MSFT gdy nie obserwowane → "⭐ Obserwuj" → klik → chip na dashboardzie
