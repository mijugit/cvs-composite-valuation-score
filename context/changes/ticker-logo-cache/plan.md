# Cache logo spółek (logo.dev) — Implementation Plan

## Overview

Dziś ticker spółki wyświetla się jako sam tekst (link) w screenerze, portfolio,
track-record i hover-hincie. Dodajemy logo spółki obok tickera wszędzie tam gdzie
się pojawia, pobierane RAZ przez cron/CLI z logo.dev i serwowane lokalnie z
`public/images/logos/` — żadnego hotlinku do `img.logo.dev` na żywo, żeby nie
zużywać darmowego limitu API logo.dev (500k req/mies.) przy każdym odsłonięciu
strony i nie zależeć od dostępności logo.dev w ścieżce renderowania.

## Current State Analysis

- Brak dedykowanej tabeli `tickers`/`companies` — uniwersum żyje w
  `public/data/tickers.json` (`[{symbol, name}, ...]`), zarządzane przez
  `src/Admin/TickersController.php` (`index()`/`add()`, admin-only; **brak
  metody usuwania tickera** — potwierdzone grepem, więc nie ma czego czyścić przy
  "delete tickera", bo taka ścieżka dziś nie istnieje).
- Najświeższa nazwa firmy per ticker to `cvs_snapshots.company_name` (migracja 018,
  źródło Yahoo `long_name`). Wzorzec zapytania "najnowszy snapshot per ticker"
  (`INNER JOIN (SELECT ticker, MAX(score_date) ... GROUP BY ticker)`) powtórzony
  w kilku repozytoriach, kanonicznie w `src/TrackRecord/CvsSnapshotRepository.php:333-356`.
- `FinancialDataFetcher::fetch(string $ticker): ?array` (`src/Api/FinancialDataFetcher.php:140`)
  zwraca cały znormalizowany kształt danych spółki, **w tym pole `website`**
  (linia 1055, z Yahoo `assetProfile`) — dla większości tickerów US to gotowa,
  dokładna domena bez żadnego zapytania do logo.dev. Cache tej metody jest
  sesyjny (`$_SESSION`); wywołanie z CLI poza sesją po prostu nigdy nie trafia
  w cache — nic nie trzeba omijać poza standardowym resetem `$_SESSION = []`
  (patrz `bin/rescore.php:57`). Brak throttlingu do Yahoo w tym kliencie —
  potwierdzone wcześniejszym pomiarem, że Yahoo nie rate-limituje ten endpoint.
- Wzorzec CLI/cron: `bin/rescore.php` (306 linii) — guard `PHP_SAPI!=='cli'`
  (L19-22), ręczny `.env` parser (L41-52, kopia z `public/index.php`),
  `$_SESSION = []` (L57), log `[Y-m-d H:i:s] treść` przez
  `file_put_contents($logFile, $line, FILE_APPEND|LOCK_EX)` (L31-34), błąd na
  pojedynczym tickerze nie przerywa batcha (`continue` w pętli, L174-206),
  podsumowanie z licznikami na końcu (L282-303).
- Wzorzec retry/backoff: `src/Ai/ClaudeClient.php` — `max_retries`/`total_timeout`/
  `retry_base_delay_ms` z configu, delay `(baseMs * 2**attempt)/1000` sekund z
  budżetem czasowym (L79-90), klasyfikacja retryable (429/529/5xx/timeout) vs
  nie-retryable (401/403/quota) w `interpret()` (L168-203). Seam do skopiowania:
  `src/Ai/HttpTransport.php` (interfejs `send()`, nigdy nie rzuca) +
  `src/Ai/CurlTransport.php` (goły cURL) — brak Guzzle w projekcie
  (`composer.json`: tylko `phpmailer/phpmailer` + `ext-curl`).
- Bulk-read wzorzec: `src/Screener/ScreenerRepository.php:287-317`
  (`findTickerLinksMap`) — jedno zapytanie `IN (?,?,...)`, degrade do `[]` przy
  `\PDOException` (tabela nie istnieje), wynik zmapowany `ticker => rows`,
  wstrzyknięty do wiersza po kluczu (`$row['ticker_links'] = $map[$ticker] ?? []`,
  L134-142). `src/Links/TickerLinkRepository.php` to wzorzec CRUD (96 linii).
- Komponent "ticker-hint" (JS `public/js/app.js:1327-1363`, CSS
  `public/css/components.css:220-258`) — **NIE ma wspólnego partiala PHP**:
  każdy widok ma własną kopię closure `$tickerHint`, i to z **różnymi
  sygnaturami**: `screener.php:222-255` i `track-record.php:49` przyjmują
  `($ticker, array $row)`, `portfolio.php:33+/45` przyjmuje argumenty pozycyjne.
  Render w komórce: `<span class="ticker-hint"><a href="...">TICKER</a><?= $tickerHint(...) ?></span>`.
  Punkty: `screener.php:451-457`, `portfolio.php:113-125` (holdings) i
  `:226-237` (rekomendowane), `track-record.php:143-151`,
  `track-record-ticker.php:21` (zwykły `<h1>`, brak `.ticker-hint` — osobny
  punkt integracji dla nagłówka strony pojedynczej spółki).
- CSS ładowany w `templates/layout.php:21-22`: `components.css` (komponenty UI,
  w tym `.ticker-hint*`) → `app.css` (page-specific).
- Migracje: `NNN_*.sql` w `database/migrations/`, ostatnia
  `041_add_provider_and_probability_to_ai_critical_reviews.sql` — **042 wolny**.
- `.env` na serwerze już ma zrotowane klucze: `CVS_Logo_Dev` (sekretny, Search
  API) i `CVS_Logo_Dev_Public` (publiczny, image API) — mixed-case nazwy,
  analogicznie do istniejącego wyjątku `Gemini_CVS` (`$_ENV` jest
  case-sensitive, backend dopasowuje się do serwera).

## Desired End State

Ticker spółki wszędzie w aplikacji (screener, obie tabele portfolio, track-record,
nagłówek strony pojedynczego tickera, hover-hint) pokazuje małe logo firmy obok
symbolu — pobrane z lokalnego `public/images/logos/{TICKER}.webp`, bez żadnego
live-callu do logo.dev. Ticker bez logo w bazie logo.dev pokazuje spójny
placeholder: inicjały (z `company_name`, fallback na sam ticker) na tle koloru
wyliczonego deterministycznie z tickera. Codzienny cron (`bin/fetch_logos.php`)
dogrywa logo tylko dla tickerów, które jeszcze nie mają wiersza w `ticker_logos`
— pierwsze uruchomienie backfilluje całe istniejące uniwersum, kolejne tylko
nowo dodane tickery.

**Weryfikacja**: `vendor/bin/phpunit` i `composer stan` zielone; ręczne
uruchomienie `bin/fetch_logos.php` na serwerze/lokalnie zapisuje pliki `.webp`
i wiersze `found`/`not_found` w `ticker_logos`; wszystkie 4+1 punktów integracji
renderują logo lub placeholder bez błędów w przeglądarce.

### Key Discoveries:

- `website` z Yahoo (`FinancialDataFetcher.php:1055`) eliminuje potrzebę
  wywołania Search API logo.dev dla większości tickerów — Search API to
  wyłącznie fallback, gdy Yahoo nie zna strony spółki.
- Status w `ticker_logos` ogranicza się do `found`/`not_found` (bez `pending`)
  — cały fetch per ticker jest atomowy w jednym przebiegu skryptu, nie ma
  etapu wymagającego zatwierdzenia (decyzja usera: auto-pick najlepszego
  wyniku Search API, bez admin-review).
- `$tickerHint` istnieje w 3 różnych, niezależnych kopiach o różnych
  sygnaturach — plan ich NIE ujednolica (poza zakresem), tylko dogrywa logo
  do każdej z osobna przez wspólny `TickerLogoPresenter`.

## What We're NOT Doing

- Nie budujemy UI do zatwierdzania fuzzy-matchowanych domen przez admina —
  auto-pick najlepszego wyniku Search API (decyzja usera).
- Nie dodajemy auto-retry dla `not_found` — status jest trwały do ręcznej
  interwencji w DB (decyzja usera).
- Nie triggerujemy fetchu logo synchronicznie z `TickersController::add()` —
  wyłącznie codzienny cron (decyzja usera); `TickersController` zostaje
  nietknięty.
- Nie ujednolicamy trzech niezależnych kopii `$tickerHint` w
  screener/portfolio/track-record — poza zakresem tego planu.
- Nie budujemy mechanizmu usuwania tickera ani czyszczenia `ticker_logos` przy
  usunięciu — taka ścieżka nie istnieje dziś nigdzie w aplikacji.
- Nie generujemy wielu rozmiarów obrazka — jeden plik 128px webp (retina),
  skalowany w dół przez CSS tam gdzie potrzebny mniejszy rozmiar.
- Nie mockujemy transportu HTTP w testach `LogoDevClient` (decyzja usera) —
  testowana jest wyłącznie deterministyczna logika (klasyfikacja retry,
  wybór domeny, generowanie ścieżki/inicjałów), zero realnych wywołań
  sieciowych w PHPUnit, zgodnie z regułą "testy działają w pełni offline"
  z `CLAUDE.md`.

## Implementation Approach

Trzy fazy w kolejności wymuszonej zależnościami: (1) warstwa danych — migracja,
config, klient logo.dev, repozytorium — samodzielnie testowalna bez żadnego
punktu w UI; (2) skrypt CLI/cron, który faktycznie populuje `ticker_logos` i
`public/images/logos/`, korzystając z warstwy z fazy 1; (3) rendering — placeholder,
CSS, wstrzyknięcie logo w 4+1 punktach UI, korzystające z danych które faza 2
już zapisała na dysku/w DB.

## Critical Implementation Details

**Kolejność rozwiązywania domeny** (Faza 2): zawsze najpierw `website` z
`FinancialDataFetcher::fetch()`; Search API logo.dev wołany TYLKO gdy to pole
jest puste/null. Odwrócenie tej kolejności zaprzepaszcza całą oszczędność
limitu, którą ten plan ma zapewnić.

**Sygnatury `$tickerHint`**: `screener.php`/`track-record.php` przyjmują
`($ticker, array $row)`, `portfolio.php` przyjmuje argumenty pozycyjne
`($ticker, $companyName, ...)`. `TickerLogoPresenter::render()` ma jednolitą
sygnaturę (`string $ticker, ?string $companyName, ?array $logoRow`) niezależną
od tego rozjazdu — każdy z 5 call-site'ów przekazuje jej dane wyciągnięte
zgodnie z LOKALNYM kształtem, którym już dysponuje.

## Phase 1: Warstwa danych — migracja, config, klient logo.dev, repozytorium

### Overview

Fundament: tabela, config z sekretami, klient HTTP do logo.dev z retry/backoff,
repozytorium CRUD+bulk-read. Zero wpływu na istniejące strony — czysto addytywne.

### Changes Required:

#### 1. `database/migrations/042_create_ticker_logos.sql`

**Intent**: Jeden wiersz na ticker, cache'ujący wynik resolucji logo — pozwala
skryptowi z Fazy 2 pomijać tickery już przetworzone (skip-lista), niezależnie
od wyniku (`found` czy `not_found`).

**Contract**: `CREATE TABLE IF NOT EXISTS ticker_logos` z kolumnami:
`ticker VARCHAR(20) NOT NULL PRIMARY KEY`, `domain VARCHAR(255) NULL`,
`logo_path VARCHAR(255) NULL`, `status ENUM('found','not_found') NOT NULL`,
`fetched_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`. `ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, komentarz nagłówkowy z
kontekstem/change-id, zgodnie z konwencją pozostałych migracji.

#### 2. `config/logo-dev.php`

**Intent**: Centralny config czytający `.env`, mirror kształtu `config/gemini.php`.

**Contract**: zwraca tablicę: `search_api_key` (z `$_ENV['CVS_Logo_Dev']`),
`img_api_key` (z `$_ENV['CVS_Logo_Dev_Public']`), `search_base_url` (default
`https://api.logo.dev`), `img_base_url` (default `https://img.logo.dev`),
`image_format` (`webp`), `image_size` (`128`), `retina` (`true`), `timeout`,
`max_retries`, `retry_base_delay_ms`, `total_timeout` — wartości domyślne
identyczne co do rzędu wielkości z `config/gemini.php`.

#### 3. `src/Logo/LogoDevTransport.php` + `src/Logo/CurlLogoDevTransport.php`

**Intent**: Minimalny seam HTTP GET, mirror `src/Ai/HttpTransport.php`/
`CurlTransport.php`, ale bez JSON POST body (Search API i image endpoint to
oba `GET`; odpowiedź obu — JSON tekstowy i binarne bajty obrazka — to w PHP
zwykły `string`, więc jeden interfejs pokrywa oba przypadki).

**Contract**: `LogoDevTransport::get(string $url, array $headers, int $timeout):
array{status:int, body:string, error:?string}` — nigdy nie rzuca wyjątku,
identyczny kontrakt błędu co `HttpTransport::send()` (network/timeout →
`status=0` + `error` niepusty).

#### 4. `src/Logo/LogoDevClient.php`

**Intent**: Wrapper na Search API i image-fetch logo.dev, z retry/backoff dla
błędów transientnych (429/5xx/timeout), bez retry dla realnego braku wyniku
(404 / pusta lista kandydatów w Search API).

**Contract**: `searchDomain(string $companyName): ?string` (Bearer
`search_api_key`, `GET /search?q=...&strategy=match`, zwraca domenę
najwyżej ocenionego kandydata lub `null` gdy brak wyników); `fetchImageBytes(string
$domain): ?string` (`GET img.logo.dev/{domain}?token={img_api_key}&format=webp&size=128&retina=true`,
zwraca surowe bajty lub `null` na 404). Retry/backoff i klasyfikacja
retryable-vs-not skopiowane z `ClaudeClient::interpret()` (429/529/5xx/timeout
= retryable; 401/403 = nie-retryable, log i zwrot `null`). Config i transport
wstrzykiwane przez konstruktor (ten sam DI seam co `ClaudeClient`, dla
testowalności logiki bez realnych wywołań sieciowych).

Dla testowalności bez mockowania transportu (decyzja usera): klasyfikacja
retryable-vs-not i matematyka backoffu żyją jako `public static` metody
(`LogoDevClient::isRetryableStatus(int $status): bool`,
`LogoDevClient::backoffDelayMs(int $attempt, int $baseDelayMs): int`) — czyste
funkcje, testowalne bez transportu.

#### 5. `src/Logo/TickerLogoRepository.php`

**Intent**: CRUD + bulk-read dla `ticker_logos`, mirror `TickerLinkRepository`
w stylu, ale semantyka upsert (1 wiersz na ticker) zamiast insert-many.

**Contract**: `findByTickers(array $tickers): array` → `ticker => ['logo_path'
=> ?string, 'status' => string]` (jedno zapytanie `IN (?,?,...)`, degrade do
`[]` przy `\PDOException`, mirror `ScreenerRepository::findTickerLinksMap`);
`findByTicker(string $ticker): ?array`; `existingTickers(): array<string>`
(pojedyncze `SELECT ticker FROM ticker_logos` — skip-lista dla Fazy 2);
`upsert(string $ticker, ?string $domain, ?string $logoPath, string $status):
void` (`INSERT ... ON DUPLICATE KEY UPDATE`).

#### 6. `tests/Logo/LogoDevClientTest.php`, `tests/Logo/TickerLogoRepositoryTest.php`

**Intent**: Pokrycie deterministycznej logiki bez sieci — `isRetryableStatus`/
`backoffDelayMs` na syntetycznych statusach/próbach; `TickerLogoRepository`
CRUD+bulk-read na in-memory SQLite (mirror `CVSModelTest::setUp()`).

**Contract**: zero realnych wywołań HTTP w tej klasie testowej.

### Success Criteria:

#### Automated Verification:

- Migracja aplikuje się czysto: uruchomienie `042_create_ticker_logos.sql` na
  bazie deweloperskiej/testowej
- `vendor/bin/phpunit tests/Logo/` przechodzi zielono
- Pełny `vendor/bin/phpunit` nadal zielony (zero regresji)
- `composer stan` czysty dla `src/Logo/`

#### Manual Verification:

- Ręczny smoke-test: mały skrypt/REPL wołający `LogoDevClient::searchDomain('Airbnb')`
  i `fetchImageBytes('airbnb.com')` z prawdziwym kluczem zwraca sensowną domenę
  i niepuste bajty obrazka

---

## Phase 2: Skrypt CLI/cron `bin/fetch_logos.php`

### Overview

Skrypt, który faktycznie populuje `ticker_logos` i zapisuje pliki `.webp` na
dysk — jedyne miejsce, gdzie aplikacja rozmawia z logo.dev.

### Changes Required:

#### 1. `src/TrackRecord/CvsSnapshotRepository.php`

**Intent**: Dodanie lekkiej metody zwracającej tylko `ticker => company_name`
dla najnowszego snapshotu każdego tickera — reużycie istniejącego wzorca
zapytania (L333-356) zamiast duplikowania joina `MAX(score_date)` w nowym
skrypcie CLI.

**Contract**: `latestCompanyNames(): array<string,string>`.

#### 2. `bin/fetch_logos.php`

**Intent**: CLI-only, jeden pełny przebieg na uruchomienie. Czyta
`public/data/tickers.json` + `CvsSnapshotRepository::latestCompanyNames()`;
pomija tickery już obecne w `TickerLogoRepository::existingTickers()`; dla
reszty: `FinancialDataFetcher::fetch($ticker)['website']` jako domena, a gdy
puste — `LogoDevClient::searchDomain($companyName)`; gdy domena rozwiązana,
`LogoDevClient::fetchImageBytes($domain)` → zapis do
`public/images/logos/{ticker}.webp` → `upsert(..., status: 'found')`; gdy
domena nierozwiązana (brak website ORAZ brak wyniku Search API) →
`upsert(ticker: $ticker, domain: null, logoPath: null, status: 'not_found')`
bez próby pobrania obrazka.

**Contract**: guard `PHP_SAPI !== 'cli'` (mirror `rescore.php:19-22`); ręczny
`.env` parser (mirror `rescore.php:41-52`); `$_SESSION = []` przed pierwszym
`FinancialDataFetcher::fetch()` (mirror `rescore.php:57`); log
`logs/fetch_logos.log`, format `[Y-m-d H:i:s] treść` +
`FILE_APPEND|LOCK_EX` (mirror `rescore.php:31-34`); błąd na pojedynczym
tickerze łapany i logowany, batch kontynuuje (mirror `rescore.php` `continue`
pattern, L174-206); podsumowanie na końcu z licznikami
`found`/`not_found`/`skipped`/`errors` + lista tickerów `not_found` z tego
przebiegu (mirror `rescore.php:282-303`).

### Success Criteria:

#### Automated Verification:

- `php -l bin/fetch_logos.php` — składnia czysta
- `composer stan` czysty dla `bin/fetch_logos.php` i zmienionego
  `CvsSnapshotRepository.php`

#### Manual Verification:

- Ręczne uruchomienie `php bin/fetch_logos.php` lokalnie/na serwerze na
  niepustym `tickers.json` kończy się bez fatal errora, zapisuje pliki
  `.webp` w `public/images/logos/` i wiersze w `ticker_logos` (spot-check
  kilku tickerów US → `found` z sensowną domeną, kilku `.WA`/`.KS` →
  prawdopodobnie `not_found`)
- Drugie uruchomienie zaraz po pierwszym przetwarza 0 nowych tickerów
  (potwierdza działanie skip-listy — brak zbędnych wywołań logo.dev)
- Instrukcja crona na Cyber_Folks (typ "Ścieżka", PHP 8.2/8.4 binarka jawnie
  wskazana jak w `rescore.php`, codziennie) — użytkownik zakłada go ręcznie w
  panelu CF z dokładną komendą podaną w tym planie

---

## Phase 3: Rendering — placeholder, CSS, wstrzyknięcie logo w UI

### Overview

Konsument danych zapisanych przez Fazę 2: presenter renderujący `<img>` lub
placeholder, i pięć punktów integracji (4 widoki + hover-hint).

### Changes Required:

#### 1. `src/Logo/TickerLogoPresenter.php`

**Intent**: Jedna funkcja renderująca logo-lub-placeholder, reużywana we
wszystkich 5 punktach integracji zamiast duplikowania markupu. Placeholder:
inicjały z `company_name` (pierwsze litery pierwszych 1-2 słów), fallback na
pierwsze 2 znaki tickera gdy `company_name` jest `null`; kolor tła
deterministyczny z hashu pełnego tickera (np. `substr(md5($ticker), 0, 6)`
jako hex).

**Contract**: `TickerLogoPresenter::render(string $ticker, ?string
$companyName, ?array $logoRow): string` — zwraca gotowy, już
`htmlspecialchars`-bezpieczny fragment HTML (`<img class="ticker-logo" ...>`
gdy `$logoRow['status'] === 'found'`, inaczej `<span class="ticker-logo-fallback"
style="background:#...">XY</span>`). Czysta funkcja, bez I/O.

#### 2. `public/css/components.css`

**Intent**: Style dla `.ticker-logo` (obrazek) i `.ticker-logo-fallback`
(placeholder inicjałów) — spójny mały kwadrat/kółko obok tickera, umieszczony
obok istniejącego bloku `.ticker-hint*` (~linia 220), zgodnie z podziałem
odpowiedzialności już przyjętym w repo (komponenty UI w `components.css`).

**Contract**: oba selektory dają identyczny box (np. `width/height: 20px`,
`border-radius`, `vertical-align: middle`, `margin-right`), żeby zamiana
obrazka na placeholder nie przesuwała layoutu.

#### 3. `templates/screener.php` (okolice L451-457)

**Intent**: Bulk-fetch `TickerLogoRepository::findByTickers()` w miejscu gdzie
dziś woła się `findTickerLinksMap` (ten sam wzorzec injekcji po kluczu
`ticker`); wstawienie `TickerLogoPresenter::render(...)` przed `<a>` wewnątrz
`<span class="ticker-hint">`.

**Contract**: sygnatura istniejącego `$tickerHint($ticker, array $row)` bez
zmian; logo dodane jako sibling, nie scalone z tooltipem.

#### 4. `templates/portfolio.php` (L113-125 i L226-237)

**Intent**: Jedno wywołanie `findByTickers()` pokrywające tickery z obu tabel
(holdings + rekomendowane), żeby uniknąć podwójnego zapytania na stronie;
wstrzyknięcie logo w obu miejscach.

**Contract**: lokalna sygnatura pozycyjna `$tickerHint($ticker, $companyName,
...)` bez zmian.

#### 5. `templates/track-record.php` (L143-151)

**Intent**: Analogicznie do screenera.

**Contract**: sygnatura `$tickerHint($ticker, array $row)` bez zmian.

#### 6. `templates/track-record-ticker.php` (L21)

**Intent**: Nagłówek strony pojedynczej spółki — pojedyncze `findByTicker($ticker)`
(bez bulk), logo przed tickerem w `<h1>`.

**Contract**: `<h1>` zawiera teraz `TickerLogoPresenter::render(...)` przed
istniejącym tekstem.

### Success Criteria:

#### Automated Verification:

- `composer stan` czysty dla `src/Logo/TickerLogoPresenter.php` i wszystkich
  zmienionych `templates/*.php`
- `vendor/bin/phpunit` pełny zielony (zero regresji)

#### Manual Verification:

- `/screener`, `/portfolio`, `/track-record`, `/track-record/{ticker}` w
  przeglądarce pokazują logo lub placeholder inicjałów obok każdego tickera,
  bez przesunięcia layoutu i bez błędów w konsoli
- Hover-hint (`.ticker-hint`) nadal działa poprawnie (tooltip portal,
  pozycjonowanie) po dodaniu logo
- Ticker znany jako `not_found` (np. jeden z `.WA`/`.KS` ze spot-checku Fazy 2)
  pokazuje placeholder inicjałów z poprawnym kolorem, nie zepsuty `<img>`

---

## Testing Strategy

### Unit Tests:

- `LogoDevClient::isRetryableStatus()`/`backoffDelayMs()` na zestawie
  statusów (200/404/429/500/503/529) i numerów prób
- `TickerLogoRepository` CRUD + `findByTickers()` (w tym przypadek pustej
  tabeli i przypadek `IN ()` dla pustej listy tickerów) na in-memory SQLite
- `TickerLogoPresenter::render()` — przypadek `found` (zwraca `<img>` z
  poprawnym `src`), przypadek `not_found` (placeholder z inicjałami z
  `company_name`), przypadek `company_name === null` (fallback na inicjały
  tickera), przypadek tickera z sufiksem (`005930.KS` → poprawne
  escape'owanie w atrybutach)

### Integration Tests:

- Brak (projekt nie ma warstwy integration testów dla zewnętrznych API — ani
  `FinancialDataFetcher`, ani `ClaudeClient` nie są nią objęte; ten feature
  jest z nimi spójny)

### Manual Testing Steps:

1. Uruchom `bin/fetch_logos.php` lokalnie z realnymi kluczami logo.dev na
   kilkunastu tickerach z `tickers.json` (mix US i `.WA`/`.KS`)
2. Sprawdź `logs/fetch_logos.log` i zawartość `public/images/logos/`
3. Sprawdź wiersze w `ticker_logos` przez DB client — `found` ma niepuste
   `domain`/`logo_path`, `not_found` ma oba `NULL`
4. Otwórz `/screener`, najedź na kilka tickerów — logo w tabeli i w tooltipie
5. Otwórz `/portfolio`, sprawdź obie tabele
6. Otwórz `/track-record` i wejdź w szczegóły jednego tickera — logo w
   nagłówku strony
7. Uruchom `bin/fetch_logos.php` drugi raz — potwierdź że nic nowego nie
   przetworzył (log pokazuje `skipped` = liczba tickerów z poprzedniego runu)

## Performance Considerations

Bulk-read `ticker_logos` to jedno indeksowane zapytanie po kluczu głównym per
stronę (analogicznie do `findTickerLinksMap`) — pomijalny narzut. Obrazki
serwowane jako statyczne pliki przez webserver (nie przez PHP) po pierwszym
fetchu — zero narzutu runtime poza pierwszym cronem.

## Migration Notes

Tabela jest czysto addytywna, bez backfillu w osobnym skrypcie: pierwsze
uruchomienie `bin/fetch_logos.php` na produkcji naturalnie przetworzy CAŁE
istniejące `tickers.json` (bo żaden ticker nie ma jeszcze wiersza w
`ticker_logos`), więc backfill i "normalny" codzienny przebieg to ten sam
kod bez rozgałęzień.

## References

- Wzorce do klonowania: `bin/rescore.php`, `src/Ai/ClaudeClient.php` +
  `HttpTransport.php`/`CurlTransport.php`, `src/Links/TickerLinkRepository.php`,
  `src/Screener/ScreenerRepository.php:287-317` (`findTickerLinksMap`),
  `config/gemini.php`, `bin/gen_favicon.php` (jedyny precedens zapisu pliku
  statycznego przez backend).
- Kontekst i pełny research: `context/changes/ticker-logo-cache/change.md`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Warstwa danych — migracja, config, klient logo.dev, repozytorium

#### Automated

- [x] 1.1 Migracja aplikuje się czysto: uruchomienie `042_create_ticker_logos.sql` na bazie deweloperskiej/testowej
- [x] 1.2 `vendor/bin/phpunit tests/Logo/` przechodzi zielono — 350770d
- [x] 1.3 Pełny `vendor/bin/phpunit` nadal zielony (zero regresji) — 350770d
- [x] 1.4 `composer stan` czysty dla `src/Logo/` — 350770d

#### Manual

- [x] 1.5 Ręczny smoke-test `searchDomain('Airbnb')` i `fetchImageBytes('airbnb.com')` z prawdziwym kluczem zwraca sensowną domenę i niepuste bajty obrazka (potwierdzone pośrednio: realny przebieg `bin/fetch_logos.php` na produkcji użył obu ścieżek klienta i zapisał 594 obrazki)

### Phase 2: Skrypt CLI/cron `bin/fetch_logos.php`

#### Automated

- [x] 2.1 `php -l bin/fetch_logos.php` — składnia czysta — be0b172
- [x] 2.2 `composer stan` czysty dla `bin/fetch_logos.php` i zmienionego `CvsSnapshotRepository.php` — be0b172

#### Manual

- [x] 2.3 Ręczne uruchomienie zapisuje pliki `.webp` i wiersze w `ticker_logos` (produkcja: found=594 not_found=2 (FISV, SATS) skipped=0 errors=0 total=596)
- [x] 2.4 Drugie uruchomienie przetwarza 0 nowych tickerów (produkcja: found=0 not_found=0 skipped=596 errors=0 — skip-lista działa)
- [ ] 2.5 Cron założony ręcznie na Cyber_Folks wg instrukcji z planu

### Phase 3: Rendering — placeholder, CSS, wstrzyknięcie logo w UI

#### Automated

- [x] 3.1 `composer stan` czysty dla `TickerLogoPresenter.php` i zmienionych templates — fc890a5
- [x] 3.2 Pełny `vendor/bin/phpunit` zielony (zero regresji) — fc890a5

#### Manual

- [x] 3.3 `/screener`, `/portfolio`, `/track-record`, `/track-record/{ticker}` pokazują logo/placeholder bez przesunięcia layoutu i błędów w konsoli (produkcja: 0 błędów konsoli, 0 broken img na wszystkich 4 widokach — screener 128/128, portfolio 51/51, track-record 100/100, track-record/AAPL działa)
- [x] 3.4 Hover-hint nadal działa poprawnie po dodaniu logo (produkcja: `.ticker-hint-portal--visible` + nazwa spółki potwierdzone programowo)
- [x] 3.5 Ticker `not_found` pokazuje placeholder inicjałów, nie zepsuty `<img>` (produkcja: fallbackCount>0 na każdym widoku, brokenImgCount=0 wszędzie)
