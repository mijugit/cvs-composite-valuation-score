# Cache logo spółek (logo.dev) — Plan Brief

> Full plan: `context/changes/ticker-logo-cache/plan.md`

## What & Why

Ticker spółki dziś to sam tekst — dodajemy obok niego logo firmy, wszędzie
gdzie ticker się pojawia (screener, portfolio, track-record, hover-hint).
Logo pobierane RAZ przez cron z logo.dev i zapisywane lokalnie na dysku —
żaden hotlink do `img.logo.dev` na żywo, żeby nie zużywać darmowego limitu
API przy każdym odsłonięciu strony.

## Starting Point

Dziś ticker renderuje się jako sam link (`<a href="/analysis/{ticker}">
{ticker}</a>`) w 4 widokach, każdy z własną kopią hover-hint closure
(`$tickerHint`) o różnych sygnaturach. Nie ma tabeli tickerów w DB (żyją w
`public/data/tickers.json`), nie ma dziś żadnego mechanizmu cache'owania
zasobów zewnętrznych na dysk poza jednorazowym `bin/gen_favicon.php`.

## Desired End State

Każdy ticker w aplikacji pokazuje małe logo spółki (albo spójny placeholder
z inicjałami, gdy logo.dev go nie ma) — bez żadnego live-callu do logo.dev
przy renderze strony. Codzienny cron dogrywa logo tylko dla nowo dodanych
tickerów; pierwsze uruchomienie backfilluje całe istniejące uniwersum.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Domain resolution | Yahoo `website` (już fetchowany) najpierw, Search API logo.dev tylko jako fallback | Eliminuje zdecydowaną większość wywołań Search API i podnosi trafność (realna domena > fuzzy match nazwy) | Plan (research) |
| Sync trigger przy dodaniu tickera | Tylko codzienny cron, bez synchronicznego fetchu w `TickersController::add()` | Prostszy kontroler, zero zależności od czasu odpowiedzi logo.dev w ścieżce admina | Plan |
| Fuzzy-match z kilkoma kandydatami | Auto-pick najlepszego wyniku Search API | Pełna automatyzacja; ryzyko dotyczy tylko nielicznych tickerów bez Yahoo website | Plan |
| Brak logo (`not_found`) | Placeholder: inicjały + kolor z hashu tickera | Spójny wygląd wszędzie, zero dodatkowych plików | Plan |
| Zakres UI | Wszystkie 4 widoki + hover-hint naraz, jeden plan | Spójny wygląd od razu, jeden bulk-read repo do zaprojektowania | Plan |
| Testy `LogoDevClient` | Unit testy logiki (retry/backoff, wybór domeny), bez mockowanego transportu HTTP | Zgodne z konwencją repo — testy działają w pełni offline, `FinancialDataFetcher` też nią nieobjęty | Plan |
| Retry dla `not_found` | Brak — status trwały do ręcznej interwencji w DB | Najprostsze, zero ryzyka niekontrolowanego zużywania limitu w pętli | Plan |
| Status enum | Tylko `found`/`not_found` (bez `pending`) | Fetch per ticker jest atomowy w jednym przebiegu skryptu — nie ma etapu pośredniego | Plan |

## Scope

**In scope:**
- Migracja `042_create_ticker_logos.sql`, `config/logo-dev.php`, `CVS\Logo\*`
  (klient logo.dev, repozytorium, presenter)
- `bin/fetch_logos.php` (CLI/cron)
- Logo/placeholder w screener, portfolio (2 tabele), track-record,
  track-record-ticker, hover-hint

**Out of scope:**
- Admin UI do zatwierdzania fuzzy-matchowanych domen
- Auto-retry `not_found`
- Synchroniczny fetch przy dodaniu tickera
- Ujednolicenie trzech kopii `$tickerHint`
- Usuwanie tickerów / czyszczenie `ticker_logos` (taka ścieżka nie istnieje w appce)
- Wiele rozmiarów obrazka (jeden 128px webp, skalowany CSS-em)

## Architecture / Approach

Trzy niezależnie weryfikowalne fazy: (1) warstwa danych — tabela, config,
klient HTTP z retry/backoff (mirror `ClaudeClient`), repozytorium CRUD+bulk-read
(mirror `TickerLinkRepository`/`ScreenerRepository::findTickerLinksMap`); (2)
`bin/fetch_logos.php` (mirror `bin/rescore.php`) — jedyne miejsce, gdzie
aplikacja rozmawia z logo.dev, z wbudowaną skip-listą dla już przetworzonych
tickerów; (3) `TickerLogoPresenter` (jedna funkcja renderująca logo-lub-placeholder)
wstrzyknięty w 5 punktów UI przez bulk-read per stronę.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Warstwa danych | Migracja, config, `LogoDevClient`, `TickerLogoRepository`, testy jednostkowe | Retry/backoff logo.dev źle skopiowany z ClaudeClient (błędna klasyfikacja retryable) |
| 2. Fetch script/cron | `bin/fetch_logos.php` populuje DB + `public/images/logos/` | Domain resolution w złej kolejności (Search API zamiast Yahoo website jako pierwsze) marnuje limit |
| 3. Rendering | Logo/placeholder w 4 widokach + hover-hint | Rozjazd layoutu między `<img>` a placeholderem; zepsuty hover-hint portal |

**Prerequisites:** klucze `CVS_Logo_Dev`/`CVS_Logo_Dev_Public` już w `.env` na
serwerze (zrobione przez usera); brak innych zależności.
**Estimated effort:** ~3 sesje, po jednej na fazę.

## Open Risks & Assumptions

- Zakładamy, że pole `website` z Yahoo jest wiarygodne (oficjalna domena, nie
  np. strona inwestorska poddomeny) — jeśli logo.dev nie rozpozna niektórych
  takich domen, część tickerów US i tak wyląduje jako `not_found` mimo
  posiadania `website`; do zweryfikowania podczas Fazy 2 na realnych danych.
- Limit 500k req/mies. logo.dev nie jest w praktyce zagrożony przy tej
  architekturze (cache lokalny + skip-lista), więc nie projektujemy żadnego
  monitoringu zużycia limitu w tym planie.

## Success Criteria (Summary)

- Każdy ticker w screener/portfolio/track-record/hover-hint pokazuje logo
  lub spójny placeholder, bez live-calla do logo.dev
- Cron dogrywa nowe tickery bez ręcznej interwencji, nigdy nie odpytuje
  ponownie tickerów już przetworzonych
- `vendor/bin/phpunit` i `composer stan` zielone po każdej fazie
