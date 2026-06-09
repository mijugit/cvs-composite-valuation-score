# F-01: Szablon wizualny (fresh design system) — Implementation Plan

## Overview

Zbudować świeży, spójny system wizualny dla aplikacji: **tokeny** (paleta, typografia,
odstępy, radius, shadow) → **komponenty bazowe** (karta, przyciski, tabela, chip, formularz,
badge/pigułka, alert, progress-bar) → **styleguide** (żywa galeria) → **migracja wszystkich
istniejących widoków** + **ujednolicenie markup renderowanego przez JS** → **przegląd regresji**.
Look generowany świeżo przez `/frontend-design`. Stack frontu bez zmian: vanilla PHP templates
(`Response::view` → `templates/layout.php`), plain CSS w `public/css/`, Chart.js z CDN — bez
frameworka, bez build-stepu, bez npm. UI po polsku, klasy/kod po angielsku. Fundament FR-013 pod
nowe widoki fazy 2 (S-01/S-02/S-03).

## Current State Analysis

- **Front już istnieje i jest spójny-ish**: `public/css/app.css` (454 l.) ma tokeny w `:root`
  (paleta, `--radius`, `--shadow`), dark theme, i komponenty: card, btn (tylko `--primary`),
  form, table, chip (watchlist), badge/pill (cvs-badge, golden-signal, result-card__signal),
  alert, autocomplete, spinner, responsive.
- **`templates/analysis.php` (widok referencyjny) ma ~140-liniowy inline `<style>`** (L697–835) —
  definiuje większość komponentów detalu poza tym co w `app.css`. Główny cel formalizacji.
- **Duplikaty tej samej koncepcji** (do ujednolicenia w jeden kanon):
  - „score tile": `.cvs-mode-tile*` (inline w analysis.php) ↔ `.score-badge*` (app.css, render JS).
  - „signal pill": `.golden-signal--*` (inline) ↔ `.result-card__signal--*` (app.css).
- **Złoty akcent `#eab308` zaszyty w 5+ miejscach** (analysis.php + app.css), brak tokena; radius
  miesza `99px`/`999px`.
- **Kontrakt DOM dla JS** (`public/js/app.js`) — MUSI zostać zachowany *albo* zmieniony razem z JS:
  klasy `result-card*`, `score-badge*`, `result-card__signal*`, `watchlist-toggle-btn`/`is-watched`,
  `watchlist-chip*`, `ac-wrapper/ac-dropdown/ac-item`, `alert/alert--error`, `failure-list`;
  id `#analysis-form #spinner #error-msg #results-section #results-grid #analyse-btn #tickers
  #csrf-token #radar-{ticker}`; `data-ticker/data-watchlist/data-symbol/data-watched`; oraz markup
  kart wstrzykiwany przez JS (L237–291). Pełna lista = checklista migracji w Fazie 4.
- **Widoki współdzielą chrome** z `templates/layout.php` — `EXCEPT 404.php` (standalone, własny
  `<!DOCTYPE>`, inline style). Bezpieczna współdzielona powierzchnia: `card`, `form`, `form-group`,
  `btn`, `alert`, `auth-box`, `disclaimer-inline`, chrome.
- **Brak testów CSS/template** — suite PHPUnit jest offline i nie dotyka frontu. Weryfikacja wizualna
  jest manualna.

## Desired End State

- `public/css/tokens.css` — świeża, pełna lekka skala tokenów (kolory wraz z `--c-fund`/gold,
  `--space-*`, `--text-*`, `--radius-*` w tym `--radius-pill`, `--shadow-*`).
- `public/css/components.css` — kanoniczne komponenty: card, przyciski (primary/secondary/ghost/
  danger), table, chip, form, badge/pill (jeden **score-tile** i jeden **signal-pill**), alert,
  progress-bar. Jedne nazwy, zero duplikatów.
- `templates/layout.php` ładuje `tokens.css` → `components.css` → `app.css` (w tej kolejności).
- `templates/styleguide.php` + trasa `/styleguide` — galeria wszystkich tokenów i komponentów.
- Wszystkie istniejące widoki (`analysis`, `dashboard`, `login`, `register`, `404`, chrome)
  zmigrowane na nowy system; `analysis.php` bez inline `<style>`.
- `public/js/app.js` renderuje karty na kanonicznych klasach; wszystkie hooki (id, `data-*`,
  zachowania) działają.
- `app.css` zredukowany do resztek specyficznych dla widoków (martwy/duplikowany CSS usunięty).

**Weryfikacja**: każdy widok wygląda spójnie z nowym systemem; smoke JS (analiza → karty,
watchlist toggle, autocomplete, radary, wykresy detalu) działa bez błędów konsoli; disclaimer
obecny na każdym wyniku; `vendor/bin/phpunit` i `vendor/bin/phpstan` nadal zielone (brak zmian
w logice PHP).

### Key Discoveries:

- Inline `<style>` w `analysis.php:697-835` — największy blok do przeniesienia.
- Duplikaty score-tile/signal-pill (`analysis.php` inline vs `app.css:232-269`).
- Kontrakt DOM JS: `public/js/app.js` (klasy/id/data-attrs + markup kart L237-291).
- Kolejność ładowania CSS wpinana w `templates/layout.php:8`.
- `404.php` omija layout (osobny przypadek migracji).

## What We're NOT Doing

- **Zmiana frameworka / dodanie build-stepu / npm / SCSS** — zostaje plain CSS + plain PHP.
- **Zmiana logiki PHP** (kontrolery, model CVS, dane) — to czysto warstwa prezentacji.
- **Nowe widoki funkcjonalne** (analiza AI S-01, track record S-02, screener S-03) — F-01 tylko
  daje im fundament.
- **Zmiana zachowania JS** (przepływy, AJAX, autocomplete) — tylko nazwy klas w generowanym markup,
  zachowując id/`data-*`/logikę.
- **Zmiana treści/disclaimera** — disclaimer pozostaje dosłownie ten sam, tylko stylowany spójnie.

## Implementation Approach

Warstwowo, od fundamentu do widoków, z regresją na końcu: (1) tokeny, (2) komponenty na tokenach,
(3) styleguide jako żywa weryfikacja i referencja, (4) migracja realnych widoków + ujednolicenie
markup JS (faza najwyższego ryzyka — checklista z kontraktu DOM), (5) pełny przegląd regresji.
Look (paleta/typografia/komponenty) generujemy świeżo skillem `/frontend-design` w fazach 1–2;
fazy 3–5 to zastosowanie i weryfikacja. Kolejność ładowania `tokens → components → app` pozwala
starym regułom w `app.css` współistnieć podczas migracji i znikać stopniowo.

**Decyzje po review (2026-06-01):**
- Fazy 1+2 (tokeny + komponenty) = wystarczające prerekwizyty do startu S-01; Fazy 3–5 mogą iść
  równolegle lub po S-01.
- Paleta: ciemna rewolucja — granaty (`--c-bg`/`--c-surface`) + zielenie (success/pozytywne sygnały)
  + **żółte** akcenty (`--c-fund`/`--c-primary`, nie gold). Zachowujemy dark mode, wyrzucamy amber.
- SWS-inspired UI patterns (referencja: https://simplywall.st/watchlist):
  - Watchlist: row layout z kolumnami (spółka | CVS dual-score | sygnał | rekomendacja | mini-sparkline)
  - Detail page: left sticky sidebar nawigacja + główna kolumna + prawy panel skrót
  - Score tile: duża cyfra z kolorową obwódką (zielona=KUPUJ, czerwona=UNIKAJ, żółta=NEUTRALNA)
- 404.php: przepiąć na `layout.php` (nie duplikować `<link>`-ów).
- Aliasy CSS w Fazie 2 (stare klasy JS `score-badge*`, `result-card__signal*`) oznaczone komentarzem
  `/* alias — remove in F-01 Phase 4 */` dla czytelności sprzątania.

## Critical Implementation Details

- **Kontrakt DOM jest twardy.** `app.js` odpytuje ~50 klas/id/`data-*` i wstrzykuje markup kart
  (`public/js/app.js:237-291`). W Fazie 4 każda zmiana nazwy klasy w generowanym markup MUSI iść
  w parze ze zmianą w `app.js`; id i `data-*` zostają nietknięte (sterują logiką, nie wyglądem).
  Kanwy `#radar-{ticker}` i `#detail-radar`/`#price-chart` muszą istnieć dla Chart.js.
- **Kolejność ładowania CSS.** `tokens.css` przed `components.css` przed `app.css` — inaczej
  `var(--token)` nie rozwiążą się w komponentach. Wpięcie w `templates/layout.php` (wiele `<link>`,
  bez `@import` — unikamy dodatkowych round-tripów i blokowania).
- **404.php omija layout** — migracja musi dodać do niego nowe `<link>`-i albo przepiąć na layout.
- **Brak wizualnego złamania w Fazie 1.** Tokeny mapują się na obecne wartości tam, gdzie świeży
  look jeszcze nie wszedł; pełny nowy wygląd „włącza się" gdy komponenty (F2) i widoki (F4) go użyją.

## Phase 1: Tokeny + fundament

### Overview
Świeża, pełna lekka skala tokenów + style bazowe; wpięcie kolejności ładowania. Look generowany `/frontend-design`.

### Changes Required:

#### 1. Tokeny
**File**: `public/css/tokens.css`
**Intent**: Jedno źródło prawdy dla palety, typografii, odstępów, radius i shadow — świeży system
wygenerowany `/frontend-design`, zachowujący dark-theme jako bazę.
**Contract**: `:root` z grupami: kolory (`--c-bg/surface/border/text/muted/primary/success/warn/danger`
+ **`--c-fund` (gold)**), skala odstępów (`--space-1..6`), typografii (`--text-xs..2xl`, line-height),
radius (`--radius`, **`--radius-pill`**), shadow (`--shadow`, `--shadow-lg`). Nazwy spójne z obecnymi tam, gdzie istnieją.

#### 2. Style bazowe + load order
**File**: `public/css/app.css` (reset/base zostają lub przenoszą się do tokens), `templates/layout.php`
**Intent**: Bazowe style elementów (body, nagłówki, linki) na tokenach; wpiąć `tokens.css` przed resztą.
**Contract**: `layout.php:8` — dodać `<link rel="stylesheet" href="/css/tokens.css">` (i w kroku F2
`components.css`) PRZED `app.css`. Element-level base (h1–h3, body, a) czyta tokeny.

### Success Criteria:
#### Automated Verification:
- `vendor/bin/phpunit` zielony (brak zmian PHP)
- `vendor/bin/phpstan analyse` zielony
- `tokens.css` istnieje i jest linkowany w `layout.php` (grep)
#### Manual Verification:
- Strony ładują się bez błędów; brak wizualnego złamania względem stanu sprzed (tokeny == obecne wartości tam gdzie look jeszcze nie zmieniony)

---

## Phase 2: Komponenty bazowe

### Overview
Kanoniczne komponenty na tokenach; ujednolicenie duplikatów (score-tile, signal-pill). `/frontend-design` na wygląd.

### Changes Required:

#### 1. Biblioteka komponentów
**File**: `public/css/components.css`
**Intent**: Zdefiniować jeden kanoniczny zestaw komponentów konsumujących tokeny, zastępujący
rozproszone/duplikowane reguły.
**Contract**: komponenty i ich modyfikatory: **card** (+ stany), **button** (`primary/secondary/ghost/
danger`), **table**, **chip**, **form** (group/label/input/textarea), **alert** (`error/…`),
**badge/pill** — w tym **jeden score-tile** (mode/value/reco, akcenty swing/fund przez `--c-primary`/
`--c-fund`) i **jeden signal-pill** (`strong/watchlist/momentum`), **progress-bar** (track+fill, szer.
inline data-driven). Wpiąć `components.css` w `layout.php` po `tokens.css`, przed `app.css`.

#### 2. Mapowanie kanonu na nazwy wymagane przez JS
**File**: `public/css/components.css`
**Intent**: Zapewnić, że kanoniczny wygląd obowiązuje też klasy, które JS generuje, BEZ zmiany JS w tej fazie.
**Contract**: kanoniczne reguły obejmują (lub aliasują) `score-badge*` i `result-card__signal*`, tak by
po Fazie 2 karty render-JS już wyglądały spójnie. Pełna zmiana nazw markup przychodzi w Fazie 4.

### Success Criteria:
#### Automated Verification:
- `vendor/bin/phpunit` zielony; `vendor/bin/phpstan` zielony
- `components.css` linkowany po `tokens.css` w `layout.php` (grep)
#### Manual Verification:
- Komponenty wyglądają spójnie z nowym systemem na istniejących widokach (wstępny ogląd dashboard/detal)

---

## Phase 3: Styleguide

### Overview
Żywa galeria tokenów i komponentów — referencja dla nowych widoków + powierzchnia weryfikacji.

### Changes Required:

#### 1. Widok styleguide
**File**: `templates/styleguide.php`, `src/Core/routes.php`, kontroler (istniejący lub mały nowy)
**Intent**: Pokazać wszystkie tokeny (swatche kolorów, skala typografii/odstępów) i komponenty
(warianty przycisków, card, table, chip, score-tile, signal-pill, alert, progress-bar, form).
**Contract**: trasa `GET /styleguide` (za auth wg konwencji projektu) renderująca `styleguide.php`
przez `Response::view`. Bez logiki/danych — statyczne przykłady. Disclaimer wg layoutu.

### Success Criteria:
#### Automated Verification:
- `vendor/bin/phpunit` zielony; `vendor/bin/phpstan` zielony
- Trasa `/styleguide` zarejestrowana (grep w `routes.php`)
#### Manual Verification:
- `/styleguide` renderuje wszystkie komponenty/tokeny i wygląda spójnie; służy jako wzorzec

---

## Phase 4: Migracja widoków + ujednolicenie JS

### Overview
Najwyższe ryzyko: zastosować nowy system do wszystkich widoków i przepisać markup kart w JS na kanon.

### Changes Required:

#### 1. Widok referencyjny — detal
**File**: `templates/analysis.php`
**Intent**: Zdjąć inline `<style>` (L697–835); użyć kanonicznych komponentów (score-tile, signal-pill,
table, progress-bar, badge); zachować wszystkie id kanw i `data-*`.
**Contract**: brak bloku `<style>`; klasy z `components.css`; `#detail-radar`, `#price-chart`,
`#reco-trend-chart`, `#forecast-fan-chart`, `data-ticker/data-watched` nietknięte.

#### 2. Pozostałe widoki + chrome
**File**: `templates/dashboard.php`, `login.php`, `register.php`, `404.php`, `templates/layout.php`
**Intent**: Przepiąć na kanoniczne komponenty/tokeny; `404.php` dostaje nowe `<link>`-i lub layout.
**Contract**: zachować klasy/id wymagane przez JS na dashboard (`#analysis-form #results-grid #tickers
#spinner …`, `watchlist-*`, `ac-*`); disclaimer w layoucie bez zmian treści.

#### 3. Ujednolicenie markup renderowanego przez JS
**File**: `public/js/app.js`, `public/css/components.css`
**Intent**: Przepisać generowany markup kart wyników na kanoniczne klasy (jeden score-tile/signal-pill),
usuwając rozdwojenie nazw — w parze z CSS.
**Contract**: zmiana nazw klas w stringach markup `app.js:237-291` zsynchronizowana z `components.css`;
**id, `data-*`, selektory zdarzeń i logika bez zmian**; `#radar-{ticker}` nadal tworzone.

#### 4. Sprzątanie
**File**: `public/css/app.css`
**Intent**: Usunąć martwe/duplikowane reguły przeniesione do tokens/components.
**Contract**: `app.css` zawiera tylko resztki specyficzne dla widoków; brak duplikatu gold/pill/tile.

### Success Criteria:
#### Automated Verification:
- `vendor/bin/phpunit` zielony; `vendor/bin/phpstan` zielony (brak zmian logiki PHP)
- Brak inline `<style>` w `templates/analysis.php` (grep)
#### Manual Verification:
- Każdy widok (detal, dashboard, login, register, 404, styleguide) renderuje się poprawnie na nowym systemie
- Smoke JS: analiza → karty wyników, watchlist toggle, autocomplete, mini-radary, wykresy detalu — działają bez błędów konsoli
- Disclaimer obecny na każdym wyniku; brak utraconych hooków JS

---

## Phase 5: Przegląd regresji

### Overview
Domknięcie: pełny przegląd wizualny + smoke JS wg kontraktu DOM.

### Changes Required:

#### 1. Sweep regresji (poprawki znalezione)
**File**: dowolne z `templates/`, `public/css/`, `public/js/app.js` (tylko poprawki regresji)
**Intent**: Przejść wszystkie widoki i przepływy JS wg checklisty kontraktu DOM; naprawić znalezione regresje.
**Contract**: zero zmian w logice PHP; wyłącznie korekty prezentacji/markup pod spójność i brak regresji.

### Success Criteria:
#### Automated Verification:
- `vendor/bin/phpunit` zielony; `vendor/bin/phpstan` zielony
#### Manual Verification:
- Przejście checklisty kontraktu DOM (klasy/id/`data-*`/markup) — wszystkie hooki obecne
- Wszystkie widoki spójne wizualnie; mobile (≤600px) bez złamań
- Pełny przepływ: login → dashboard → analiza wielu tickerów → karty → szczegóły → watchlist toggle → wylogowanie — bez błędów

---

## Testing Strategy

### Unit Tests:
- Brak nowych (F-01 to warstwa prezentacji; PHPUnit pozostaje zielony jako guard braku zmian PHP).

### Integration Tests:
- Brak automatycznych dla CSS/template.

### Manual Testing Steps:
1. `php -S localhost:8000 -t public`; zaloguj się.
2. `/styleguide` — potwierdź wszystkie komponenty/tokeny.
3. Dashboard: analiza 2–3 tickerów → karty wyników (badge'y, sygnały, mini-radar), watchlist toggle, autocomplete.
4. `/analysis/{ticker}` — score-tile, tabela pilarów, wykresy (radar/cena/prognozy), disclaimer.
5. login/register/404 — spójny wygląd.
6. Mobile (DevTools ≤600/≤375px) — brak złamań.
7. Konsola przeglądarki — brak błędów JS.

## Performance Considerations
Trzy pliki CSS przez `<link>` (bez `@import`) — pomijalny narzut przy małej skali; brak build-stepu.
Chart.js bez zmian. Świeży CSS nie zwiększa istotnie payloadu.

## Migration Notes
Brak zmian schematu/danych. Zmiana czysto front (CSS/templates/app.js). Deploy ręczny SSH+git jak zwykle;
po wdrożeniu twardy refresh (cache CSS). Kolejność ładowania `tokens → components → app` kluczowa.

## References
- Roadmap: `context/foundation/roadmap.md` (F-01), PRD: `context/foundation/prd.md` (FR-013)
- Widok referencyjny + inline style: `templates/analysis.php:697-835`
- Duplikaty komponentów: `public/css/app.css:232-269`
- Kontrakt DOM JS: `public/js/app.js:237-291` (+ id/`data-*` w całym pliku)
- Kolejność CSS: `templates/layout.php:8`
- Renderowanie: `src/Core/Response.php:53` (`Response::view`)
- Realizacja look: skill `/frontend-design`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Tokeny + fundament
#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony — ba67fb6
- [x] 1.2 `vendor/bin/phpstan` zielony — ba67fb6
- [x] 1.3 `tokens.css` istnieje i linkowany w `layout.php` — ba67fb6
#### Manual
- [x] 1.4 Brak wizualnego złamania względem stanu sprzed — ba67fb6

### Phase 2: Komponenty bazowe
#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony — 456beaf
- [x] 2.2 `vendor/bin/phpstan` zielony — 456beaf
- [x] 2.3 `components.css` linkowany po `tokens.css` w `layout.php` — 456beaf
#### Manual
- [x] 2.4 Komponenty spójne na istniejących widokach (wstępny ogląd) — 456beaf

### Phase 3: Styleguide
#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — 34744fe
- [x] 3.2 `vendor/bin/phpstan` zielony — 34744fe
- [x] 3.3 Trasa `/styleguide` zarejestrowana w `routes.php` — 34744fe
#### Manual
- [x] 3.4 `/styleguide` renderuje wszystkie komponenty/tokeny — 34744fe

### Phase 4: Migracja widoków + ujednolicenie JS
#### Automated
- [x] 4.1 `vendor/bin/phpunit` zielony — 43f0050
- [x] 4.2 `vendor/bin/phpstan` zielony — 43f0050
- [x] 4.3 Brak inline `<style>` w `templates/analysis.php` — 43f0050
#### Manual
- [x] 4.4 Wszystkie widoki renderują się poprawnie na nowym systemie — 43f0050
- [x] 4.5 Smoke JS (analiza, watchlist toggle, autocomplete, radary, wykresy) bez błędów — 43f0050
- [x] 4.6 Disclaimer obecny na każdym wyniku; hooki JS zachowane — 43f0050

### Phase 5: Przegląd regresji
#### Automated
- [x] 5.1 `vendor/bin/phpunit` zielony — 43f0050
- [x] 5.2 `vendor/bin/phpstan` zielony — 43f0050
#### Manual
- [x] 5.3 Checklista kontraktu DOM przejdzie (klasy/id/`data-*`/markup)
- [x] 5.4 Wszystkie widoki spójne + mobile bez złamań
- [x] 5.5 Pełny przepływ login→dashboard→analiza→detal→watchlist→logout bez błędów
