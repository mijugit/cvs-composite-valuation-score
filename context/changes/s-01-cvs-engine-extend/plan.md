# CVS Engine — napraw Sektor (EV/FCF) + dodaj Momentum Implementation Plan

## Overview

Rozszerzamy istniejący 4-filarowy model CVS w PHP — nie przepisujemy go od zera.
Dwa filary są działające i pozostają bez zmian (Growth, Quality). Dwa wymagają naprawy:

- **SectorBenchmarkPillar** dziś zawsze zwraca score = 50 bo `sector_pe_median` itp.
  są hardkodowane jako `null`. Przepisujemy go na logikę EV/FCF z `cvs_analyze.py`
  (hardkodowane benchmarki sektorowe, sigmoida identyczna jak Python).
- **PriceHistoryPillar** (52-week percentile + 200MA) zastępujemy **MomentumPillar**
  (ROC 6M+3M vs SPY excess return) — ta sama logika co Filar 2 w Python v1.6.

Wagi 30/25/25/20, Quality Gate i CVSModel orchestration pozostają bez zmian (poza
drobnym swap nazwy klasy i klucza weight: `history` → `momentum`).

## Current State Analysis

**Co działa poprawnie:**
- `GrowthPillar` — YoY revenue growth vs 3-year CAGR, sigmoid normalizacja — bez zmian
- `FundamentalQualityPillar` — ROE, FCF margin, gross margin trend, NetDebt/EBITDA — bez zmian
- `QualityGate` — 4 kryteria binarne (gross margin, D/E, current ratio, positive revenue) — bez zmian
- `CVSModel` orchestracja, `CVSResult` value object, `AnalysisController` — bez zmian (poza swap)
- `FinancialDataFetcher` — cURL do Yahoo Finance v10, session cache — base jest dobra, wymaga rozszerzenia

**Co jest zepsute lub brakuje:**
- `FinancialDataFetcher`: brak `assetProfile` → brak pola `sector` w normalized output
- `FinancialDataFetcher`: brak `sharesOutstanding`, `forwardEps`, `trailingEps`,
  `revenueGrowth`, `earningsQuarterlyGrowth`, `grossMargins` (flat float) — potrzebne dla Sektora
- `FinancialDataFetcher`: brak chart endpoint → brak `monthly_closes` + `spy_closes` — potrzebne dla Momentum
- `SectorBenchmarkPillar`: odczytuje `sector_pe_median` etc. (zawsze null) → score zawsze 50
- `PriceHistoryPillar`: logika 52W/200MA — zastępujemy Momentum

**Kluczowe odkrycia:**
- `CVSModel` (line 64): klucz wag to `$this->config['weights']`, sub-klucze: `growth`, `sector`, `history`, `quality`
- Pillary są tworzone bez argumentów (`new SectorBenchmarkPillar()`). Po zmianie:
  SectorBenchmarkPillar i MomentumPillar potrzebują config — CVSModel musi je przekazać
- `FinancialDataFetcher::fetch()` → normalise() → 28 pól w tablicy; rozszerzamy tę tablicę o nowe pola
- `CVSModelTest.php` używa syntetycznej tablicy `baseFinancials()` — wymaga uzupełnienia o nowe pola

## Desired End State

Po zaimplementowaniu planu:
1. Analiza AAPL, MSFT, NVDA zwraca SectorBenchmarkPillar score **różny od 50** (prawdziwa wycena EV/FCF)
2. Analiza tych samych spółek zwraca MomentumPillar score **różny od 50** (prawdziwy ROC vs SPY)
3. `vendor/bin/phpunit --testdox` — wszystkie testy przechodzą
4. UI na dashboard pokazuje "Momentum" zamiast "Historia cenowa" jako etykietę pillar (c)
5. Wyniki na cvs.timeflow.fun po deploy są zgodne z oczekiwaniami

### Weryfikacja ręczna parity test:
Uruchom `python cvs_analyze.py AAPL` (Python v1.6) i zaloguj się do PHP app, wpisz AAPL.
Wyniki NIE muszą być identyczne (różna architektura pillarów: PHP 4-pillar, Python 3-pillar).
Sprawdź tylko: czy ta sama spółka wpada w ten sam próg rekomendacji (np. obie NEUTRALNIE)?
Duże rozbieżności (PHP SILNE KUPUJ vs Python UNIKAJ) wymagają diagnozy.

### Key Discoveries:

- `FinancialDataFetcher::MODULES` (line 28–35): nie zawiera `assetProfile` → sector = null
- `FinancialDataFetcher::normalise()` (line 146): płaska tablica, pola `sector_pe_median` etc.
  na końcu są hardkodowane jako `null` — można je usunąć lub zastąpić
- `CVSModel` (line 37–40): pillary bez konstruktor-argumentów; po zmianie SectorBenchmarkPillar
  i MomentumPillar muszą dostać config w konstruktorze
- `config/cvs-weights.php` (line 15–20): klucz `'history' => 0.25` → zmienić na `'momentum'`
- Yahoo Finance chart endpoint: `https://query1.finance.yahoo.com/v8/finance/chart/{ticker}?interval=1mo&range=3y`
- SPY cache: klucz `cvs_spy_closes` w `$_SESSION`, TTL = `cache_ttl` z config

## What We're NOT Doing

- Nie zmieniamy GrowthPillar (działa poprawnie)
- Nie zmieniamy FundamentalQualityPillar (działa poprawnie)
- Nie zmieniamy QualityGate ani jego progów
- Nie zmieniamy wag (30/25/25/20) ani progów CVS (72/58/42/28)
- Nie zmieniamy CVSResult, CVSResult::toArray(), disclaimera
- Nie zmieniamy AnalysisController ani tras w routes.php
- Nie zmieniamy AuthController, UserRepository, bazy danych
- Nie dodajemy radar chart (S-02) ani detail panel (S-03) — to osobne slice'y
- Nie robimy CI/CD — deploy jest ręczny przez git pull na CF

## Implementation Approach

Cztery zmiany w zdefiniowanej kolejności (każda faza jest testowalna przed następną):

1. **Data layer first** — rozszerzyć FinancialDataFetcher o nowe pola; pozostałe fazy
   zależą od tego że `$financials` ma te pola
2. **SectorBenchmarkPillar** — po tym SectorPillar zwraca rzeczywiste wartości
3. **MomentumPillar** — nowa klasa zastępuje PriceHistoryPillar
4. **Config + Tests + Porządki** — zaktualizować config, testy, CLAUDE.md, template

---

## Phase 1: FinancialDataFetcher — Data Layer Upgrade

### Overview

Rozszerzyć fetcher o: sektor spółki (`assetProfile`), pola potrzebne do obliczenia EV
(sharesOutstanding, forwardEps, trailingEps, revenueGrowth, earningsQuarterlyGrowth,
grossMargins), i miesięczne ceny zamknięcia dla Momentum (chart endpoint + SPY).

### Changes Required:

#### 1. Dodaj `assetProfile` do modułów quoteSummary

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Dołącz `'assetProfile'` do tablicy `MODULES` (line 28–35). Moduł ten dostarcza
pole `sector` (string, np. `"Technology"`) i `industry` — potrzebne do lookup benchmarku
sektorowego w SectorBenchmarkPillar.

**Contract**: `self::MODULES` rozszerzyć o `'assetProfile'` jako ostatni element.
W `normalise()`: `$ap = $raw['assetProfile'] ?? [];` i odczytaj `$ap['sector']`.

#### 2. Dodaj nowe pola skalarne do `normalise()`

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Dołącz do zwracanej tablicy nowe pola potrzebne dla SectorBenchmarkPillar:
EV components (sharesOutstanding, gross_margins) i growth metrics (forwardEps, trailingEps,
revenueGrowth, earningsQuarterlyGrowth). Sektor też tu trafia.

**Contract**: Źródła z Yahoo Finance JSON (moduł → ścieżka):

| Pole w normalized output | Moduł Yahoo Finance | Ścieżka JSON |
|---|---|---|
| `sector` | `assetProfile` | `assetProfile.sector` (string, bez `{'raw':...}` wrappera) |
| `shares_outstanding` | `defaultKeyStatistics` | `defaultKeyStatistics.sharesOutstanding.raw` |
| `gross_margins` | `financialData` | `financialData.grossMargins.raw` (float 0–1) |
| `forward_eps` | `defaultKeyStatistics` | `defaultKeyStatistics.forwardEps.raw` |
| `trailing_eps` | `defaultKeyStatistics` | `defaultKeyStatistics.trailingEps.raw` |
| `revenue_growth` | `financialData` | `financialData.revenueGrowth.raw` |
| `earnings_quarterly_growth` | `defaultKeyStatistics` | `defaultKeyStatistics.earningsQuarterlyGrowth.raw` |

Wszystkie pola przez helper `$v()` (nullable float), z wyjątkiem `sector` który jest stringiem:
`$raw['assetProfile']['sector'] ?? 'DEFAULT'`.

Pola `sector_pe_median`, `sector_ps_median`, `sector_ev_ebitda_median` (linie 225–227) usunąć
z normalized output — nie będą już używane.

#### 3. Dodaj metodę `fetchChartData()` + pobieranie SPY

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Prywatna metoda `fetchChartData(string $ticker, string $range): array` wywołuje
Yahoo Finance chart endpoint i zwraca tablicę miesięcznych cen zamknięcia (float[], newest last).
Jeśli wywołanie się nie uda — zwraca `[]` (graceful degradation: Momentum zwróci 50).

SPY jest benchmarkiem wspólnym dla wszystkich tickerów w sesji — cache go osobno pod kluczem
`cvs_spy_closes` z TTL = `cache_ttl`. Pobieranie SPY jest leniwe: przy pierwszym tickerze
w sesji, nie przy każdym.

**Contract**:
- Chart endpoint: `https://query1.finance.yahoo.com/v8/finance/chart/{ticker}?interval=1mo&range={range}`
- Odpowiedź JSON: `chart.result[0].indicators.quote[0].close` — tablica float lub null per slot
- Filtruj null values: `array_values(array_filter($closes, fn($c) => $c !== null))`
- SPY range: `'1y'`; ticker range: `'3y'`
- Osobne cURL call (bez modułów quoteSummary), ten sam User-Agent, timeout = `timeout_seconds`

#### 4. Wpleć `monthly_closes` i `spy_closes` do `normalise()` i `fetch()`

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Dodaj pola `monthly_closes` i `spy_closes` do tablicy zwracanej przez `normalise()`.
Chart data jest pobierana w `fetch()` przed/po `callApi()` i przekazywana do `normalise()`.
Cachować razem z resztą danych tickera (ten sam klucz sesji `cvs_fin_{ticker}`).

**Contract**: Sygnatura `normalise()` rozszerzyć: `normalise(array $raw, array $monthlyCloses, array $spyCloses): ?array`.
`fetch()` woła `fetchChartData($ticker, '3y')`, `fetchSpyCloses()` (lazy), przekazuje do normalise.
Wynikowe pola:
```
'monthly_closes' => float[],  // up to 36 monthly closes, oldest first
'spy_closes'     => float[],  // up to 12 monthly SPY closes, oldest first
```

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit --testdox` — wszystkie istniejące testy przechodzą
- `php -S localhost:8000 -t public` startuje bez błędów PHP

#### Manual Verification:

- Zaloguj się, wpisz AAPL, kliknij "Analiza" → w DevTools Network response JSON zawiera
  `sector: "Technology"` (nie null/DEFAULT) dla AAPL
- Response zawiera `monthly_closes` z ≥7 elementami (wystarczy do Momentum)
- Response zawiera `spy_closes` z ≥7 elementami

**Implementation Note**: Zweryfikuj ręcznie że `assetProfile` moduł jest dostępny i zwraca
sector dla AAPL przed przejściem do Phase 2. Yahoo Finance może wymagać `crumb` — jeśli 403,
sprawdź czy istniejące wywołanie quoteSummary działa (wspólny endpoint; crumb jest opcjonalny
dla publicznych danych).

---

## Phase 2: SectorBenchmarkPillar — Rewrite (EV/FCF vs Sektor)

### Overview

Kompletne przepisanie logiki SectorBenchmarkPillar. Stara logika (P/E, P/S, EV/EBITDA vs null
mediany) jest usuwana. Nowa logika: EV/FCF (Wariant A gdy FCF > 0) lub EV/Sales (Wariant B)
vs hardkodowane benchmarki sektorowe — identyczne z Python `calc_relative()`.

### Changes Required:

#### 1. Przepisz `SectorBenchmarkPillar.php`

**File**: `src/CVS/Pillars/SectorBenchmarkPillar.php`

**Intent**: Zastąp istniejące porównania P/E/P/S/EV/EBITDA nową logiką EV/FCF. Klasa otrzymuje
benchmarki w konstruktorze (`array $benchmarks` — zawartość `config['benchmarks']`). Metoda
`score(array $financials): float` zwraca wynik 0–100.

**Contract**: Algorytm wzorowany dokładnie na Python `calc_relative()`:

```
1. Wyciągnij sektor: $financials['sector'] → lookup w $this->benchmarks
   Fallback: $this->benchmarks['DEFAULT'] gdy sektor nieznany
   Jeśli $this->benchmarks jest pusty → return 50.0 (graceful)

2. Oblicz EV:
   EV = current_price × shares_outstanding + total_debt - cash
   Jeśli shares_outstanding null → return 50.0

3. Wyciągnij forward growth (metoda pomocnicza extractForwardGrowth($financials)):
   a. Spróbuj: ($forward_eps / $trailing_eps - 1) × 100
      → Jeśli > 200: ustaw flagę EARNINGS_BASE_EFFECT, pomiń
      → Jeśli / revenue_growth > 3.5: ustaw flagę EARNINGS_EPS_REVENUE_GAP, pomiń
      → W przeciwnym razie: użyj tej wartości
   b. Fallback: revenue_growth × 100 (gdy > 0)
   c. Fallback: earnings_quarterly_growth × 100 (gdy > 0 i ≤ 200)
   d. Jeśli żadne: return 50.0 (brak danych wzrostu → neutral)
   Cap growth do $bm['max_growth']

4. Wariant A (gdy free_cash_flow > 0):
   forward_fcf = free_cash_flow × (1 + growth/100)²
   ev_fcf      = EV / forward_fcf
   ratio       = ev_fcf / $bm['median_ev_fcf']
   score       = sigmoid(ratio)  // k=3

5. Wariant B (gdy FCF ≤ 0 lub null):
   fwd_sales   = revenue × (1 + growth/100)²
   ev_sales    = EV / fwd_sales
   adjusted    = ev_sales / max(growth × (gross_margins), 0.001)
   target      = bm['median_ev_sales'] / max((bm['max_growth']/2) × bm['median_gm']/100, 0.001)
   ratio       = adjusted / max(target, 0.01)
   score       = sigmoid(ratio)  // k=3

Sigmoid: 100.0 / (1.0 + exp(3.0 × (ratio − 1.0)))
Wynik clampowany do [0, 100].
```

#### 2. Zaktualizuj `CVSModel` — inject benchmarks do SectorBenchmarkPillar

**File**: `src/CVS/CVSModel.php`

**Intent**: SectorBenchmarkPillar teraz wymaga `array $benchmarks` w konstruktorze.
Przekaż `$config['benchmarks'] ?? []` przy jego tworzeniu.

**Contract**: Zmień linię 38:
```php
// było:
$this->sector = new SectorBenchmarkPillar();
// po zmianie:
$this->sector = new SectorBenchmarkPillar($config['benchmarks'] ?? []);
```

#### 3. Dodaj sekcję `benchmarks` do `config/cvs-weights.php`

**File**: `config/cvs-weights.php`

**Intent**: Dodaj tablicę `'benchmarks'` portując BENCHMARKS dict z Python `cvs_analyze.py`.
To jest source of truth dla progów sektorowych EV/FCF, EV/Sales, median marż.

**Contract**: Dodaj jako nową sekcję (np. po `'quality_gate'`):
```php
'benchmarks' => [
    'Technology'             => ['median_ev_fcf' => 32, 'median_ev_sales' => 8.0,  'median_gm' => 55, 'max_growth' => 60],
    'Healthcare'             => ['median_ev_fcf' => 28, 'median_ev_sales' => 5.0,  'median_gm' => 60, 'max_growth' => 30],
    'Communication Services' => ['median_ev_fcf' => 22, 'median_ev_sales' => 4.0,  'median_gm' => 50, 'max_growth' => 25],
    'Consumer Cyclical'      => ['median_ev_fcf' => 20, 'median_ev_sales' => 1.5,  'median_gm' => 35, 'max_growth' => 20],
    'Consumer Defensive'     => ['median_ev_fcf' => 18, 'median_ev_sales' => 1.0,  'median_gm' => 40, 'max_growth' =>  8],
    'Industrials'            => ['median_ev_fcf' => 20, 'median_ev_sales' => 2.0,  'median_gm' => 35, 'max_growth' => 12],
    'Energy'                 => ['median_ev_fcf' => 12, 'median_ev_sales' => 1.5,  'median_gm' => 30, 'max_growth' => 15],
    'Basic Materials'        => ['median_ev_fcf' => 14, 'median_ev_sales' => 2.0,  'median_gm' => 35, 'max_growth' => 12],
    'Real Estate'            => ['median_ev_fcf' => 22, 'median_ev_sales' => 8.0,  'median_gm' => 55, 'max_growth' => 10],
    'Utilities'              => ['median_ev_fcf' => 14, 'median_ev_sales' => 2.0,  'median_gm' => 30, 'max_growth' =>  5],
    'Financial Services'     => ['median_ev_fcf' => 18, 'median_ev_sales' => 3.0,  'median_gm' => 70, 'max_growth' => 12],
    'DEFAULT'                => ['median_ev_fcf' => 20, 'median_ev_sales' => 3.0,  'median_gm' => 40, 'max_growth' => 20],
],
```

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit --testdox` — testy przechodzą (testy QG i growth są nienaruszone)

#### Manual Verification:

- Analiza AAPL (`sector: "Technology"`) → `pillar_breakdown.sector` ≠ 50.0
- Analiza AAPL z FCF > 0 → wariant A (EV/FCF): score powinien być < 50 (AAPL wyceniane
  z premią do mediany Technology)
- Analiza spółki bez FCF (np. PLTR) → wariant B (EV/Sales) bez błędu PHP

---

## Phase 3: MomentumPillar — Zastąp PriceHistoryPillar

### Overview

Utwórz nowy plik `MomentumPillar.php` z logiką ROC 6M+3M vs SPY (Python Filar 2).
Usuń `PriceHistoryPillar.php`. Zaktualizuj CVSModel i config.

### Changes Required:

#### 1. Utwórz `MomentumPillar.php`

**File**: `src/CVS/Pillars/MomentumPillar.php` (NOWY PLIK)

**Intent**: Nowy pillar obliczający momentum cenowe spółki względem rynku (SPY).
Klasa otrzymuje config momentum w konstruktorze. Metoda `score(array $financials): float`.

**Contract**: Algorytm identyczny z Python `calc_momentum()`:

```
$closes    = $financials['monthly_closes']  // float[]
$spyCloses = $financials['spy_closes']      // float[]
$n         = count($closes)

Jeśli $n < 7 → return 50.0  // za mała historia, neutral

$now  = $closes[$n-1]
$m6   = $closes[max(0, $n-7)]
$m3   = $closes[max(0, $n-4)]
$roc6m = ($now / $m6 - 1) * 100
$roc3m = ($now / $m3 - 1) * 100
$composite = 0.6 * $roc6m + 0.4 * $roc3m

$spyCalib = 15.0  // domyślny fallback gdy brak SPY danych
$sn = count($spyCloses)
Jeśli $sn >= 7:
    $sNow = $spyCloses[$sn-1]
    $s6m  = $spyCloses[max(0, $sn-7)]
    $s3m  = $spyCloses[max(0, $sn-4)]
    $spyCalib = 0.6*($sNow/$s6m-1)*100 + 0.4*($sNow/$s3m-1)*100

$excess    = $composite - $spyCalib
$normRatio = 1.0 - ($excess / $cfg['normalization_divisor'])  // cfg['normalization_divisor'] = 40.0
$score     = sigmoid($normRatio)  // k=3
$score     = max($cfg['score_min'], min($cfg['score_max'], $score))  // cap [5.0, 95.0]
```

Sigmoid prywatna metoda klasy: `100.0 / (1.0 + exp(3.0 * ($ratio - 1.0)))`.

Namespace: `CVS\CVS\Pillars`.

#### 2. Usuń `PriceHistoryPillar.php`

**File**: `src/CVS/Pillars/PriceHistoryPillar.php` (DELETE)

**Intent**: Pillar zastępowany przez MomentumPillar. Plik do usunięcia.

**Contract**: `git rm src/CVS/Pillars/PriceHistoryPillar.php`

#### 3. Zaktualizuj `CVSModel.php` — swap klasy i klucza

**File**: `src/CVS/CVSModel.php`

**Intent**: Zastąp `PriceHistoryPillar` przez `MomentumPillar` we właściwości, imporcie i
konstruktorze. Zmień klucz w `$pillarScores` z `'history'` na `'momentum'`.

**Contract**: Cztery miejsca do zmiany:
1. `use` (line 9): `use CVS\CVS\Pillars\MomentumPillar;` zamiast `PriceHistoryPillar`
2. Właściwość (line 30): `private MomentumPillar $momentum;` zamiast `PriceHistoryPillar $history`
3. Konstruktor (line 39): `$this->momentum = new MomentumPillar($config['momentum'] ?? []);`
4. `$pillarScores` (line 69): klucz `'momentum'` + `$this->momentum->score($financials)`;
   weight lookup (line 75): `$w['momentum']`

#### 4. Zaktualizuj `config/cvs-weights.php` — rename + dodaj momentum config

**File**: `config/cvs-weights.php`

**Intent**: Przemianuj klucz wagi `'history'` na `'momentum'`. Dodaj sekcję `'momentum'`
z parametrami dla MomentumPillar.

**Contract**: Dwie zmiany:

a) W sekcji `'weights'`:
```php
'momentum'   => 0.25, // (c) Price momentum vs market (ROC 6M+3M vs SPY)
// usuń: 'history' => 0.25,
```

b) Nowa sekcja (np. po `'data_source'`):
```php
'momentum' => [
    'normalization_divisor' => 40.0,   // excess return divisor (matches Python)
    'score_min'             => 5.0,    // floor score
    'score_max'             => 95.0,   // ceiling score
],
```

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit --testdox` — wszystkie testy przechodzą
- `php -l src/CVS/Pillars/MomentumPillar.php` — brak syntax errors

#### Manual Verification:

- Analiza MSFT → `pillar_breakdown.momentum` ≠ 50.0 (gdy dostępna historia cen)
- Analiza spółki z bardzo dobrym momentum (np. NVDA w trend wzrostowym) → score > 50
- Analiza spółki z bardzo słabym momentum → score < 50

---

## Phase 4: Config Finalization + Tests + Porządki

### Overview

Uzupełnić `baseFinancials()` w testach o nowe pola, zaktualizować istniejące testy
pod nową architekturę, poprawić przestarzałą notatkę w CLAUDE.md, zaktualizować etykietę
pillar w szablonie.

### Changes Required:

#### 1. Zaktualizuj `tests/CVS/CVSModelTest.php` — fixture + testy

**File**: `tests/CVS/CVSModelTest.php`

**Intent**: Metoda `baseFinancials()` musi zawierać nowe pola wymagane przez
SectorBenchmarkPillar i MomentumPillar, inaczej testy będą produkować notice/warning
lub nieprawidłowe wyniki. Istniejące testy QG i disclaimer zostają bez zmian (nie dotykamy QG).

**Contract**: Rozszerz `baseFinancials()` o nowe pola ze sensownymi wartościami testowymi:

```php
// Nowe pola dla SectorBenchmarkPillar
'sector'                     => 'Technology',
'shares_outstanding'         => 15_000_000_000.0,  // 15B shares
'gross_margins'              => 0.45,               // 45%
'forward_eps'                => 7.0,
'trailing_eps'               => 6.0,
'revenue_growth'             => 0.10,              // 10%
'earnings_quarterly_growth'  => null,

// Nowe pola dla MomentumPillar (7 miesięcznych cen = minimum dla obliczeń)
'monthly_closes' => [140.0, 145.0, 150.0, 148.0, 155.0, 160.0, 162.0],
'spy_closes'     => [430.0, 432.0, 435.0, 433.0, 438.0, 440.0, 442.0],
```

Dodaj nowe testy:
- `test_sector_pillar_returns_non_neutral_score()` — z poprawnymi danymi EV/FCF sprawdź
  że SectorBenchmarkPillar nie zwraca 50.0
- `test_momentum_pillar_returns_non_neutral_score()` — z minimum 7 cenami sprawdź
  że MomentumPillar nie zwraca 50.0
- `test_momentum_pillar_returns_neutral_when_insufficient_history()` — gdy
  `monthly_closes` ma < 7 elementów, Momentum = 50.0
- `test_sector_pillar_returns_neutral_when_no_growth_data()` — gdy growth = null
  i brak revenueGrowth, SectorBenchmarkPillar zwraca 50.0

Zachowaj istniejące testy: QualityGate failures, CVS range [0,100], determinism, disclaimer, strong_buy threshold.

#### 2. Zaktualizuj `CLAUDE.md` — usuń przestarzałą notatkę

**File**: `CLAUDE.md`

**Intent**: Usunąć lub zaktualizować notatkę "**Sector medians may be `null`.**
`SectorBenchmarkPillar` returns neutral 50 when `sector_pe_median` etc. are null"
— po zmianie to nie jest już prawdą ani oczekiwane zachowanie.

**Contract**: Zastąp ten akapit:
```
// było:
- **Sector medians may be `null`.** `SectorBenchmarkPillar` returns neutral 50
  when `sector_pe_median` etc. are null — this is expected, not an error.
  Yahoo Finance doesn't expose sector medians directly.

// po zmianie:
- **SectorBenchmarkPillar uses hardcoded sector benchmarks** (EV/FCF medians from
  `config/cvs-weights.php → benchmarks`). Returns neutral 50 only when growth data
  is unavailable or `shares_outstanding` is null. `Financial Services` and
  `Real Estate` sectors work but have lower model accuracy.
```

#### 3. Zaktualizuj `templates/analysis.php` — etykieta pillar Momentum

**File**: `templates/analysis.php`

**Intent**: Etykieta pillar w tabeli wyników zmienia się z "Historia cenowa" na "Momentum".
Klucz w `$result['pillar_breakdown']` zmienia się z `'history'` na `'momentum'`.

**Contract**: W tabeli pillar breakdown (lines ~35–40) zmień:
- Klucz tablicy: `$result['pillar_breakdown']['history']` → `$result['pillar_breakdown']['momentum']`
- Etykieta: `"Historia cenowa"` → `"Momentum"`

Sprawdź też `public/js/app.js` — jeśli renderuje pillar breakdown w JS, analogiczna zmiana klucza.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit --testdox` — wszystkie testy przechodzą, w tym nowe 4 testy
- `php -S localhost:8000 -t public` startuje bez errors/warnings

#### Manual Verification:

- Dashboard pokazuje wyniki z 4 pillarami: "Wzrost", "Wycena sektorowa", **"Momentum"**, "Jakość"
- Disclaimer widoczny przy każdym wyniku
- Analiza AAPL: wszystkie 4 pillar scores są różne od siebie i żaden nie jest stały 50

---

## Phase 5: Weryfikacja lokalna + Deploy na Cyber_Folks

### Overview

Pełny test lokalny, parity check vs Python, commit i deploy na produkcję.

### Changes Required:

#### 1. Test lokalny end-to-end

**File**: (brak zmian kodu, weryfikacja)

**Intent**: Uruchomić aplikację lokalnie, przejść cały flow user story US-01, zweryfikować
że wszystkie zmiany działają razem.

**Contract**: Kroki weryfikacji manualnej (patrz Manual Verification poniżej).

#### 2. Parity check vs Python

**File**: (brak zmian kodu)

**Intent**: Uruchomić Python `cvs_analyze.py` dla AAPL i porównać z PHP endpoint.
Wyniki nie muszą być identyczne (różna architektura pillarów).

**Contract**: Uruchom:
```bash
python "C:\Users\Michał\.claude\skills\cvs-analyze\scripts\cvs_analyze.py" AAPL
```
PHP: zaloguj się → wpisz AAPL → uruchom analizę.
Porównaj zakres rekomendacji (próg CVS). Akceptowalne: ta sama etykieta (np. oba NEUTRALNIE)
lub jedna różnica progu (NEUTRALNIE vs AKUMULUJ). Nieakceptowalne: SILNE KUPUJ vs UNIKAJ
bez wyjaśnienia.

#### 3. Commit + Push + Deploy na Cyber_Folks

**File**: `git` + SSH na CF

**Intent**: Wrzucić zmiany do repo i zaktualizować serwer produkcyjny.

**Contract**:
```bash
git add -A
git commit -m "feat: extend CVS engine — EV/FCF sector + Momentum vs SPY pillar"
git push origin main
# Następnie SSH na CF lub /MiJu-CF-Deploy
```
Na CF: `git pull origin main` + `COMPOSER_MEMORY_LIMIT=-1 /usr/local/bin/php82 composer.phar install --no-dev`
(vendor/ jest w .gitignore — tylko jeśli zmieniły się zależności, czyli nie w tym przypadku).

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit --testdox` — wszystkie testy przechodzą (zielone)
- `git push` zakończony sukcesem
- `curl -I https://cvs.timeflow.fun/` → HTTP 200

#### Manual Verification:

1. `php -S localhost:8000 -t public` → otwórz `http://localhost:8000`
2. Zarejestruj konto lub zaloguj się
3. Wpisz: `AAPL MSFT NVDA` → "Analiza"
4. Wyniki pokazują 3 spółki posortowane CVS malejąco
5. Każda spółka ma 4 pillar scores — **żaden nie jest stały 50** (weryfikuje że Sektor i Momentum działają)
6. Etykiety pillarów: Wzrost / Wycena sektorowa / Momentum / Jakość
7. Disclaimer widoczny przy każdym wyniku
8. Wejdź na cvs.timeflow.fun i powtórz kroki 3–7 na produkcji po deploy

---

## Testing Strategy

### Unit Tests:

- `test_quality_gate_fails_on_zero_revenue()` — zachowany
- `test_quality_gate_fails_on_high_debt()` — zachowany
- `test_cvs_is_between_0_and_100()` — zachowany
- `test_same_input_always_produces_same_cvs()` — zachowany (determinism)
- `test_toArray_contains_disclaimer()` — zachowany
- `test_strong_buy_threshold()` — zachowany
- `test_sector_pillar_returns_non_neutral_score()` — NOWY
- `test_momentum_pillar_returns_non_neutral_score()` — NOWY
- `test_momentum_pillar_returns_neutral_when_insufficient_history()` — NOWY
- `test_sector_pillar_returns_neutral_when_no_growth_data()` — NOWY

### Manual Testing Steps:

1. Zaloguj się do lokalnej aplikacji
2. Wpisz `AAPL MSFT NVDA` → Analiza
3. Sprawdź w DevTools response body — `pillar_breakdown.sector` ≠ 50, `pillar_breakdown.momentum` ≠ 50
4. Sprawdź `sector` pole w response — powinno być `"Technology"` dla AAPL
5. Sprawdź `monthly_closes` w response — ≥ 7 elementów
6. Powtórz z tickerem `BABA` (Chinese stock → CURRENCY_MISMATCH flag should appear gracefully)
7. Wpisz nieistniejący ticker `XYZNOTEXIST` → graceful error, bez 500

## Performance Considerations

Każdy ticker wymaga teraz 2 cURL calls zamiast 1 (quoteSummary + chart endpoint).
SPY jest cachowany per session (jeden call na sesję, nie per ticker).
Czas odpowiedzi dla 10 tickerów: ~20–25s przy pierwszym zapytaniu (cache cold),
~0.1s przy kolejnych (session cache). Guardrail PRD: < 30s p95 → spełniony.

## References

- Python source of truth: `~/.claude/skills/cvs-analyze/scripts/cvs_analyze.py` (v1.6)
- Roadmap S-01: `context/foundation/roadmap.md`
- CLAUDE.md: reguły projektu (CSRF, auth guard, determinism, disclaimer)
- Mapping yfinance → Yahoo Finance API: `context/foundation/roadmap.md § S-01 ¶ 1`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: FinancialDataFetcher — Data Layer Upgrade

#### Automated

- [x] 1.1 `vendor/bin/phpunit --testdox` — testy przechodzą po Phase 1 — 7044a38

#### Manual

- [x] 1.2 Response JSON zawiera `sector: "Technology"` dla AAPL — 7044a38
- [x] 1.3 Response zawiera `monthly_closes` z ≥ 7 elementami — 7044a38
- [x] 1.4 Response zawiera `spy_closes` z ≥ 7 elementami — 7044a38

### Phase 2: SectorBenchmarkPillar Rewrite

#### Automated

- [x] 2.1 `vendor/bin/phpunit --testdox` — testy przechodzą — e8314cb

#### Manual

- [x] 2.2 `pillar_breakdown.sector` ≠ 50.0 dla AAPL (Technology, FCF > 0) — e8314cb
- [x] 2.3 Wariant B działa bez błędu dla spółki bez FCF — e8314cb

### Phase 3: MomentumPillar

#### Automated

- [x] 3.1 `vendor/bin/phpunit --testdox` — testy przechodzą
- [x] 3.2 `php -l src/CVS/Pillars/MomentumPillar.php` — brak syntax errors

#### Manual

- [x] 3.3 `pillar_breakdown.momentum` ≠ 50.0 dla MSFT lub NVDA

### Phase 4: Config + Tests + Porządki

#### Automated

- [ ] 4.1 `vendor/bin/phpunit --testdox` — wszystkie testy łącznie z 4 nowymi przechodzą
- [ ] 4.2 `php -S localhost:8000 -t public` startuje bez errors

#### Manual

- [ ] 4.3 Etykieta "Momentum" widoczna w UI zamiast "Historia cenowa"
- [ ] 4.4 Disclaimer widoczny przy każdym wyniku
- [ ] 4.5 AAPL + MSFT + NVDA: żaden z 4 pillar scores nie jest stały 50

### Phase 5: Weryfikacja lokalna + Deploy

#### Automated

- [ ] 5.1 `vendor/bin/phpunit --testdox` — zielone
- [ ] 5.2 `git push` zakończony sukcesem
- [ ] 5.3 `curl -I https://cvs.timeflow.fun/` → HTTP 200

#### Manual

- [ ] 5.4 Parity check: PHP i Python w tym samym przybliżonym progu rekomendacji dla AAPL
- [ ] 5.5 Produkcja cvs.timeflow.fun — analiza AAPL MSFT NVDA działa bez błędów
- [ ] 5.6 Ticker nieistniejący → graceful error bez 500
