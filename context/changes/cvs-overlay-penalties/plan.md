# CVS Overlay Penalties (Faza 5, plaster 1) — Implementation Plan

## Overview

Dodajemy dwa **deterministyczne overlaye jako kary post-agregacyjne** do modelu CVS:
- **Overlay A (rewizja prognoz)** — kara celowana: `trap=(valScore−50)/50 × skala cięcia forward-EPS`,
  źródło sygnału = Yahoo `earningsTrend.epsTrend` dla okresu **+1q** (current vs 90daysAgo).
- **Overlay B (cena-vs-target)** — kara liniowa gdy `upside` (z `ForecastParser`) < 0.

Liczone w **trybie cieniowym** za `model_version` 3.1: model liczy wynik bazowy (3.0, dziś pokazywany)
ORAZ wynik 3.1 z karami; pokazywana rekomendacja zostaje 3.0 do czasu rekalibracji (guardrail FR-016),
a snapshoty 3.1 są zapisywane równolegle, by nazbierać dane do rekalibracji. Walidacja referencyjna:
`cvs-composite-valuation-score/sim_overlay.php`.

## Current State Analysis

- `CVSModel::calculate()` ([src/CVS/CVSModel.php:84](cvs-composite-valuation-score/src/CVS/CVSModel.php#L84)) liczy filary, agreguje
  `$swingCvs`/`$fundCvs` (linie 111–123), clampuje i mapuje na etykietę (`mapToLabel`). **Czysty punkt wpięcia kar = między agregacją a `mapToLabel`.**
- `CVSResult` ([src/CVS/CVSResult.php](cvs-composite-valuation-score/src/CVS/CVSResult.php)) jest immutable (named constructors `passed()`/`failed()`, `readonly`). Rozszerzenie = nowe opcjonalne argumenty + pola + klucze `toArray()` (addytywnie, wstecznie zgodne — FR-017).
- `ForecastParser::parse()` ([src/Forecast/ForecastParser.php:58](cvs-composite-valuation-score/src/Forecast/ForecastParser.php#L58)) zwraca gotowy `targets.upside` = `(mean−price)/price`. Dane Overlay B **już istnieją**.
- `FinancialDataFetcher::MODULES` ([src/Api/FinancialDataFetcher.php:43](cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php#L43)) ma 8 modułów, **bez `earningsTrend`**. `normalise()` ([:340](cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php#L340)) buduje płaską tablicę `$financials` konsumowaną przez model.
- `cvs_snapshots` ma `UNIQUE KEY uq_ticker_day (ticker, score_date)` ([migration 004:20](cvs-composite-valuation-score/database/migrations/004_create_cvs_snapshots.sql#L20)) — **bez `model_version`**. Dual-write 3.0+3.1 dla tej samej daty zderzy się; wymaga poszerzenia klucza.
- `config/cvs-weights.php` niesie `model_version: '3.0'` i wszystkie pokrętła (FR-010). `FinancialDataFetcher` jest **wyłączony z testów** (CLAUDE.md) → logika parsowania musi żyć w czystym, testowalnym parserze (wzorzec `ForecastParser`).

## Desired End State

`CVSModel` zwraca `CVSResult` niosący — obok niezmienionego wyniku bazowego 3.0 — **blok cienia 3.1**:
wynik swing/fund po karach, etykiety, rozbicie kar (`revision`, `target`, `total`) i flagi pokrycia
(`missing_eps_trend`, `missing_target`). Rescore zapisuje równolegle snapshot 3.0 i 3.1. Detal pokazuje
chip „Podgląd 3.1: −X pkt". Testy złote odtwarzają `sim_overlay.php` dla NVO/AVGO/QCOM/MU.

### Key Discoveries:
- Punkt wpięcia kar: [CVSModel.php:111](cvs-composite-valuation-score/src/CVS/CVSModel.php#L111) (po agregacji, przed `mapToLabel`).
- `upside` gotowe w [ForecastParser.php:58](cvs-composite-valuation-score/src/Forecast/ForecastParser.php#L58).
- Sygnatury kar i przypadki referencyjne: `sim_overlay.php` (`revisionPenalty`, `targetGatePenalty`).
- UNIQUE snapshotów do poszerzenia: [migration 004:20](cvs-composite-valuation-score/database/migrations/004_create_cvs_snapshots.sql#L20).

## What We're NOT Doing

- **Brak earnings-proximity guard** (FR-006..010), **brak normalizacji FCF** (FR-011), **brak rekalibracji progów** (FR-012..014) — osobne, późniejsze plastry.
- **Brak parsowania `mostRecentQuarter`/`calendarEvents`** w tym plastrze (Q5 — ściśle A+B).
- **Brak aktywacji 3.1 na produkcji** — tryb cieniowy; headline reco zostaje 3.0 (FR-016).
- **Brak zmian Momentum / auth / UX-redesign / peer-group z fazy 3.**
- **Brak finalnej kalibracji pokręteł** — używamy ilustracyjnych wartości z sim jako domyślnych w configu (OQ-2, do rekalibracji).

## Implementation Approach

Czysta warstwa kar post-agregacyjnych: nowa klasa `OverlayPenalties` (czyste funkcje statyczne, sygnatury z sim),
wpięta w `CVSModel` za flagą configu. Sygnały wejściowe (`eps_revision_pct` z nowego czystego parsera epsTrend,
`analyst_target_upside` z `ForecastParser`) doprowadzone przez `FinancialDataFetcher::normalise()`. Wynik 3.1
podróżuje w `CVSResult` obok bazowego; persystencja i UI czytają blok cienia. Determinizm: kary to czysta arytmetyka
na wejściach, zero `date()/time()`.

## Critical Implementation Details

- **UNIQUE snapshotów blokuje dual-write.** `uq_ticker_day (ticker, score_date)` musi zostać poszerzony do
  `(ticker, score_date, model_version)` (Faza 4), inaczej zapis 3.1 nadpisze/zderzy 3.0 tego samego dnia.
- **epsTrend +1q przy granicy kwartału.** Gdy okres `+1q` się przewija, `90daysAgo` bywa `null`/0 → parser zwraca
  `null` → Overlay A = 0 + flaga `missing_eps_trend` (Q4). Nie liczyć rewizji z zerowego mianownika.
- **Parser epsTrend musi być czysty.** `FinancialDataFetcher` jest poza testami — ekstrakcja rewizji idzie do
  testowalnej klasy (jak `ForecastParser`), `FDF` tylko ją woła.
- **`current_price` z żywej ceny.** `upside` i EV liczone z bieżącej ceny; spójne z dzisiejszym zachowaniem (kary nie zmieniają tej mechaniki).

## Phase 1: Fundament — config + CVSResult niesie rozbicie kar

### Overview
Dodaj pokrętła overlayów i wersję cienia do configu; rozszerz `CVSResult` o blok cienia 3.1 (addytywnie).

### Changes Required:

#### 1. Config — pokrętła overlayów + wersja cienia
**File**: `config/cvs-weights.php`
**Intent**: Wprowadzić sekcję `overlays` (FR-010, zero hardkodu) z wartościami domyślnymi z `sim_overlay.php`.
**Contract**: nowy klucz `'overlays'`:
```php
'overlays' => [
    'enabled'       => true,
    'shadow_version'=> '3.1',
    'revision'      => ['slope' => 120.0, 'cap' => 18.0], // trap=(val-50)/50; kara=max(-cap, slope*rev*trap)
    'target_gate'   => ['slope' => 60.0,  'cap' => 18.0], // kara=max(-cap, upside*slope) dla upside<0
],
```
`model_version` (bazowe) zostaje `'3.0'`.

#### 2. CVSResult — blok cienia 3.1
**File**: `src/CVS/CVSResult.php`
**Intent**: Przenieść wynik 3.1 (po karach) obok bazowego, bez zmiany pól bazowych (FR-017 wstecznie zgodne).
**Contract**: nowy opcjonalny argument `passed(... , ?array $overlay = null)` + `readonly ?array $overlay`. Kształt:
`['shadow_version'=>'3.1','swing'=>f,'fund'=>f,'swing_reco'=>s,'fund_reco'=>s,'penalties'=>['revision'=>f,'target'=>f,'total'=>f],'coverage'=>['missing_eps_trend'=>bool,'missing_target'=>bool]]`.
`toArray()` dostaje addytywny klucz `'overlay'` (null gdy brak). `failed()` bez zmian (overlay=null).

### Success Criteria:
#### Automated Verification:
- [ ] PHPStan czysty: `composer stan`
- [ ] Istniejące testy CVSResult/CVSModel zielone (brak regresji pól bazowych): `vendor/bin/phpunit`
- [ ] `toArray()` zawiera klucz `overlay` (null) gdy overlay nie podany — nowy assert
#### Manual Verification:
- [ ] Diff CVSResult jest czysto addytywny (żadne istniejące pole/klucz nie zmienia typu)

**Implementation Note**: pauza na potwierdzenie manualne przed Fazą 2.

---

## Phase 2: Wejścia danych — epsTrend (+1q) + upside do modelu

### Overview
Dociągnij moduł `earningsTrend`, sparsuj rewizję +1q w czystym parserze, doprowadź `eps_revision_pct` i
`analyst_target_upside` do `$financials`.

### Changes Required:

#### 1. Nowy moduł Yahoo
**File**: `src/Api/FinancialDataFetcher.php`
**Intent**: Dodać `earningsTrend` do `MODULES` (jedno wywołanie, bez nowego round-tripu — NFR).
**Contract**: `MODULES` rośnie o `'earningsTrend'`.

#### 2. Czysty parser rewizji
**File**: `src/Forecast/EarningsTrendParser.php` (nowy, `CVS\Forecast\`)
**Intent**: Wyliczyć % rewizji estymaty EPS dla okresu +1q (current vs 90daysAgo), testowalnie offline.
**Contract**: `EarningsTrendParser::revisionPct(array $raw): ?float` — znajduje w `earningsTrend.trend[]` wpis
`period === '+1q'`, czyta `epsTrend.current` i `epsTrend.90daysAgo`; zwraca `(current/ago) - 1` jako ułamek
(np. `-0.13`); `null` gdy brak danych, `ago` ≤ 0, lub current null (→ flaga pokrycia w modelu).

#### 3. Podłączenie sygnałów do financials
**File**: `src/Api/FinancialDataFetcher.php` (`normalise()`)
**Intent**: Wystawić dwa nowe wejścia konsumowane przez overlaye.
**Contract**: `normalise()` dodaje klucze: `'eps_revision_pct' => EarningsTrendParser::revisionPct($raw)` oraz
`'analyst_target_upside' => <upside z istniejącego ForecastParser::parse()>` (już liczony — przepiąć do płaskiej mapy).

### Success Criteria:
#### Automated Verification:
- [ ] `EarningsTrendParserTest` zielony: cięcie (−), wzrost (+), brak/zero `90daysAgo` → null, brak okresu +1q → null
- [ ] PHPStan czysty: `composer stan`
- [ ] Pełny zestaw testów zielony: `vendor/bin/phpunit`
#### Manual Verification:
- [ ] Live fetch realnej spółki (np. NVO, AVGO) loguje sensowny `eps_revision_pct` i `analyst_target_upside`
- [ ] Spółka bez pokrycia analitycznego → oba pola `null`, bez błędu

**Implementation Note**: pauza na potwierdzenie manualne przed Fazą 3.

---

## Phase 3: Silnik overlayów + wpięcie + testy złote

### Overview
Zaimplementuj `OverlayPenalties`, wepnij wynik cienia 3.1 w `CVSModel`, pokryj testami złotymi z sim.

### Changes Required:

#### 1. Klasa kar
**File**: `src/CVS/OverlayPenalties.php` (nowy, `CVS\CVS\`)
**Intent**: Dwie czyste, deterministyczne funkcje kar — sygnatury 1:1 z `sim_overlay.php`.
**Contract**:
```php
// rev/upside jako ułamki; cfg z config['overlays']
static function revision(float $valScore, ?float $rev, array $cfg): float;   // 0 gdy rev null lub ≥0
static function targetGate(?float $upside, array $cfg): float;               // 0 gdy upside null lub ≥0
// revision: trap=clamp((valScore-50)/50,0,1); return max(-cap, slope*rev*trap)
// targetGate: return max(-cap, upside*slope)
```

#### 2. Wpięcie cienia w model
**File**: `src/CVS/CVSModel.php` (`calculate()`)
**Intent**: Po agregacji bazowej policzyć karę i wynik 3.1; zbudować blok overlay; przekazać do `CVSResult::passed()`.
**Contract**: gdy `config['overlays']['enabled']`: czytaj `eps_revision_pct`/`analyst_target_upside` z `$financials`;
`$pen = revision($valScore, $rev, $cfg) + targetGate($upside, $cfg)`; `$shadowSwing/$shadowFund = clamp(base+$pen)`;
etykiety przez istniejący `mapToLabel`; flagi pokrycia z null-ości wejść. Pola bazowe (3.0) **bez zmian**.

#### 3. Testy złote (odtworzenie sim)
**File**: `tests/CVS/OverlayPenaltiesTest.php` (nowy) + rozszerzenie `tests/CVS/CVSModelTest.php`
**Intent**: Dowieść US-01 deterministycznie — wynik 3.1 zgodny z sim dla próbki referencyjnej.
**Contract**: fixtures NVO/AVGO/QCOM/MU (z `sim_overlay.php`); asserty: NVO 3.1 fund *AKUMULUJ*/swing *NEUTRALNIE*;
AVGO 3.1 = baza (oba overlaye 0); QCOM/MU 3.1 fund o szczebel niżej; baza (3.0) niezmieniona; ten sam input → ten sam wynik.

### Success Criteria:
#### Automated Verification:
- [ ] `OverlayPenaltiesTest` zielony (granice: null, ≥0, nasycenie trap, capy)
- [ ] Testy złote CVSModel zielone — 3.1 zgodne z sim dla NVO/AVGO/QCOM/MU
- [ ] Test determinizmu: dwukrotny `calculate()` na tym samym input → identyczny wynik
- [ ] PHPStan czysty: `composer stan`; pełny `vendor/bin/phpunit` zielony
#### Manual Verification:
- [ ] `php sim_overlay.php` i model na tych samych fixtures dają zgodne etykiety 3.1 (spójność spec↔kod)

**Implementation Note**: pauza na potwierdzenie manualne przed Fazą 4.

---

## Phase 4: Shadow persistence + podgląd na detalu

### Overview
Zapisuj snapshot 3.1 obok 3.0 (poszerzony UNIQUE) i pokaż podgląd 3.1 na detalu; headline reco zostaje 3.0.

### Changes Required:

#### 1. Migracja — poszerzenie klucza snapshotów
**File**: `database/migrations/014_widen_snapshot_unique.sql` (nowy)
**Intent**: Umożliwić dwa wiersze (3.0 i 3.1) dla tej samej spółki/dnia.
**Contract**: `DROP INDEX uq_ticker_day` → `ADD UNIQUE KEY uq_ticker_day_version (ticker, score_date, model_version)`.
Addytywne dla danych (zmiana klucza, bez utraty wierszy). Rollback: odwrotny ALTER.

#### 2. Zapis cienia w rescore
**File**: `bin/rescore.php` + `src/TrackRecord/CvsSnapshotRepository.php`
**Intent**: Po policzeniu `CVSResult` zapisać wiersz bazowy 3.0 (jak dziś) ORAZ wiersz 3.1 z bloku overlay.
**Contract**: `CvsSnapshotRepository::save()` wywoływany dwukrotnie albo nowa metoda zapisująca parę; wiersz 3.1
niesie `swing/fund` z `overlay`, `model_version = shadow_version`. `ON DUPLICATE KEY UPDATE` po nowym kluczu. Idempotentne.

#### 3. Podgląd 3.1 na detalu
**File**: `src/CVS/AnalysisController.php` (`show()`) + szablon detalu
**Intent**: Pokazać chip „Podgląd 3.1: −X pkt (rewizja −a / target −b)" + ew. nota niskiego pokrycia; headline reco = 3.0.
**Contract**: przekazać `CVSResult->overlay` do szablonu; chip renderuje `penalties.total` i rozbicie; gdy
`coverage.missing_*` → mała adnotacja „brak danych: rewizja/target". Zero zmian w headline reco/etykietach 3.0.

### Success Criteria:
#### Automated Verification:
- [ ] Migracja 014 aplikuje się czysto na świeżej bazie testowej
- [ ] Test repozytorium: zapis 3.0 i 3.1 dla tej samej (ticker, score_date) współistnieje (brak kolizji)
- [ ] Idempotencja: dwukrotny zapis tej samej pary nie tworzy duplikatów
- [ ] PHPStan czysty; pełny `vendor/bin/phpunit` zielony
#### Manual Verification:
- [ ] `php -l` szablonu detalu OK (lekcja fazy 3 — składnia szablonów)
- [ ] Detal realnej spółki pokazuje chip 3.1 z poprawnym rozbiciem; headline reco niezmieniony (3.0)
- [ ] Ręczny przebieg `bin/rescore.php` zapisuje pary 3.0/3.1; istniejące widoki track-record 3.0 bez regresji

**Implementation Note**: pauza na potwierdzenie manualne; po Fazie 4 plaster gotowy do PR.

---

## Testing Strategy

### Unit Tests:
- `OverlayPenalties::revision/targetGate` — granice: null, ≥0, ujemne, nasycenie `trap`, capy.
- `EarningsTrendParser::revisionPct` — cięcie/wzrost/null (brak +1q, zero `90daysAgo`).
- CVSResult — addytywność `overlay` + `toArray()`.

### Integration Tests:
- CVSModel golden — NVO/AVGO/QCOM/MU: wynik 3.1 zgodny z `sim_overlay.php`, baza 3.0 niezmieniona, determinizm.
- CvsSnapshotRepository — współistnienie 3.0/3.1, idempotencja po nowym kluczu.

### Manual Testing Steps:
1. Live fetch NVO/AVGO — sprawdź `eps_revision_pct`, `analyst_target_upside`.
2. Detal — chip „Podgląd 3.1" z rozbiciem; headline reco = 3.0.
3. `bin/rescore.php` — pary snapshotów 3.0/3.1; track-record 3.0 bez regresji.

## Performance Considerations
Kary to znikoma arytmetyka. `earningsTrend` dokładany do istniejącego wywołania quoteSummary (bez nowego round-tripu).
Shadow = jeden dodatkowy zapis na spółkę w rescore (akceptowalne dla ~unii watchlist).

## Migration Notes
Migracja 014 zmienia klucz UNIQUE snapshotów (poszerzenie o `model_version`) — bez utraty danych; rollback odwrotnym ALTER.
Snapshoty 3.0 pozostają czytelne; track-record liczony per wersja (mechanizm fazy 3).

## References
- PRD: `context/foundation/prd.md` (FR-001..005, FR-015..019)
- Shape: `context/foundation/shape-notes.md`
- Walidacja referencyjna: `cvs-composite-valuation-score/sim_overlay.php`
- Punkt wpięcia: [CVSModel.php:111](cvs-composite-valuation-score/src/CVS/CVSModel.php#L111); upside: [ForecastParser.php:58](cvs-composite-valuation-score/src/Forecast/ForecastParser.php#L58)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Fundament — config + CVSResult niesie rozbicie kar
#### Automated
- [x] 1.1 PHPStan czysty (`composer stan`) — 75d14b2
- [x] 1.2 Istniejące testy CVSResult/CVSModel zielone (brak regresji pól bazowych) — 75d14b2
- [x] 1.3 `toArray()` zawiera klucz `overlay` (null) gdy overlay nie podany — 75d14b2
#### Manual
- [x] 1.4 Diff CVSResult czysto addytywny (typy pól bazowych bez zmian) — 75d14b2

### Phase 2: Wejścia danych — epsTrend (+1q) + upside do modelu
#### Automated
- [x] 2.1 `EarningsTrendParserTest` zielony (cięcie/wzrost/null/brak +1q) — 591a87f
- [x] 2.2 PHPStan czysty — 591a87f
- [x] 2.3 Pełny `vendor/bin/phpunit` zielony — 591a87f
#### Manual
- [x] 2.4 Live fetch NVO/AVGO loguje sensowny `eps_revision_pct` i `analyst_target_upside` — 591a87f
- [x] 2.5 Spółka bez pokrycia → oba pola null, bez błędu — 591a87f

### Phase 3: Silnik overlayów + wpięcie + testy złote
#### Automated
- [x] 3.1 `OverlayPenaltiesTest` zielony (null, ≥0, nasycenie trap, capy) — 2854b7f
- [x] 3.2 Testy złote CVSModel — 3.1 zgodne z sim dla MU/STX/AVGO (adaptacja: sim_overlay.php zawiera obecnie te trzy fixtures, nie NVO/QCOM — patrz decyzja w sesji) — 2854b7f
- [x] 3.3 Test determinizmu (dwukrotny calculate → identyczny wynik) — 2854b7f
- [x] 3.4 PHPStan czysty; pełny `vendor/bin/phpunit` zielony — 2854b7f
#### Manual
- [x] 3.5 `sim_overlay.php` i model dają zgodne etykiety 3.1 na tych samych fixtures — 2854b7f

### Phase 4: Shadow persistence + podgląd na detalu
#### Automated
- [x] 4.1 Migracja 014 aplikuje się czysto na świeżej bazie testowej — 9530a10
- [x] 4.2 Zapis 3.0 i 3.1 dla tej samej (ticker, score_date) współistnieje — 9530a10
- [x] 4.3 Idempotencja zapisu pary (brak duplikatów) — 9530a10
- [x] 4.4 PHPStan czysty; pełny `vendor/bin/phpunit` zielony — 9530a10
#### Manual
- [x] 4.5 `php -l` szablonu detalu OK — 9530a10
- [x] 4.6 Detal pokazuje chip 3.1 z poprawnym rozbiciem; headline reco = 3.0 — 9530a10
- [x] 4.7 `bin/rescore.php` zapisuje pary 3.0/3.1; track-record 3.0 bez regresji — 9530a10
