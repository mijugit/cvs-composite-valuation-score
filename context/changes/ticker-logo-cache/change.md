---
change_id: ticker-logo-cache
title: Cache logo spółek z logo.dev po stronie serwera (nie hotlink)
status: implemented
created: 2026-08-27
updated: 2026-08-27
archived_at: null
---

## Notes

Cel: pokazywać logo spółki obok tickera (hover-hint + tabele screener/portfolio/track-record)
bez hotlinkowania `img.logo.dev` na każdym renderze — obrazki pobierane raz przez cron/CLI
i serwowane lokalnie z `public/images/logos/`, żeby nie zużywać darmowego limitu logo.dev
(500k req/mies. na kluczu użytkownika) i nie zależeć od dostępności logo.dev przy każdym
odsłonięciu strony.

Klucze logo.dev zrotowane przez użytkownika (poprzednie wklejone na czacie w plaintext —
unieważnione), nowe już w `.env` na serwerze:
- `CVS_Logo_Dev` — sekretny klucz do Search API (`sk_...`)
- `CVS_Logo_Dev_Public` — publiczny klucz do `img.logo.dev` (`pk_...`)
- Mixed-case nazwy analogiczne do istniejącego wyjątku `Gemini_CVS` w configu — `$_ENV` jest
  case-sensitive, backend musi się dopasować do tego co faktycznie jest na serwerze.
- Oba klucze wyłącznie server-side — front nigdy nie woła logo.dev bezpośrednio.

Kontekst z researchu (2026-08-27):
- **Kluczowe odkrycie**: `FinancialDataFetcher::fetch()` (`src/Api/FinancialDataFetcher.php:1055`)
  już zwraca pole `website` z Yahoo `assetProfile` — dla większości tickerów US mamy więc
  DOKŁADNĄ domenę bez żadnego zapytania do logo.dev Search API. Pole to jest dziś tylko
  efemeryczne (cache w `$_SESSION`, nigdzie nie persystowane w DB). Search API (fuzzy match
  po nazwie firmy) potrzebny tylko jako fallback gdy Yahoo `website` jest puste/null —
  co dodatkowo redukuje zużycie limitu i podnosi trafność (realna domena > fuzzy match).
  Przykład ryzyka fuzzy matchu: query "reddit"/"CyberFolks" w Search Playground zwraca kilka
  kandydatów o różnych TLD (cyberfolks.pl vs cyberfolks.ro) — trzeba mieć strategię wyboru.
- Brak dedykowanej tabeli `tickers`/`companies` — uniwersum żyje w `public/data/tickers.json`
  (`[{symbol, name}, ...]`), zarządzane przez `src/Admin/TickersController.php` (admin-only,
  metody `index()`/`add()`). Najświeższa nazwa firmy per ticker: `cvs_snapshots.company_name`
  (migracja 018, źródło: Yahoo `long_name`, patrz [[cvs-long-name-quotetype-fix]]).
- Wzorzec CLI/cron: `bin/rescore.php` — guard `PHP_SAPI!=='cli'`, `ROOT_PATH` const, ręczny
  `.env` parser (kopia z `public/index.php`), log przez `file_put_contents(FILE_APPEND|LOCK_EX)`
  do `logs/*.log`, liczniki na końcu, idempotencja przez unikalny klucz. Cron na Cyber_Folks:
  typ "Ścieżka", PHP 8.2/8.4 binarka jawnie wskazana (CF domyślnie ma PHP 7.4).
  Throttling do zewn. API nie ma nigdzie poza `src/Ai/ClaudeClient.php` (retry z backoff,
  `retry_base_delay_ms`/`max_retries`/`retryable` per typ błędu) — to wzorzec do skopiowania
  dla wywołań logo.dev, NIE dla samego Yahoo fetchu (per [[cvs-model-adaptations-2026-08]]
  Yahoo nie rate-limituje).
- Jedyny precedens generowania pliku statycznego przez backend: `bin/gen_favicon.php`
  (GD, `imagecreatefrompng`, one-shot). Nie ma jeszcze katalogu `storage/`/`uploads/` —
  konwencja do ustanowienia: `public/images/logos/{TICKER}.webp`.
- `ticker_links` (migracja 033) — wzorzec CRUD do powielenia dla odczytu: bulk-read w
  `ScreenerRepository`/`TickerLinkRepository`, wstrzykiwany do wiersza tabeli jako
  `data-links="..."` (`templates/screener.php:449`) żeby uniknąć N+1. Mutacje idą przez
  osobne AJAX endpointy (`POST /screener/links/add|delete`), ale dla logo mutacji od usera
  nie ma — tylko odczyt bulk + zapis przez cron/CLI.
- `MarketResolver` (`src/Screener/MarketResolver.php`) — `final class`, czysty resolver bez
  I/O/cache, NIE wzorzec dla tego feature'u (logo wymaga persystencji, nie tylko mapowania).
- Migracje: kolejny wolny numer to `042` (`NNN_*.sql`, addytywne, `database/migrations/`).
- Config: nowy `config/logo.php` czytający `.env` (`CVS_Logo_Dev`, `CVS_Logo_Dev_Public`),
  mirror wzorca `config/ai.php`/`config/gemini.php`.
- Punkty integracji w widokach: `templates/screener.php:451`, `templates/portfolio.php:114`,
  `templates/track-record.php:146`, `templates/track-record-ticker.php`, plus wspólny
  komponent hover-hint (ticker-hint, patrz [[cvs-ticker-hover-hints]] i
  [[cvs-ticker-hint-clipping-fix]]) używany na dashboardzie/screener/track-record/portfolio.

Powiązane wcześniejsze zmiany: [[cvs-screener-ticker-links]] (wzorzec CRUD per-ticker),
[[cvs-ticker-hover-hints]] + [[cvs-ticker-hint-clipping-fix]] (komponent hover-hint),
[[cvs-long-name-quotetype-fix]] (company_name/long_name).

## Implementacja (2026-08-27)

Wszystkie 3 fazy zaimplementowane i scommitowane (350770d, be0b172, fc890a5,
epilogue b6e7ea9). Automatyczna weryfikacja (phpunit, phpstan, php -l) zielona
na każdym etapie.

## Deploy i weryfikacja na produkcji (2026-08-27)

Wdrożone na `cvs.timeflow.fun` (Cyber_Folks) tego samego dnia — `git push`
origin/main (4 commity nie były wcześniej wypchnięte), `git pull` na
serwerze, `composer dump-autoload --optimize` (pełny `composer install`
wywalał się na CF-specyficznym buncie `ext-cf:-version-hardening` niezwiązanym
z tą zmianą — dump-autoload wystarczył, bo composer.json się nie zmienił),
migracja 042 na produkcyjnej bazie.

Pierwsze uruchomienie `bin/fetch_logos.php` na produkcji (596 tickerów,
~12 min): **found=594, not_found=2 (FISV, SATS), errors=0** — 99,7%
trafności, potwierdza że priorytet `website` z Yahoo nad Search API działa
świetnie. Drugie uruchomienie: `skipped=596` — skip-lista działa. Weryfikacja
w przeglądarce (Chrome, konto usera): `/screener` (128/128 tickerów, logo
lub placeholder, 0 broken img), `/portfolio` (51/51), `/track-record`
(100/100), `/track-record/AAPL` (logo w nagłówku). Hover-hint zweryfikowany
programowo (`.ticker-hint-portal--visible` + nazwa spółki) — nie koliduje
z logo. Zero błędów w konsoli.

**Jedyna pozycja bez `[x]` w `plan.md` → `## Progress`: 2.5 (cron na
Cyber_Folks)** — wymaga konfiguracji w panelu cyber_Admin, user zakłada
ręcznie. Komenda: codziennie, PHP 8.2 binarka:
`/usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/fetch_logos.php`

## Poprawka po testach usera na telefonie (2026-08-27, commit `75339c2`)

Dwa zgłoszenia po realnym użyciu:
1. **`/analysis/{ticker}` (karta analizy) nie miała logo** — pominięta w
   oryginalnym zakresie Fazy 3 (lista punktów integracji w tym pliku wyżej
   jej nie wymieniała). Dodane: `AnalysisController::show()` (oba branche —
   błąd fetchu i pełny render) przekazuje `tickerLogo` z
   `TickerLogoRepository::findByTicker()`, `templates/analysis.php` renderuje
   `TickerLogoPresenter` w `<h1>`.
2. **Rozmiar logo miał być "wielkości dużej litery" nagłówka**, nie sztywne
   20px wszędzie — `.ticker-logo`/`.ticker-logo-fallback` przełączone na
   `width/height: 1em` (żaden z selektorów nie deklaruje własnego
   `font-size`, więc `1em` liczy się względem OTACZAJĄCEGO kontekstu — mała
   czcionka tabeli, duży `<h1>`). Inicjały placeholdera przeniesione do
   zagnieżdżonego `<span class="ticker-logo-fallback__text">` z własnym
   `font-size:.5em`, bo gdyby font-size był na tym samym elemencie co
   `width:1em`, `1em` liczyłoby się względem WŁASNEGO (zmniejszonego)
   rozmiaru, nie otaczającego — classic CSS em-cascading gotcha.

Zweryfikowane na produkcji: `/analysis/AAPL` → box 32×32px (= `1em` z
`h1{font-size:32px}`), `/screener` → box ~13.6px (= rozmiar czcionki
tabeli), 0 broken img na obu.

## Poprawka wizualna: ramka, baseline, kolejność (2026-08-28, commity `f93a214`…`1cc9271`)

Kolejne zgłoszenie usera po zerknięciu na `/analysis/SNDK`:
1. **Ramka** — `.ticker-logo`/`.ticker-logo-fallback` dostały
   `border: 1px solid var(--c-primary)` (#4090e0 — to dokładnie ta zmienna,
   spójne z `.card--result`) i `padding: .1em`, `box-sizing: content-box`
   (żeby ramka rosła NA ZEWNĄTRZ obrazka 1em, nie zjadała go).
2. **Wyrównanie do baseline** — `vertical-align: middle` → `baseline`, ale
   samo `baseline` zostawiało ~0.25em (8px przy h1 32px) luki nad tekstem
   (zmierzone `Range.getBoundingClientRect()` vs `img.getBoundingClientRect()`
   na żywej stronie) — dociągnięte `vertical-align: -.25em`. Próba "to pewnie
   inline-flex na obrazku" (zmiana na inline-block dla samego `<img>`) NIE
   zmieniła wyniku pomiaru ani o piksel — czysty magic-number fix na
   podstawie pomiaru, nie teorii.
3. **Kolejność nagłówka** — `templates/analysis.php`: "Analiza" przeniesione
   za nazwę firmy: `[logo] TICKER Nazwa Sp. - Analiza` zamiast
   `Analiza: TICKER Nazwa Sp.`.

Ramka propaguje się automatycznie na screener/portfolio/track-record (ta sama
klasa CSS), proporcjonalnie mniejsza dzięki `em`. Zweryfikowane na produkcji:
`diff` obrazek-vs-tekst = 0px na `/analysis/SNDK`, 0 broken img na
screener (128) / portfolio (51) / track-record (100).

Bezpośrednio po tym w tej samej sesji poszła osobna, niezwiązana runda
porządków UX (menu, zwijany panel filtrów, czytelniejsze wykresy, 3-way NAV
comparison) — patrz `context/changes/screener-ux-polish/change.md`.
