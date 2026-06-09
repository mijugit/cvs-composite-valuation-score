# CVS Earnings Timing Awareness (Faza 5, plaster 2) — Implementation Plan

## Overview

Dodajemy do modelu CVS **świadomość czasu wyników** — deterministyczne, liczone przy pobraniu (fetch-time)
sygnały „dni od ostatnich wyników" i „dni do następnych wyników", oraz **earnings-proximity guard**:
deterministyczną, post-agregacyjną karę cieniową (model_version 3.1, wzorzec plastra 1) tłumiącą
konwersję napędzaną momentum w ~K-sesyjnym oknie wokół daty wyników (symetrycznie przed i po, K=5 — OQ-1).
Do tego: addytywne znaczniki czasu w snapshotach (migracja 015) i badge stanu („przed/po/w tranzycie")
na detalu i screenerze.

Źródła danych: `defaultKeyStatistics.mostRecentQuarter` (już pobierane, niesparsowane) i nowy moduł Yahoo
`calendarEvents.earnings.earningsDate` (do dodania). Liczenie dni odbywa się **raz, przy pobraniu**
(`FinancialDataFetcher`) i trafia do `$financials` jako gotowe liczby całkowite — logika modelu
(`CVSModel`/guard) pozostaje czystą arytmetyką na wejściach (FR-015, determinizm: zero `date()`/`time()`
w warstwie scoringu).

## Current State Analysis

- `FinancialDataFetcher::MODULES` ([FinancialDataFetcher.php:44-54](cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php#L44-L54))
  ma 9 modułów (po plastrze 1, z `earningsTrend`), **bez `calendarEvents`**. `defaultKeyStatistics` jest
  już rozpakowywane (`$ks`, linia 346) i niesie `mostRecentQuarter` — obecnie nieużywane.
- `normalise()` ([:342-485](cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php#L342)) buduje płaską
  tablicę `$financials`; sekcja Phase-5-slice-1 (linie 361-365, 479-484) to ustalony wzorzec: czysty
  parser → pole w `$financials`. `fetch()` ([:75-108](cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php#L75))
  już woła `time()`/`new` poza `normalise()` — naturalne miejsce, by raz wyznaczyć datę odniesienia
  (fetch-time) i przekazać ją w dół jako parametr (FDF jest poza guardrailem determinizmu scoringu —
  CLAUDE.md, ale wstrzyknięcie referencyjnej daty zamiast ukrytego `new DateTimeImmutable()` w parserze
  utrzymuje testowalność offline — wzorzec `EarningsTrendParser`).
- `CVSModel::computeOverlay()` ([CVSModel.php:185-219](cvs-composite-valuation-score/src/CVS/CVSModel.php#L185)) jest
  ustalonym punktem wpięcia kar post-agregacyjnych do bloku cienia 3.1 (`OverlayPenalties::revision/targetGate`,
  config `overlays`). To **wzorzec dla guard'u** (zgodnie z decyzją: kara cieniowa, nie modyfikacja wejść
  do `MomentumPillar` ani wag agregacji — zero ryzyka regresji bazy 3.0, FR-016).
- `CVSResult` ([CVSResult.php](cvs-composite-valuation-score/src/CVS/CVSResult.php)) jest immutable
  (`readonly`, named constructors). Blok `overlay` (linie 38-49, 75, 113, 130, 163, 206) to wzorzec
  addytywnego rozszerzenia. **Badge (FR-010) musi działać niezależnie od `overlays.enabled`** — to wymaga
  osobnego, zawsze-obecnego pola (nie zagnieżdżonego w `overlay`).
- `cvs_snapshots` ma po migracji 014 `UNIQUE KEY uq_ticker_day_version (ticker, score_date, model_version)`
  ([migration 014](cvs-composite-valuation-score/database/migrations/014_widen_snapshot_unique.sql)).
  `CvsSnapshotRepository::save()` ([:47-134](cvs-composite-valuation-score/src/TrackRecord/CvsSnapshotRepository.php#L47))
  ma ustalony wzorzec INSERT/UPDATE z jawną listą kolumn — rozszerzenie addytywne (FR-019).
- **Screener czyta z persystencji, nie z żywego `CVSResult`**: `ScreenerRepository::findAllLatest()`
  ([ScreenerRepository.php:129](cvs-composite-valuation-score/src/Screener/ScreenerRepository.php#L129)) zwraca
  surowe wiersze `cvs_snapshots`; `templates/screener.php` renderuje `$row['...']` bezpośrednio (linie 150-179,
  `signal-pill` przez `$signalChip`, linie 40-47). **Badge na screenerze zależy od migracji 015 + co najmniej
  jednego przebiegu rescore** — naturalna kolejność faz (fetch→guard→persystencja→UI) to honoruje.
- `templates/analysis.php` ma wzorzec `overlay-preview-chip` (linie 235-266) — inline-stylowany blok
  warunkowy czytający `$result['overlay']`.
- `OverlayPenalties`/`OverlayPenaltiesTest` i `EarningsTrendParser`/`EarningsTrendParserTest` to ustalone
  wzorce: czyste klasy `final`, statyczne metody, testy graniczne (null, zero, nasycenie, capy) — szablon
  dla `EarningsCalendarParser` i `EarningsGuard`.

## Desired End State

`$financials` niesie deterministyczne `days_since_earnings`/`days_to_earnings` (liczone raz, przy pobraniu).
`CVSResult` niesie **dwa nowe, addytywne bloki**:
1. `earnings_timing` — zawsze obecny gdy dane dostępne (niezależnie od flag `overlays`/`earnings_guard`):
   `{days_since, days_to, state ('before'|'after'|'in_transit'|null), guard_active}`. Zasila badge.
2. Rozszerzony blok cienia `overlay` (3.1) — `penalties.earnings_guard` (kara tłumiąca, ujemna, capowana)
   dolicza się do `penalties.total` i shadow swing/fund, **baza 3.0 nietknięta** (FR-016).

Migracja 015 dokłada 4 addytywne kolumny do `cvs_snapshots`; rescore zapisuje je przy każdym przebiegu.
Detal i screener pokazują jeden chip stanu („📅 Wyniki za 3 dni" / „W oknie wyników" / „Wyniki 2 dni temu").

### Key Discoveries:
- Punkt wpięcia kary guard: [CVSModel.php:144](cvs-composite-valuation-score/src/CVS/CVSModel.php#L144)
  (wewnątrz/obok `computeOverlay`), analogicznie do `OverlayPenalties`.
- `mostRecentQuarter` już w `$ks` ([:346](cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php#L346)),
  zero kosztu dodatkowego — tylko `calendarEvents` to nowy moduł (jedno wywołanie quoteSummary, FR-018/NFR).
- Badge musi być NIEZALEŻNY od `overlay` (gating na `overlays.enabled`/`earnings_guard.enabled` zepsułby
  FR-010 dla użytkowników bez aktywnego shadow-mode) → osobny top-level blok `earnings_timing`.
- Screener czyta z DB, nie z `CVSResult` na żywo → kolejność faz (persystencja przed UI screenera) jest wymuszona.

## What We're NOT Doing

- **Brak normalizacji FCF** (plaster 3) i **brak rekalibracji progów/skali** (plaster 4, zablokowany na
  danych z crona) — osobne, późniejsze plastry.
- **Brak modyfikacji `MomentumPillar`/wag agregacji** — guard to kara post-agregacyjna w cieniu (3.1),
  zgodnie z decyzją; baza 3.0 i sigmoid momentum pozostają nietknięte.
- **Brak aktywacji 3.1 na produkcji** — nadal tryb cieniowy; headline reco zostaje 3.0 (FR-016, jak w plastrze 1).
- **Brak realnej rekalibracji wagi „starych" danych z FR-006** — w tym plastrze to wyłącznie sygnał/flaga
  pokrycia w `earnings_timing` (świadoma decyzja — realna recalibracja dopiero z danymi z crona, plaster 4).
- **Brak osobnych okien przed/po** — K=5 sesji symetrycznie (decyzja, OQ-1); ewentualna asymetria to
  materiał na rekalibrację.
- **Brak specjalnego stanu UI „brak danych o wynikach"** — przy braku `calendarEvents`/`mostRecentQuarter`
  badge po prostu się nie renderuje (cicho `null` + flaga pokrycia w `coverage`, wzorzec `eps_revision_pct`).

## Implementation Approach

Czterofazowa kolejność odzwierciedla zależności danych: **(1) fetch & parse** → nowy moduł Yahoo +
czysty parser licząc dni względem wstrzykniętej daty referencyjnej (fetch-time) → `$financials`;
**(2) guard logic** → nowa klasa `EarningsGuard` (wzorzec `OverlayPenalties`: czyste statyczne funkcje,
config `earnings_guard` ze slope/cap), nowy zawsze-obecny blok `earnings_timing` w `CVSResult` (osobno od
`overlay`, by badge działał niezależnie od shadow-mode), kara guard dolicza się do istniejącego bloku
cienia 3.1; **(3) persystencja** → migracja 015 (4 kolumny addytywne) + `CvsSnapshotRepository::save()`;
**(4) UI** → jeden chip stanu, wzorzec `signal-pill` (screener) / inline-blok (detal), czytający
odpowiednio z DB-wiersza (screener) i z żywego `CVSResult` (detal).

## Critical Implementation Details

- **Determinizm: data odniesienia wstrzykiwana, nie liczona w locie.** `fetch()` wyznacza
  `$referenceDate = new DateTimeImmutable()` RAZ (obok istniejących `time()`), przekazuje w dół do
  `normalise(..., $referenceDate)` → `EarningsCalendarParser::parse($raw, $referenceDate)`. Parser jest
  czystą funkcją dat (testowalny offline z dowolną datą referencyjną) — `CVSModel`/`EarningsGuard` widzą
  już gotowe `int|null` dni, zero `date()`/`time()` w scoringu (FR-015, Socrates rationale z shape-notes).
- **`earningsDate` bywa przedziałem dat.** Yahoo `calendarEvents.earnings.earningsDate` to czasem tablica
  2 wpisów (widełki raportowania). Decyzja: bierzemy **pierwszą (najwcześniejszą)** datę — konserwatywnie,
  guard aktywuje się nie później niż trzeba. Parser musi obsłużyć zarówno pojedynczy obiekt `{"raw":...}`,
  jak i tablicę obiektów.
- **`days_to_earnings` może wyjść ujemne** (kalendarz pokazuje datę, która już minęła, a `mostRecentQuarter`
  jeszcze się nie zaktualizował — typowy data-lag Yahoo). To NIE błąd parsera — to sygnał stanu
  `'in_transit'` w `EarningsGuard::state()`. Nie clampować do zera w parserze; logika stanu/guard'u
  interpretuje znak.
- **Badge ≠ guard — rozdzielone bloki w `CVSResult`.** `earnings_timing` (badge, zawsze gdy dane są)
  i `overlay.penalties.earnings_guard` (kara cieniowa, gating `earnings_guard.enabled`) to dwa osobne,
  addytywne pola. Mieszanie ich (badge wewnątrz `overlay`) złamałoby FR-010 dla użytkowników bez
  aktywnego shadow-mode.
- **Kara guard — proximity-based, wzorzec `OverlayPenalties`.** `proximity = (K − min(days_to, days_since
  w oknie)) / K ∈ [0,1]`; `penalty = round(max(-cap, -slope * proximity), 1)` — czysta arytmetyka,
  config-driven (`earnings_guard.penalty.{slope,cap}`, FR-010, zero hardkodu). Dolicza się do
  `penalties.total` obok istniejących `revision`/`target` (suma capowana łącznie jak dotąd).
- **Kolejność faz wymuszona przez architekturę screenera.** Badge na screenerze czyta `$row['earnings_state']`
  z DB (nie z żywego `CVSResult`) — wymaga migracji 015 + co najmniej jednego przebiegu `bin/rescore.php`
  zanim będzie cokolwiek do pokazania. Faza 4 (UI) musi iść po Fazie 3 (persystencja) — manualna
  weryfikacja to uwzględnia.

## Phase 1: Fetch & parse — wejścia czasu wyników do `$financials`

### Overview
Dodaj moduł `calendarEvents`, zbuduj czysty `EarningsCalendarParser` liczący `days_since_earnings`/
`days_to_earnings` względem wstrzykniętej daty referencyjnej, doprowadź pola do `$financials`.

### Changes Required:

#### 1. Nowy moduł Yahoo
**File**: `src/Api/FinancialDataFetcher.php`
**Intent**: Dociągnąć kalendarz wyników w tym samym wywołaniu quoteSummary (bez nowego round-tripu — NFR/FR-018).
**Contract**: `MODULES` (linie 44-54) rośnie o `'calendarEvents'`.

#### 2. Data odniesienia wstrzykiwana z fetch-time
**File**: `src/Api/FinancialDataFetcher.php`
**Intent**: Wyznaczyć moment pobrania RAZ i przekazać w dół — zachowuje testowalność i determinizm warstwy parsowania.
**Contract**: `fetch()` (linie 75-108) tworzy `$referenceDate = new DateTimeImmutable()`; sygnatura
`normalise()` (linia 342) rośnie o `DateTimeImmutable $referenceDate`; wywołanie na linii 97 przekazuje wartość dalej.

#### 3. Czysty parser kalendarza wyników
**File**: `src/Forecast/EarningsCalendarParser.php` (nowy, `CVS\Forecast\`, mirror `EarningsTrendParser`)
**Intent**: Wyliczyć dni od/do wyników z `defaultKeyStatistics.mostRecentQuarter` i
`calendarEvents.earnings.earningsDate` (pierwsza data z ew. przedziału), w pełni testowalnie offline.
**Contract**:
```php
final class EarningsCalendarParser
{
    /** @return array{days_since_earnings: ?int, days_to_earnings: ?int} */
    public static function parse(array $raw, DateTimeImmutable $referenceDate): array;
}
```
`days_since_earnings = floor((referenceDate − mostRecentQuarter) / 86400)`, nieujemne (przeszła data) lub `null`
gdy brak `mostRecentQuarter.raw`. `days_to_earnings = ceil((earningsDate[0] − referenceDate) / 86400)`,
**może być ujemne** (patrz Critical Details — sygnał `in_transit`) lub `null` gdy brak `calendarEvents.earnings.earningsDate`.
Helper `raw()` 1:1 z `EarningsTrendParser::raw()` (unwrap `{"raw": x, "fmt": "y"}`).

#### 4. Wpięcie do `$financials`
**File**: `src/Api/FinancialDataFetcher.php`
**Intent**: Doprowadzić gotowe liczby całkowite do modelu — wzorzec sekcji „Phase 5 (slice 1)" (linie 479-484).
**Contract**: w `normalise()` wywołanie `$earningsTiming = EarningsCalendarParser::parse($raw, $referenceDate);`
i nowe klucze `'days_since_earnings' => $earningsTiming['days_since_earnings']`,
`'days_to_earnings' => $earningsTiming['days_to_earnings']` w zwracanej tablicy (sekcja komentowana „Phase 5 (slice 2)").

### Success Criteria:
#### Automated Verification:
- [ ] `EarningsCalendarParserTest` zielony (pojedyncza data, przedział dat, brak `mostRecentQuarter`,
      brak `calendarEvents`, ujemne `days_to_earnings`, granice ±K) — fixed `$referenceDate`
- [ ] PHPStan czysty: `composer stan`
- [ ] Pełny `vendor/bin/phpunit` zielony (brak regresji istniejących parserów/`normalise`)
#### Manual Verification:
- [ ] Live fetch spółki z bliską datą wyników (np. w ciągu tygodnia) → sensowne `days_since_earnings`/`days_to_earnings` w logu
- [ ] Spółka bez pokrycia `calendarEvents` → oba/jedno pole `null`, bez błędu fetch'a

**Implementation Note**: pauza na potwierdzenie manualne przed Fazą 2.

---

## Phase 2: Earnings-proximity guard + blok `earnings_timing`

### Overview
Dodaj pokrętła configu, czystą klasę `EarningsGuard` (stan + kara proximity-based), nowy zawsze-obecny
blok `earnings_timing` w `CVSResult` (badge, FR-010) i rozszerz blok cienia 3.1 o `penalties.earnings_guard`.

### Changes Required:

#### 1. Config — pokrętła guard'u
**File**: `config/cvs-weights.php`
**Intent**: Wprowadzić sekcję `earnings_guard` (FR-010, zero hardkodu), bliźniaczą do `overlays`.
**Contract**:
```php
'earnings_guard' => [
    'enabled'         => true,
    'window_sessions' => 5,                              // K (OQ-1) — symetrycznie przed/po
    'penalty'         => ['slope' => X, 'cap' => Y],     // kara=max(-cap, -slope*proximity), proximity∈[0,1]
],
```

#### 2. Czysta klasa guard'u
**File**: `src/CVS/EarningsGuard.php` (nowy, `CVS\CVS\`, mirror `OverlayPenalties`)
**Intent**: Wyznaczyć stan czasu wyników i karę tłumiącą — czysta arytmetyka na `days_since`/`days_to` + config.
**Contract**:
```php
final class EarningsGuard
{
    /** 'before' | 'after' | 'in_transit' | null */
    public static function state(?int $daysToEarnings, ?int $daysSinceEarnings, int $windowSessions): ?string;

    /** Proximity-based tempering penalty, ≤ 0, capped. 0.0 gdy poza oknem/wyłączone/brak danych. */
    public static function penalty(?int $daysToEarnings, ?int $daysSinceEarnings, array $cfg): float;
}
```
`state`: `'before'` gdy `0 ≤ days_to ≤ K`; `'after'` gdy `0 ≤ days_since ≤ K`; `'in_transit'` gdy
`days_to < 0` (kalendarz wskazuje przeszłość) **i** `days_since` mieści się w oknie po (data-lag Yahoo);
`null` poza oknem lub brak danych. `penalty`: `proximity = max(0, (K − min(pasujące days_to/days_since
w oknie)) / K)`, `kara = round(max(-cap, -slope * proximity), 1)`.

#### 3. Wpięcie w `CVSModel` + rozszerzenie bloku cienia
**File**: `src/CVS/CVSModel.php`
**Intent**: Dodać zawsze-obecny `earnings_timing` (niezależny od `overlays.enabled`) i dolicz karę guard
do istniejącego `computeOverlay()`.
**Contract**: nowa prywatna `computeEarningsTiming(array $financials): ?array` zwraca
`{days_since, days_to, state, guard_active}` (lub `null` przy braku obu pól) — czyta `window_sessions`
z `earnings_guard` (FR-010), **niezależnie od flag `enabled`** (badge ma działać zawsze).
`computeOverlay()` (linie 185-219) doliczy `$guardPenalty = EarningsGuard::penalty(...)` do
`$totalPenalty`, doda `'earnings_guard' => $guardPenalty` do `penalties` i `'missing_earnings_calendar'
=> ($daysTo === null && $daysSince === null)` do `coverage`. Wywołanie w `calculate()` (linia 144):
`$earningsTiming = $this->computeEarningsTiming($financials);` przekazane do `CVSResult::passed(...,
earningsTiming: $earningsTiming)`.

#### 4. `CVSResult` — nowy addytywny blok
**File**: `src/CVS/CVSResult.php`
**Intent**: Przenieść `earnings_timing` jako osobne, zawsze-dostępne pole (FR-017, wstecznie zgodne).
**Contract**: nowy `readonly ?array $earningsTiming`, parametr `passed(..., ?array $earningsTiming = null)`,
`toArray()` dostaje klucz `'earnings_timing'`. `failed()` bez zmian (`null`). Kształt:
`['days_since' => ?int, 'days_to' => ?int, 'state' => ?string, 'guard_active' => bool]`.

### Success Criteria:
#### Automated Verification:
- [ ] `EarningsGuardTest` zielony (granice K, stany before/after/in_transit/null, proximity 0/0.5/1.0, capy, null inputs)
- [ ] CVSModel — `earnings_timing` obecny i poprawny niezależnie od `overlays.enabled`/`earnings_guard.enabled`
- [ ] CVSModel — `overlay.penalties.earnings_guard` poprawnie dolicza się do `total`/shadow swing/fund; baza 3.0 nietknięta
- [ ] Test determinizmu (dwukrotny `calculate()` z tymi samymi `$financials` → identyczny wynik)
- [ ] PHPStan czysty; pełny `vendor/bin/phpunit` zielony
#### Manual Verification:
- [ ] Spółka z wynikami za < K sesji → `state='before'`, `guard_active=true`, niezerowa kara w podglądzie 3.1
- [ ] Spółka poza oknem → `state=null`, `guard_active=false`, kara guard = 0

**Implementation Note**: pauza na potwierdzenie manualne przed Fazą 3.

---

## Phase 3: Persystencja — migracja 015 + `CvsSnapshotRepository`

### Overview
Dodaj 4 addytywne kolumny do `cvs_snapshots` (znaczniki czasu wyników, FR-008) i rozszerz `save()`
o ich zapis/aktualizację z `$result['earnings_timing']`.

### Changes Required:

#### 1. Migracja 015
**File**: `database/migrations/015_add_earnings_timing_to_snapshots.sql` (nowy)
**Intent**: Addytywne, nullable kolumny — bez utraty danych, FR-019.
**Contract**:
```sql
ALTER TABLE cvs_snapshots
    ADD COLUMN days_since_earnings   INT          NULL AFTER model_version,
    ADD COLUMN days_to_earnings      INT          NULL AFTER days_since_earnings,
    ADD COLUMN earnings_state        VARCHAR(20)  NULL AFTER days_to_earnings,
    ADD COLUMN earnings_guard_active TINYINT(1)   NULL AFTER earnings_state;
-- Rollback: DROP COLUMN earnings_guard_active, earnings_state, days_to_earnings, days_since_earnings;
```
(dokładna pozycja `AFTER` do potwierdzenia względem realnego DDL po migracji 013/014 — zachować spójność z istniejącym układem).

#### 2. Repozytorium snapshotów
**File**: `src/TrackRecord/CvsSnapshotRepository.php`
**Intent**: Persystować nowy blok `earnings_timing` — wzorzec istniejących pól (`golden_signal`, linie 60/77).
**Contract**: w `save()` (linie 47-134) odczyt `$et = $result['earnings_timing'] ?? [];`, nowe bind
parametry `:days_since_earnings`, `:days_to_earnings`, `:earnings_state`, `:earnings_guard_active`
(jako `int` 0/1 lub `null`), dopisane do list kolumn INSERT (linie 85-93) i UPDATE (linie 106-124).

### Success Criteria:
#### Automated Verification:
- [ ] Migracja 015 aplikuje się czysto na świeżej bazie testowej (po 001-014)
- [ ] `CvsSnapshotRepository::save()` zapisuje i aktualizuje (idempotentnie) nowe kolumny — test round-trip
- [ ] Snapshoty bez `earnings_timing` (np. `quality_gate=false`) zapisują `NULL` bez błędu
- [ ] PHPStan czysty; pełny `vendor/bin/phpunit` zielony
#### Manual Verification:
- [ ] `SHOW COLUMNS FROM cvs_snapshots` na bazie deweloperskiej potwierdza 4 nowe kolumny
- [ ] Ręczny przebieg `bin/rescore.php` zapisuje wartości `days_since_earnings`/`earnings_state` dla realnych spółek

**Implementation Note**: pauza na potwierdzenie manualne przed Fazą 4 — Faza 4 (badge na screenerze)
wymaga, by w bazie były już snapshoty z nowymi kolumnami (po migracji + co najmniej jednym rescore).

---

## Phase 4: UI — chip stanu czasu wyników (detal + screener)

### Overview
Jeden spójny chip stanu („📅 Wyniki za N dni" / „W oknie wyników" / „Wyniki N dni temu") na detalu
(z żywego `CVSResult`) i screenerze (z persystowanego wiersza DB), wzorzec `signal-pill`.

### Changes Required:

#### 1. Detal — chip z `earnings_timing`
**File**: `templates/analysis.php`
**Intent**: Pokazać badge obok istniejących sygnałów/score-tiles, czytając zawsze-obecny
`$result['earnings_timing']` (niezależnie od `$result['overlay']`).
**Contract**: blok warunkowy `if (($et = $result['earnings_timing'] ?? null) !== null && $et['state'] !== null)`,
render `<span class="signal-pill ...">📅 ...</span>` z treścią zależną od `state`/`days_*`, umieszczony
blisko `overlay-preview-chip` (linia ~235), ale jako **niezależny** blok (nie zagnieżdżony w warunku `$overlay !== null`).

#### 2. Screener — chip z persystowanego wiersza
**File**: `templates/screener.php`
**Intent**: Dodać kolumnę/chip stanu w tabeli, czytając `$row['earnings_state']`/`$row['days_to_earnings']`/
`$row['days_since_earnings']` — wzorzec `$signalChip` (linie 40-47) i kolumna `Sygnał` (linie 141, 176).
**Contract**: nowy helper-closure `$earningsChip` analogiczny do `$signalChip`, nowy `<th>` w `<thead>`
(po `Sygnał`, linia 141) i `<td>` w wierszu (po linii 176); `default` (state `null`/brak danych) renderuje `—`
spójnie z istniejącym wzorcem braku sygnału.

### Success Criteria:
#### Automated Verification:
- [ ] `php -l` obu szablonów OK (lekcja fazy 3 — składnia szablonów)
- [ ] PHPStan czysty; pełny `vendor/bin/phpunit` zielony
#### Manual Verification:
- [ ] Detal spółki z bliskimi wynikami pokazuje chip z poprawnym stanem i liczbą dni
- [ ] Screener pokazuje chip dla spółek z danymi w kolumnie i `—` dla spółek bez pokrycia (po przebiegu rescore z Fazy 3)
- [ ] Brak regresji istniejących chipów (`signal-pill`, `overlay-preview-chip`) i layoutu tabeli

**Implementation Note**: pauza na potwierdzenie manualne; po Fazie 4 plaster gotowy do PR.

---

## Testing Strategy

### Unit Tests:
- `EarningsCalendarParser::parse` — pojedyncza data vs przedział (bierz pierwszą), brak `mostRecentQuarter`/
  `calendarEvents`, ujemne `days_to_earnings`, fixed `$referenceDate` (offline, deterministyczne).
- `EarningsGuard::state/penalty` — granice okna K, przejścia stanów (before/after/in_transit/null),
  proximity 0/częściowe/1.0, capy, `null`/wyłączone wejścia (mirror `OverlayPenaltiesTest`).
- `CVSResult` — addytywność `earnings_timing` (zawsze obecny niezależnie od `overlay`) + `toArray()`.

### Integration Tests:
- CVSModel — `earnings_timing` poprawny niezależnie od `overlays`/`earnings_guard.enabled`; kara guard
  poprawnie dolicza się do shadow 3.1, baza 3.0 niezmieniona; determinizm (dwukrotne `calculate()`).
- `CvsSnapshotRepository` — zapis/odczyt/idempotencja 4 nowych kolumn, w tym `NULL` dla `quality_gate=false`.

### Manual Testing Steps:
1. Live fetch spółki z bliskimi/odległymi wynikami — sprawdź `days_since_earnings`/`days_to_earnings`/`state`.
2. Detal — chip stanu z poprawną treścią; brak regresji `overlay-preview-chip`/headline reco (3.0).
3. Migracja 015 + `bin/rescore.php` — kolumny wypełnione; screener pokazuje chip z DB.

## Performance Considerations
`calendarEvents` dokładany do istniejącego wywołania quoteSummary (bez nowego round-tripu, FR-018/NFR).
Parsowanie i kara guard to znikoma arytmetyka na już pobranych danych — brak wpływu na czas odpowiedzi.

## Migration Notes
Migracja 015 dokłada 4 nullable kolumny do `cvs_snapshots` — czysto addytywna (FR-019), bez ALTER na
istniejących polach (w odróżnieniu od migracji 014, która zmieniała UNIQUE). Rollback = `DROP COLUMN`
w odwrotnej kolejności. Pre-migracyjne snapshoty mają `NULL` w nowych kolumnach — czytelne, badge się nie renderuje.

## References
- PRD: `context/foundation/prd.md` (FR-006..010, FR-015..019)
- Shape: `context/foundation/shape-notes.md` (linie 120-262 — „Świadomość czasu wyników", OQ-1..OQ-4)
- Wzorzec plastra 1: `context/changes/cvs-overlay-penalties/plan.md` (shadow-mode, `OverlayPenalties`, migracja 014)
- Punkt wpięcia guard: [CVSModel.php:144](cvs-composite-valuation-score/src/CVS/CVSModel.php#L144);
  parser-wzorzec: [EarningsTrendParser.php](cvs-composite-valuation-score/src/Forecast/EarningsTrendParser.php)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Fetch & parse — wejścia czasu wyników do `$financials`
#### Automated
- [x] 1.1 `EarningsCalendarParserTest` zielony (pojedyncza data, przedział, braki, ujemne `days_to`, granice ±K) — c88010d
- [x] 1.2 PHPStan czysty (`composer stan`) — c88010d
- [x] 1.3 Pełny `vendor/bin/phpunit` zielony — c88010d
#### Manual
- [x] 1.4 Live fetch spółki z bliską datą wyników → sensowne `days_since_earnings`/`days_to_earnings` — c88010d
- [x] 1.5 Spółka bez pokrycia `calendarEvents` → pola `null`, bez błędu — c88010d

### Phase 2: Earnings-proximity guard + blok `earnings_timing`
#### Automated
- [x] 2.1 `EarningsGuardTest` zielony (granice K, stany, proximity, capy, null inputs) — 40f78d5
- [x] 2.2 `earnings_timing` obecny/poprawny niezależnie od flag `overlays`/`earnings_guard.enabled` — 40f78d5
- [x] 2.3 `overlay.penalties.earnings_guard` poprawnie dolicza się; baza 3.0 nietknięta — 40f78d5
- [x] 2.4 Test determinizmu (dwukrotny `calculate()` → identyczny wynik) — 40f78d5
- [x] 2.5 PHPStan czysty; pełny `vendor/bin/phpunit` zielony — 40f78d5
#### Manual
- [x] 2.6 Spółka z wynikami za < K sesji → `state='before'`, `guard_active=true`, niezerowa kara — 40f78d5
- [x] 2.7 Spółka poza oknem → `state=null`, `guard_active=false`, kara = 0 — 40f78d5

### Phase 3: Persystencja — migracja 015 + `CvsSnapshotRepository`
#### Automated
- [x] 3.1 Migracja 015 aplikuje się czysto na świeżej bazie testowej — 87b6ab6
- [x] 3.2 `save()` zapisuje/aktualizuje nowe kolumny idempotentnie (round-trip test) — 87b6ab6
- [x] 3.3 Snapshoty bez `earnings_timing` zapisują `NULL` bez błędu — 87b6ab6
- [x] 3.4 PHPStan czysty; pełny `vendor/bin/phpunit` zielony — 87b6ab6
#### Manual
- [x] 3.5 `SHOW COLUMNS FROM cvs_snapshots` potwierdza 4 nowe kolumny na bazie deweloperskiej — 87b6ab6
- [x] 3.6 Ręczny `bin/rescore.php` zapisuje wartości dla realnych spółek — 87b6ab6

### Phase 4: UI — chip stanu czasu wyników (detal + screener)
#### Automated
- [x] 4.1 `php -l` obu szablonów OK — 0ea7548
- [x] 4.2 PHPStan czysty; pełny `vendor/bin/phpunit` zielony — 0ea7548
#### Manual
- [x] 4.3 Detal pokazuje chip z poprawnym stanem i liczbą dni — 0ea7548
- [x] 4.4 Screener pokazuje chip/`—` zgodnie z danymi po rescore — 0ea7548
- [x] 4.5 Brak regresji istniejących chipów i layoutu — 0ea7548
