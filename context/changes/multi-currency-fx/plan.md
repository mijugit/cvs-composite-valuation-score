# Multi-Currency FX Conversion — Implementation Plan

## Overview

Spółki notowane poza USA dostarczają z Yahoo Finance dane finansowe i cenę w walucie macierzystej (np. 000660.KS w KRW). Ten plan wprowadza konwersję wszystkich pól monetarnych do USD w jednym seamie normalizacji (`FinancialDataFetcher::normalise()`), z kursem FX pobieranym z Yahoo `{CCY}=X` i wstrzykiwanym jak `referenceDate` (gwarancja determinizmu). Cena natywna, kurs i kod waluty są zachowane do prezentacji dwuwalutowej (USD wiodące, natywna w nawiasie) dla ceny i Fair Value. Snapshoty rozszerzono o kurs/walutę/cenę natywną, a `model_version` bumpnięto dla czystego rozdziału semantyki.

## Current State Analysis

- **Seam normalizacji** ([FinancialDataFetcher.php:354](src/Api/FinancialDataFetcher.php:354)) — `normalise()` produkuje płaską tablicę `$financials` z ~15 polami monetarnymi, wszystkie w walucie natywnej. Już ekstrahuje `currency` (quote) i `financial_currency` ([:437-438](src/Api/FinancialDataFetcher.php:437)).
- **Determinizm seam już istnieje** — `$referenceDate` jest wyznaczany raz w `fetch()` ([:106](src/Api/FinancialDataFetcher.php:106)) i wstrzykiwany do `normalise()`. Kurs FX pójdzie tym samym wzorcem.
- **Kanał chart gotowy** — `fetchChartData($ticker, $range)` ([:~210](src/Api/FinancialDataFetcher.php:210)) pobiera close'y przez v8 chart endpoint z crumb/cookie flow. SPY jest cache'owany per-sesja pod `cvs_spy_closes` ([:258](src/Api/FinancialDataFetcher.php:258)) — wzorzec do naśladowania dla FX.
- **EV/FCF i EV/Sales są bezwymiarowe** — [ValuationMetrics::enterpriseValue()](src/CVS/Valuation/ValuationMetrics.php:80) liczy `price × shares + debt − cash`; [forwardEvFcf()](src/CVS/Valuation/ValuationMetrics.php:113) dzieli EV/FCF. Gdy licznik i mianownik są w tej samej walucie, waluta się kasuje. Dlatego dla **czysto-zagranicznych** (price i finanse w tej samej walucie) CVS score jest już poprawny; psuje się tylko display.
- **ADR/cross-listed zepsute** — gdy `currency=USD` a `financial_currency=TWD` (np. TSM), EV miesza USD (price×shares) z TWD (debt−cash) → błędny EV → zatruty score. Guard tylko ukrywa fair value.
- **Guardy walutowe (tylko suppress)** — `AiAnalysisController::calcFairPrice()` zwraca null przy mismatch ([:199-204](src/Ai/AiAnalysisController.php:199)); `templates/analysis.php` powtarza ten guard inline ([:273-283](templates/analysis.php:273)) + zahardkodowany label `'Cena bieżąca ($)'` ([:548](templates/analysis.php:548)).
- **Persystencja** — `bin/rescore.php` ([:87-96](bin/rescore.php:87)) pobiera `$financials`, liczy CVS, przekazuje `$price` (natywny) do `SnapshotWriter::persist()` ([SnapshotWriter.php:46](src/TrackRecord/SnapshotWriter.php:46)), zapisując do `cvs_snapshots.price_at_snapshot`.
- **model_version = '3.0'** ([config/cvs-weights.php:18](config/cvs-weights.php:18)). Peer medians są kluczowane per `model_version` ([PeerMedianRepository.php](src/CVS/Valuation/PeerMedianRepository.php)); `CvsSnapshotRepository::findAllLatest($liveVersion)` ([:229](src/TrackRecord/CvsSnapshotRepository.php:229)) filtruje "latest" po wersji. Bump → peer_medians dla nowej wersji nie istnieją (cold-start = score 50) dopóki pełny rescore ich nie odbuduje; stare snapshoty 3.0 znikają z "latest" do repopulacji.
- **Determinizm to twardy guardrail** (CLAUDE.md): "No randomness, no `date()`/`time()` calls inside scoring logic." Kurs FX MUSI być wstrzykiwanym inputem, nie pobieranym w scoringu.

## Desired End State

Analiza dowolnej spółki zagranicznej (np. https://cvs.timeflow.fun/analysis/000660.KS) pokazuje:
- CVS score policzony na danych w USD (poprawny także dla ADR-ów),
- cenę bieżącą jako `$XX.XX (₩XX,XXX)` — USD wiodące, natywna w nawiasie,
- Fair Value w tym samym formacie dwuwalutowym,
- spójne USD w dashboard/screener/track-record.

Spółki US działają identycznie jak dziś (kurs = 1.0, brak natywnej w nawiasie). Gdy kurs FX jest niedostępny dla nie-USD, spółka jest pomijana z czytelnym komunikatem zamiast pokazywać błędne liczby.

### Key Discoveries:

- Seam `normalise()` + wstrzykiwany `referenceDate` to gotowy wzorzec do dodania kursu FX bez łamania determinizmu ([FinancialDataFetcher.php:106](src/Api/FinancialDataFetcher.php:106))
- EV/FCF bezwymiarowe → konwersja nie zmienia score czysto-zagranicznych; jej wartość to fix ADR-ów + display + unifikacja ([ValuationMetrics.php:80](src/CVS/Valuation/ValuationMetrics.php:80))
- `fetchSpyCloses()` to wzorzec cache'owania współdzielonego zasobu per-sesja — FX rate per-waluta analogicznie ([FinancialDataFetcher.php:258](src/Api/FinancialDataFetcher.php:258))
- Bump `model_version` wymusza rebuild peer_medians — musi być zsekwencjonowany z deployem ([CvsSnapshotRepository.php:229](src/TrackRecord/CvsSnapshotRepository.php:229))

## What We're NOT Doing

- Backfill istniejących snapshotów (brak historycznego kursu FX — naprawi je następny rescore)
- Osobne API FX / nowe klucze w `.env` (używamy istniejącego kanału Yahoo)
- Konwersja walutowa watchlisty/alertów poza tym co wynika ze zmiany ceny w snapshocie
- Cache FX w Redis/APCu (trzymamy per-sesja, dependency-free)
- Obsługa walut, których Yahoo nie wystawia jako `{CCY}=X` (pomijamy spółkę)
- Historyczny wykres kursu w walucie natywnej vs USD (poza zakresem; wykres ceny pozostaje bazą=100)

## Implementation Approach

Konwersja żyje w jednym miejscu — `normalise()` — więc cały downstream (pillary, ValuationMetrics, fair value, SnapshotWriter, peer medians) operuje na USD bez świadomości waluty. Kurs FX jest pobierany raz w `fetch()` (jak `referenceDate`) i wstrzykiwany do `normalise()`, co utrzymuje scoring jako czystą funkcję. Pola natywne (`native_price`, `native_currency`, `fx_rate_to_usd`) są dokładane do `$financials` wyłącznie do prezentacji i audytu. Snapshoty zyskują kolumny na kurs/walutę/cenę natywną; `model_version` bump rozdziela epoki, a pełny rescore po deployu odbudowuje peer_medians pod nową wersją.

## Critical Implementation Details

- **Konwencja par FX Yahoo**: `{CCY}=X` (np. `KRW=X`) zwraca **ile jednostek waluty za 1 USD** (USD/CCY). Aby przeliczyć kwotę natywną → USD: `usd = native / rate`. `EURUSD=X` jest odwrotne (USD za 1 EUR). Implementer MUSI zweryfikować kierunek empirycznie dla kilku walut (KRW, EUR, JPY) i ustalić jeden spójny współczynnik `fx_rate_to_usd` taki, że `usd = native × fx_rate_to_usd`.
- **Determinizm**: kurs nie może być pobierany wewnątrz pillarów/ValuationMetrics — tylko wstrzyknięty przez `normalise()`. Inaczej łamie FR-015 i `findAllLatest`/snapshoty stają się nieodtwarzalne.
- **forward_fcf_est double-conversion guard**: `forward_fcf_est` jest pochodną `fcfEffective` ([:481-488](src/Api/FinancialDataFetcher.php:481)). Po konwersji `fcfEffective` do USD, `forward_fcf_est` policzony z już-przeliczonego `fcfEffective` jest automatycznie w USD — NIE konwertować go ponownie.
- **Sekwencja bump↔rebuild**: bump `model_version` (Faza 3) wywołuje cold-start peer_medians; pełny rescore (Faza 5) musi nastąpić tuż po deployu, inaczej wszystkie spółki dostają valuation 50 i znikają ze "latest" do czasu repopulacji.

## Phase 1: FX rate fetch + determinism seam

### Overview
Dodaje pobieranie kursu FX dla waluty finansowej spółki przez Yahoo `{CCY}=X`, cache per-waluta w sesji, i wstrzyknięcie kursu do `normalise()`. Bez konwersji wartości — tylko udostępnienie kursu i pól waluty w `$financials`. De-ryzykuje zależność zewnętrzną przed dotknięciem matematyki.

### Changes Required:

#### 1. FX rate fetch + cache

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Pobrać kurs USD↔waluta dla danej waluty finansowej, cache'owany per-waluta w sesji (wzorzec `fetchSpyCloses()`). USD → 1.0 bez requestu. Brak danych → null (sygnał "skip" dla nie-USD).

**Contract**: Nowa prywatna metoda `fetchFxRateToUsd(string $financialCurrency): ?float` zwracająca współczynnik taki, że `usd = native × rate`; cache key `cvs_fx_<CCY>` z TTL jak reszta; `'USD'` → `1.0`. Używa istniejącego `fetchChartData()` lub dedykowanego wywołania chart endpoint dla `{CCY}=X`. **Pobranie kursu: minimalny zakres (np. `range=5d`, `interval=1d`), wziąć OSTATNI niepusty close — nie `range=3y` jak dla tickera spółki.** Verify kierunku pary (patrz Critical Implementation Details).

#### 2. Wstrzyknięcie kursu do normalise()

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Pobrać kurs raz w `fetch()` (po ustaleniu `financial_currency` z raw) i przekazać do `normalise()` jako argument, analogicznie do `$referenceDate`. Gdy nie-USD i kurs niedostępny → `fetch()` zwraca null.

**Contract**: Sygnatura `normalise(array $raw, array $closes, array $spyCloses, DateTimeImmutable $referenceDate, ?float $fxRateToUsd)`. `$financials` zyskuje: `native_currency` (z `financial_currency`), `fx_rate_to_usd`. Logika skip: jeśli `financial_currency` ≠ 'USD' i `$fxRateToUsd === null` → return null.

### Success Criteria:

#### Automated Verification
- `php vendor/bin/phpstan analyse src/ --level=6 --no-progress` — 0 błędów
- Test jednostkowy: ticker USD → `fx_rate_to_usd === 1.0`, brak wywołania chart dla FX
- Test jednostkowy: fixture waluty obcej z dostępnym kursem → `fx_rate_to_usd` ustawiony, `native_currency` ustawiony
- Test jednostkowy: nie-USD bez kursu → `fetch()`/`normalise()` zwraca null

#### Manual Verification
- Analiza 000660.KS w dev nie wywala się; log/debug pokazuje pobrany kurs KRW
- Analiza spółki US działa bez zmian

---

## Phase 2: Konwersja w normalise()

### Overview
Przelicza pełną listę pól monetarnych do USD przy użyciu `fx_rate_to_usd`, zachowując `native_price` do display. Obsługuje ADR (finanse wg `financial_currency`, cena już USD gdy `currency=USD`). Pilnuje braku podwójnej konwersji pól pochodnych.

### Changes Required:

#### 1. Konwersja pól monetarnych

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: W `normalise()`, po wyliczeniu surowych wartości, przemnożyć wszystkie pola monetarne przez współczynnik konwersji do USD. Zachować `native_price` (cena przed konwersją) i `native_currency` do prezentacji.

**Contract**: Pola do konwersji (mnożone przez `fx_rate_to_usd` finansów, o ile nie są już USD): `current_price`, `fifty_two_week_low`, `fifty_two_week_high`, `moving_average_200`, `revenue`, `gross_profit`, `ebitda`, `total_debt`, `total_equity`, `cash`, `current_assets`, `current_liabilities`, `free_cash_flow`, `free_cash_flow_raw`, `operating_cash_flow`, `forward_eps`, `trailing_eps`, oraz `revenue_history[]`, `gross_margin_history[]` pozostaje bez zmian (ratio). `forward_fcf_est` liczony z już-przeliczonego `fcfEffective` — bez ponownej konwersji. Nowe pola: `native_price` (= surowa cena przed konwersją), `native_currency`. Wartości bezwymiarowe (ratio, growth, margins, ROE, monthly_closes baza=100, EPS-pochodne ratio) NIE konwertowane.

**Contract (ADR)**: Gdy `currency` (quote) = 'USD' ale `financial_currency` ≠ 'USD': cena/52w/MA200 są już w USD (nie konwertować), a pola finansowe (revenue, fcf, debt, cash, ebitda, equity…) konwertować wg kursu `financial_currency`. Rozdzielić współczynnik ceny (quote) od współczynnika finansów.

### Success Criteria:

#### Automated Verification
- `php vendor/bin/phpstan analyse src/ --level=6 --no-progress` — 0 błędów
- `vendor/bin/phpunit tests/CVS/CVSModelTest.php` — zielony (US fixtures niezmienione, kurs=1.0)
- Nowy test-inwariant: fixture KRW (price+finanse KRW) → EV/FCF identyczne (±epsilon) jak liczone natywnie (dowód bezwymiarowości), a `current_price`/`free_cash_flow` w `$financials` są w USD
- Nowy test ADR: fixture USD/TWD → EV liczone spójnie w USD, brak miksu walut

#### Manual Verification
- 000660.KS: CVS score sensowny, wartości w surowych polach (`Dane surowe`) w USD
- ADR (np. TSM): score nie jest już zatruty; fair value liczone bez suppress

---

## Phase 3: Migracja snapshotów + writer/reader + bump wersji

### Overview
Rozszerza `cvs_snapshots` o kurs/walutę/cenę natywną, zmienia semantykę `price_at_snapshot` na USD, bumpuje `model_version`. Writer i reader przenoszą nowe pola.

### Changes Required:

#### 1. Migracja schematu

**File**: `database/migrations/NNN_add_currency_to_snapshots.sql`

**Intent**: Dodać kolumny audytu waluty do snapshotów. Additive-only (nie łamać istniejących).

**Contract**: `ALTER TABLE cvs_snapshots ADD COLUMN fx_rate_to_usd DECIMAL/DOUBLE NULL, ADD COLUMN native_currency VARCHAR(8) NULL, ADD COLUMN native_price ... NULL`. `price_at_snapshot` pozostaje kolumną, ale od teraz przechowuje USD. Numer `NNN` = następny wolny (po 021).

#### 2. Bump model_version

**File**: `config/cvs-weights.php`

**Intent**: Podbić `model_version` dla czystego rozdziału epoki snapshotów (semantyka ceny zmienia się na USD).

**Contract**: `'model_version' => '4.0'` (z 3.0; unika kolizji z shadow 3.1/3.2). FR-010 — czytane z configu, nie hardkodowane.

#### 3. Writer + reader

**Files**: `src/TrackRecord/SnapshotWriter.php`, `src/TrackRecord/CvsSnapshotRepository.php`

**Intent**: Persystować `fx_rate_to_usd`, `native_currency`, `native_price` (cena USD trafia do `price_at_snapshot`). Reader wystawia nowe pola w odczytach używanych przez dashboard/track-record.

**Contract**: `SnapshotWriter::persist()` / `CvsSnapshotRepository::save()` przyjmują i zapisują nowe pola (z `$financials`). INSERT/UPDATE w `save()` rozszerzone o 3 kolumny. Odczyty (`findAllLatest`, track-record) zwracają nowe pola.

#### 4. Track-record — filtr wersji (anti-mix natywna/USD)

**Files**: `src/TrackRecord/TrackRecordController.php`, `src/TrackRecord/TrackRecordRepository.php`

**Intent**: Track-record liczy zwrot `(price_now − price_then)/price_then` ([TrackRecordRepository.php:67-69](src/TrackRecord/TrackRecordRepository.php:67)). Po bumpie na 4.0 stare wiersze zagraniczne są natywne (KRW, 3.0), a nowe USD (4.0) — bez filtra wersji zwrot wychodzi garbage (np. (59−79500)/79500). Controller dziś woła odczyty BEZ `model_version` ([TrackRecordController.php:36,70](src/TrackRecord/TrackRecordController.php:36)) → `$modelVersion=null` → brak filtra. Przekazać live `model_version` z configu do odczytów, by porównywać tylko wiersze tej samej epoki (wzorzec `findAllLatest($liveVersion)`).

**Contract**: `TrackRecordController` wstrzykuje live `model_version` (z `config/cvs-weights.php`) do `getEvaluations()` i `getForTicker()`. Filtry `{$versionFilter}`/`{$latestVersionFilter}` w repo już istnieją — wystarczy przekazać niepusty argument. Skutek: track-record pokazuje tylko historię ≥ epoki 4.0.

### Success Criteria:

#### Automated Verification
- Migracja aplikuje się czysto na dev DB; 3 nowe kolumny w `cvs_snapshots`
- `php vendor/bin/phpstan analyse src/ --level=6 --no-progress` — 0 błędów
- `php -l database/migrations/NNN_add_currency_to_snapshots.sql` n/d — zamiast: SQL przegląd ręczny
- Test: `save()` persystuje fx_rate/native_currency/native_price; reader je zwraca
- Test/asercja: track-record odczyty wołane z live `model_version` (brak mieszania wersji)

#### Manual Verification
- Po pojedynczym rescore w dev: wiersz 000660.KS ma `model_version=4.0`, `native_currency=KRW`, `fx_rate_to_usd` i `native_price` wypełnione, `price_at_snapshot` w USD
- Track-record nie pokazuje skrajnych/garbage zwrotów dla zagranicznych (stare 3.0 natywne wykluczone filtrem wersji)

---

## Phase 4: Fair Value w USD + dual-currency display

### Overview
Usuwa guardy mismatch (wszystko już USD), liczy Fair Value w USD i wystawia natywną przez kurs. Szablon pokazuje cenę i FV dwuwalutowo (USD wiodące, natywna w nawiasie) z mapowaniem symbolu/kodu waluty.

### Changes Required:

#### 1. Fair value w USD

**Files**: `src/Ai/AiAnalysisController.php`, `templates/analysis.php`

**Intent**: Skoro finanse i cena są spójnie w USD, guard "skip on currency mismatch" jest zbędny — usunąć go i liczyć FV w USD. Natywny FV = FV_USD / `fx_rate_to_usd`. Sanity-bounds (0.05×–10×) zachować.

**Contract**: `calcFairPrice()` — usunięcie bloku [:199-204](src/Ai/AiAnalysisController.php:199); FV liczone na polach USD. Inline guard w `templates/analysis.php` [:273-283](templates/analysis.php:273) usunięty/uproszczony; FV renderowany dualnie.

#### 2. Dual-currency display

**File**: `templates/analysis.php`

**Intent**: Pokazać cenę bieżącą i Fair Value jako `$USD (NATYWNA)` gdy `native_currency` ≠ USD, inaczej samo USD. Usunąć zahardkodowany label `'Cena bieżąca ($)'`. Dodać mapę kod→symbol (fallback: kod waluty).

**Contract**: Helper mapujący `native_currency` → symbol (₩, €, ¥, …) z fallbackiem na kod; format `$59.20 (₩79,500)`. Pola `native_price`, `native_currency`, `fx_rate_to_usd` z `$financials`. Label `current_price` ([:548](templates/analysis.php:548)) bez sztywnego `$`.

### Success Criteria:

#### Automated Verification
- `php vendor/bin/phpstan analyse src/ --level=6 --no-progress` — 0 błędów
- `php -l templates/analysis.php` — 0 błędów
- `vendor/bin/phpunit` — pełny suite zielony

#### Manual Verification
- 000660.KS: cena i Fair Value pokazane jako `$… (₩…)`; brak znaku `$` przy liczbie KRW
- Spółka US (np. AAPL): pojedyncza waluta USD, bez nawiasu — brak regresji
- ADR (TSM): nie pokazuje błędnych liczb — Fair Value albo poprawny (gdy shares pasują do ceny ADR), albo świadomie suppress przez sanity-bounds (ryzyko ADR-ratio); score liczony na spójnych USD

---

## Phase 5: Rebuild peer medians + weryfikacja end-to-end

### Overview
Pełny rescore odbudowuje peer_medians pod nową `model_version` (okno cold-startu), po czym weryfikacja spójności USD w całej aplikacji. Faza głównie operacyjno-weryfikacyjna.

### Changes Required:

#### 1. Rebuild + weryfikacja

**Files**: (operacyjne — `bin/rescore.php` uruchomienie, bez zmian kodu o ile Fazy 1-4 wystarczą)

**Intent**: Po deployu Faz 1-4 uruchomić pełny rescore, aby odbudować peer_medians pod `4.0` i zapełnić snapshoty USD. Zweryfikować dashboard/screener/track-record.

**Contract**: Uruchomienie `bin/rescore.php` (CLI php82). Brak nowego kodu; jeśli weryfikacja ujawni lukę (np. reader nie zwraca pola), naprawa wraca do odpowiedniej fazy.

### Success Criteria:

#### Automated Verification
- `php vendor/bin/phpstan analyse src/ --level=6 --no-progress` — 0 błędów (regresja całości)
- `vendor/bin/phpunit` — pełny suite zielony

#### Manual Verification
- Po pełnym rescore: peer_medians mają wiersze pod `model_version=4.0`; ValuationPillar nie cold-startuje (źródło ≠ cold_start dla pokrytych sektorów)
- Dashboard chip i screener pokazują wyniki spójnie (USD), bez zdublowania wierszy
- Track-record 000660.KS: ceny historyczne spójne (USD od nowych wpisów)
- 000660.KS produkcyjnie: pełny dual-display, sensowny CVS i Fair Value

---

## Testing Strategy

### Unit Tests:
- `fetchFxRateToUsd`: USD→1.0 bez requestu; waluta obca→kurs; brak danych→null
- Inwariant bezwymiarowości: EV/FCF KRW == EV/FCF natywne (dowód, że konwersja nie psuje score)
- ADR: EV liczone spójnie w USD (brak miksu walut)
- Brak podwójnej konwersji `forward_fcf_est`
- Skip path: nie-USD bez kursu → null
- Regresja: CVSModelTest (US fixtures) niezmienione

### Integration Tests:
- `save()`→reader round-trip nowych pól waluty
- Pełny przepływ fetch→model→writer dla fixture KRW (offline, bez sieci)

### Manual Testing Steps:
1. Dev: analiza 000660.KS → dual-display ceny i FV, sensowny CVS
2. Dev: analiza AAPL → brak regresji (pojedyncza waluta)
3. Dev: analiza TSM (ADR) → FV się pokazuje, score nie zatruty
4. Symulacja braku kursu (mock) → spółka pomijana z komunikatem
5. Po rescore: peer_medians pod 4.0, brak cold-startu, brak dublowania w "latest"

## Performance Considerations

Jeden dodatkowy request chart per spółka nie-USD na pobranie kursu (cache per-waluta w sesji niweluje powtórzenia w obrębie analizy/rescore). Spółki US: zero dodatkowych requestów (kurs=1.0 bez fetchu). Rescore pełny po deployu jednorazowo cięższy (cold-start median) — zgodnie z istniejącym wzorcem bumpa wersji.

## Migration Notes

- Migracja `NNN` additive-only — istniejące wiersze 3.0 zostają nietknięte.
- Po deployu **natychmiast** uruchomić pełny rescore (Faza 5) — bump na 4.0 powoduje cold-start peer_medians i ukrycie starych "latest" do repopulacji.
- Backfillu brak — historyczne snapshoty zagraniczne pozostają w starej semantyce pod 3.0; nowe pod 4.0.
- **Track-record reset okna**: po przejściu na filtr live `model_version` (Faza 3 #4) track-record porównuje tylko wiersze 4.0 — pary ewaluacyjne odbudowują się przez ~horizon (30 dni) po bumpie. To świadomy koszt rozdziału epok (zapobiega mieszaniu cen natywnych 3.0 z USD 4.0).

## References

- Change: `context/changes/multi-currency-fx/change.md`
- Seam normalizacji: [FinancialDataFetcher.php:354](src/Api/FinancialDataFetcher.php:354)
- Determinizm seam (wzorzec): [FinancialDataFetcher.php:106](src/Api/FinancialDataFetcher.php:106)
- EV/FCF: [ValuationMetrics.php:80](src/CVS/Valuation/ValuationMetrics.php:80)
- Guardy walutowe: [AiAnalysisController.php:199](src/Ai/AiAnalysisController.php:199), [analysis.php:273](templates/analysis.php:273)
- model_version read: [CvsSnapshotRepository.php:229](src/TrackRecord/CvsSnapshotRepository.php:229)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: FX rate fetch + determinism seam

#### Automated
- [x] 1.1 PHPStan level 6 — 0 błędów
- [x] 1.2 Test: ticker USD → fx_rate_to_usd = 1.0, brak fetchu FX
- [x] 1.3 Test: waluta obca z kursem → fx_rate_to_usd + native_currency ustawione
- [x] 1.4 Test: nie-USD bez kursu → fetch/normalise zwraca null

#### Manual
- [ ] 1.5 Dev: 000660.KS nie wywala się, kurs KRW pobrany
- [ ] 1.6 Dev: spółka US bez zmian

### Phase 2: Konwersja w normalise()

#### Automated
- [ ] 2.1 PHPStan level 6 — 0 błędów
- [ ] 2.2 CVSModelTest zielony (US fixtures niezmienione)
- [ ] 2.3 Test-inwariant: EV/FCF KRW == natywne; pola w USD
- [ ] 2.4 Test ADR: EV spójnie w USD, brak miksu walut

#### Manual
- [ ] 2.5 000660.KS: CVS sensowny, surowe pola w USD
- [ ] 2.6 ADR (TSM): score nie zatruty, FV liczone

### Phase 3: Migracja snapshotów + writer/reader + bump wersji

#### Automated
- [ ] 3.1 Migracja aplikuje się czysto; 3 nowe kolumny
- [ ] 3.2 PHPStan level 6 — 0 błędów
- [ ] 3.3 Test: save() persystuje nowe pola; reader je zwraca
- [ ] 3.4 Track-record odczyty wołane z live model_version (brak mieszania wersji)

#### Manual
- [ ] 3.5 Po rescore w dev: 000660.KS ma model_version=4.0, native_currency/fx_rate/native_price, price USD
- [ ] 3.6 Track-record bez garbage zwrotów dla zagranicznych (stare 3.0 wykluczone)

### Phase 4: Fair Value w USD + dual-currency display

#### Automated
- [ ] 4.1 PHPStan level 6 — 0 błędów
- [ ] 4.2 php -l templates/analysis.php — 0 błędów
- [ ] 4.3 Pełny suite phpunit zielony

#### Manual
- [ ] 4.4 000660.KS: cena i FV jako `$… (₩…)`, brak `$` przy KRW
- [ ] 4.5 AAPL: pojedyncza waluta, brak regresji
- [ ] 4.6 TSM (ADR): brak błędnych liczb — FV poprawny lub świadomie suppress; score na USD

### Phase 5: Rebuild peer medians + weryfikacja end-to-end

#### Automated
- [ ] 5.1 PHPStan level 6 — 0 błędów (regresja całości)
- [ ] 5.2 Pełny suite phpunit zielony

#### Manual
- [ ] 5.3 Peer_medians pod 4.0; ValuationPillar nie cold-startuje
- [ ] 5.4 Dashboard/screener spójne USD, brak dublowania
- [ ] 5.5 Track-record 000660.KS spójny
- [ ] 5.6 000660.KS produkcyjnie: dual-display, sensowny CVS i FV
