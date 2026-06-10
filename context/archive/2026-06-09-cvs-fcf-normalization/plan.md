# CVS FCF Normalization Implementation Plan

## Overview

`ValuationPillar` currently wycenia spółkę jako `EV / forward_fcf`, gdzie
`forward_fcf = trailing_fcf × (1 + growthPct)²`. Dla spółek w trough capex-cycle
(case MU/Micron: HBM capex depresjonuje FCF) trailing_fcf jest sztucznie niski,
a growth-cap z powodu base-effect (epsFraction > 2.0 → fallback na revenue_growth)
uniemożliwia właściwą projekcję. Efekt: pillar każe fałszywą „drогość" zawyżonym
EV/FCF, choć analitycy widzą silne odbicie EPS.

FR-011: zastąpić trailing_fcf analitycznym `forward_fcf_est` = `forward_eps × (trailing_fcf / trailing_eps)` —
jednoletnia estymata FCF z danych analityków, używana bezpośrednio jako mianownik
(bez dodatkowego `(1+g)²` — forward_eps jest już 1-roczny forward).

## Current State Analysis

- `ValuationMetrics::forwardEvFcf($financials, $growthPct)` — `src/CVS/Valuation/ValuationMetrics.php:106–118` —
  jedyne miejsce obliczania forward EV/FCF. Statyczna metoda, czyste funkcje.
- `ValuationPillar::scoreVariantA()` (peer-group, line 125) i odpowiednik w trybie legacy —
  oba wywołują `ValuationMetrics::forwardEvFcf()`. Żaden z nich nie przekazuje config
  poza benchmarks.
- `FinancialDataFetcher::normalise()` — `src/Api/FinancialDataFetcher.php:425–508` —
  buduje tablicę `$financials`. Zawiera `forward_eps` (line 481) i `trailing_eps` (line 482)
  oraz `free_cash_flow` = `$fcfEffective` (line 465, już po capex-heavy korekcie S-05).
  Brak pola `forward_fcf_est`.
- `config/cvs-weights.php` — brak sekcji `'valuation'`. Nowe klucze config muszą tu trafić
  (FR-010: zero hardkodu w logice biznesowej).
- `CVSModel::__construct()` — buduje `ValuationPillar` z `$config['benchmarks']` i resolverem
  (lines 59/66). Nie przekazuje `$config['valuation']` (sekcja jeszcze nie istnieje).
- `PeerMedianRepository` (batch crawl peer-median) wywołuje `ValuationMetrics::forwardEvFcf()`
  bezpośrednio z dwoma argumentami — zmiana z opcjonalnym trzecim param nie wpłynie na tę ścieżkę.
- Yahoo Finance nie dostarcza forward FCF wprost. Jedyna estymata możliwa z dostępnych danych:
  `forward_fcf_est = forward_eps × (trailing_fcf / trailing_eps)`.

## Desired End State

Po wdrożeniu:
- Spółka w trough capex-cycle (niski `trailing_fcf`, wysoki `forward_eps`) otrzymuje poprawiony
  score wyceny — `forward_fcf_est` jest wyższy niż `trailing_fcf × (1+g)²` (bo base-effect cap
  tłumi g), więc EV/FCF ratio spada, score wyceny rośnie.
- Spółki bez `forward_eps` / poza granicami ratio → niezmienizone zachowanie (fallback do
  dotychczasowej formuły).
- Wszystkie progi/flagi FCF normalizacji w `config/cvs-weights.php` (FR-010).
- PHPStan czysty, pełna suita testów zielona, `model_version` zostaje `'3.0'` (bump przy rekalibracji — plaster 4).

### Key Discoveries

- `ValuationMetrics::forwardEvFcf()` ma dwa argumenty: `$financials` i `$growthPct`. Rozszerzenie
  o opcjonalny trzeci `?float $fwdFcfEst = null` jest backward-compatible — wszystkie obecne
  callery (ValuationPillar peer-group, ValuationPillar legacy, PeerMedianRepository) przekazują
  tylko dwa i nie zmienią zachowania.
- Sekcja `'valuation'` w config nie istnieje → plan dodaje ją obok `'overlays'` / `'earnings_guard'`.
- `CVSModel` buduje `ValuationPillar` w dwóch miejscach (peer-group i legacy) — oba wymagają
  aktualizacji żeby przekazać nowy config.
- `FinancialDataFetcher` konstruktor przyjmuje `$config['data_source']`, nie pełny config.
  Obliczamy `forward_fcf_est` bezwarunkowo w `normalise()` (czysta matematyka, bez config).
  Decyzja o użyciu (flaga + bounds check) należy do `ValuationPillar` który ma config.
- `free_cash_flow` w `$financials` to już `$fcfEffective` (po korekcie capex-heavy S-05).
  `forward_fcf_est` powinien używać `$fcfEffective`, nie raw `$fcf` — spójność mianownika.

## What We're NOT Doing

- Nie dotykamy peer-group median DB (`peer_medians`) — mediany benchmarkowe pozostają oparte
  na trailing FCF. Efektem jest świadoma asymetria: company EV/forward_fcf_est vs peer-median
  oparty na trailing — to jest POŻĄDANE (MU wygląda taniej niż peers, bo forward_fcf_est > trailing).
- Nie bumpujemy `model_version` — to nastąpi przy rekalibracji skali (plaster 4).
- Nie dodajemy nowych modułów Yahoo Finance — dane `forward_eps` i `trailing_eps` są już
  w MODULES (defaultKeyStatistics).
- Nie zmieniamy Variant B (EV/Sales) — tylko Variant A (FCF > 0).
- Nie zmieniamy `QualityPillar` ani `MomentumPillar`.
- Nie modyfikujemy `CVSResult`, overlay layer ani żadnych szablonów.
- Nie dodajemy nowych kolumn DB ani migracji.

## Implementation Approach

Trzy fazy w kolejności zależności:

1. **Config + pochodna w normalise()** — fundamenty: nowa sekcja config + pole
   `forward_fcf_est` w `$financials`.
2. **Logika w ValuationMetrics + ValuationPillar + CVSModel** — podłączenie: ValuationPillar
   dostaje nowy config, stosuje bounds-check i przekazuje `forward_fcf_est` do
   `forwardEvFcf()` gdy warunki spełnione.
3. **Testy** — weryfikacja MU-style trough, brak regresji dla zdrowej spółki, fallback gdy null.

## Critical Implementation Details

**Bounds check należy do ValuationPillar, nie normalise().**
`FinancialDataFetcher` nie ma dostępu do `$config['valuation']` (konstruktor przyjmuje
`$config['data_source']`). Dlatego `normalise()` zawsze oblicza `forward_fcf_est` gdy dane
dostępne (czysta matematyka), a `ValuationPillar` sprawdza bounds `[ratio_min, ratio_max]`
przed przekazaniem do `forwardEvFcf()`. Ratio = `$financials['free_cash_flow'] / $financials['trailing_eps']`.

**Oba tryby ValuationPillar muszą być zaktualizowane.**
`score()` dispatches do `scoreWithPeerGroup()` (gdy `$this->resolver !== null`) lub `scoreLegacy()`.
Oba mają własne Variant A. Należy zaktualizować oba — w przeciwnym razie legacy mode (używany
gdy `peer_group.enabled = false`) nie korzysta z normalizacji.

**`free_cash_flow` w `$financials` = `$fcfEffective`** (po capex-heavy korekcie S-05,
line 465 w normalise()). Pochodna `forward_fcf_est` powinna używać tej samej wartości
(`$fcfEffective`) dla spójności z tym, co pillar widzi jako mianownik.

---

## Phase 1: Config + forward_fcf_est w FinancialDataFetcher

### Overview

Dodajemy nową sekcję `'valuation'` do config oraz obliczamy pole `forward_fcf_est`
w `normalise()`. Po tej fazie `$financials` zawiera gotową wartość; ValuationPillar
(zmieniony w fazie 2) będzie ją konsumował.

### Changes Required

#### 1. Nowa sekcja `'valuation'` w config

**File**: `config/cvs-weights.php`

**Intent**: Dodać nową sekcję `'valuation'` tuż przed `'benchmarks'` (po `'earnings_guard'`),
zawierającą trzy klucze FCF normalization per FR-010.

**Contract**: Nowe klucze:
```php
'valuation' => [
    // FR-011: Use forward FCF estimate (forward_eps × fcf/trailing_eps) as
    // EV/FCF denominator instead of trailing_fcf × (1+g)².
    // Requires forward_eps and trailing_eps in $financials.
    // Set false to fall back to the pre-normalization formula everywhere.
    'use_forward_fcf_estimate' => true,

    // Bounds on the trailing FCF/EPS conversion ratio. Outside → fallback.
    // Prevents pathological cases (near-zero EPS, outlier capex ratios).
    'fcf_to_eps_ratio_min' => 0.3,   // below → ratio too small, skip estimate
    'fcf_to_eps_ratio_max' => 3.0,   // above → ratio too large, skip estimate
],
```

#### 2. Pole `forward_fcf_est` w `FinancialDataFetcher::normalise()`

**File**: `src/Api/FinancialDataFetcher.php`

**Intent**: Obliczyć jednoletni forward FCF z danych analityków i dodać go do tablicy
`$financials` jako pole `forward_fcf_est`. Jest to czysto pochodne obliczenie — bez
decyzji czy użyć; tą decyzją zajmie się ValuationPillar (faza 2).

**Contract**: Na końcu bloku `return [...]` w `normalise()`, tuż po istniejącym bloku
`free_cash_flow` / `free_cash_flow_raw` / `free_cash_flow_adjusted` (linie 462–468), dodać:

```php
// FCF normalization estimate (FR-011) — computed unconditionally when inputs are
// available; ValuationPillar decides whether to use it (bounds + feature flag).
// Derives analyst-forward FCF from: forward_eps × (fcfEffective / trailing_eps).
// Uses $fcfEffective (capex-adjusted) for denominator parity with free_cash_flow.
'forward_fcf_est' => (function () use ($fcfEffective, $forwardEps, $trailingEps): ?float {
    if ($fcfEffective === null || $fcfEffective <= 0.0) return null;
    if ($forwardEps === null) return null;
    if ($trailingEps === null || $trailingEps <= 0.0) return null;
    return $forwardEps * ($fcfEffective / $trailingEps);
})(),
```

Gdzie `$forwardEps` i `$trailingEps` to już wyekstrahowane zmienne z `defaultKeyStatistics`
(linie 481–482 w normalise()).

### Success Criteria

#### Automated Verification

- PHPStan czysty: `composer stan`
- Pełna suita testów zielona: `vendor/bin/phpunit`

#### Manual Verification

- Live fetch spółki (np. AAPL) przez UI → w logach/debuggerze `$financials` zawiera klucz
  `forward_fcf_est` z wartością float lub null gdy brak `forward_eps`.
- Live fetch MU → `forward_fcf_est` jest wyraźnie wyższy niż `free_cash_flow` (efekt trough + recovery EPS).

**Po przejściu automated verification — pauza na manual, potem commit fazy 1.**

---

## Phase 2: ValuationMetrics + ValuationPillar + CVSModel

### Overview

Podłączamy `forward_fcf_est` do logiki scoringu: `ValuationMetrics::forwardEvFcf()` dostaje
opcjonalny trzeci parametr, `ValuationPillar` stosuje bounds-check z config i decyduje co
przekazać, a `CVSModel` przekazuje nową sekcję config do konstruktora `ValuationPillar`.

### Changes Required

#### 1. Rozszerzenie `ValuationMetrics::forwardEvFcf()`

**File**: `src/CVS/Valuation/ValuationMetrics.php`

**Intent**: Przyjąć opcjonalny forward FCF estimate i użyć go bezpośrednio jako
mianownika gdy podany, w przeciwnym razie zachować dotychczasową formułę `trailing_fcf × (1+g)²`.

**Contract**: Sygnatura rozszerzona o opcjonalny trzeci parametr:

```php
public static function forwardEvFcf(
    array  $financials,
    float  $growthPct,
    ?float $fwdFcfEst = null   // ← nowy, backward-compatible
): ?float
```

Logika mianownika:
- Gdy `$fwdFcfEst !== null` (przekazany z ValuationPillar po bounds-check): użyj `$fwdFcfEst` bezpośrednio jako `$forwardFcf` — pomijamy `(1+g)²` bo estymata jest już 1yr forward.
- Gdy `$fwdFcfEst === null` (fallback lub brak danych): dotychczasowe `(float) $fcf * ((1.0 + $growthPct / 100.0) ** 2)`.

Oba paths zwracają `null` gdy EV lub finalny `$forwardFcf <= 0`.

Docblock musi opisać różnicę semantyczną: "When `$fwdFcfEst` is provided (FR-011 analyst
forward FCF), it is used directly without the 2-year growth projection — forward_eps is
already a 1-year forward estimate. When null, falls back to trailing_fcf × (1+g)²."

#### 2. `ValuationPillar` — nowy config + bounds-check w Variant A

**File**: `src/CVS/Pillars/ValuationPillar.php`

**Intent**: Przechowywać config `'valuation'` z cvs-weights.php, i w obu Variant A (peer-group
i legacy) stosować bounds-check ratio, decydując czy przekazać `forward_fcf_est` do
`forwardEvFcf()`.

**Contract**:

_Konstruktor_ — dodać opcjonalny parametr `array $valuationConfig = []`:
```php
public function __construct(
    array         $benchmarks,
    ?MedianResolver $resolver     = null,
    string        $anchorBlend   = 'min',
    float         $anchorWeight  = 0.3,
    array         $valuationConfig = []  // ← nowy
)
```
Przechowywany jako `private readonly array $valuationConfig`.

_Nowa prywatna metoda pomocnicza_ `resolveForwardFcfEst(array $financials): ?float`:
```
Reads $this->valuationConfig keys:
  use_forward_fcf_estimate (bool, default true)
  fcf_to_eps_ratio_min     (float, default 0.3)
  fcf_to_eps_ratio_max     (float, default 3.0)

Returns float gdy:
  - use_forward_fcf_estimate === true
  - $financials['forward_fcf_est'] !== null
  - $financials['trailing_eps'] !== null && $financials['trailing_eps'] > 0
  - $financials['free_cash_flow'] !== null && $financials['free_cash_flow'] > 0
  - ratio = free_cash_flow / trailing_eps ∈ [ratio_min, ratio_max]

Returns null w każdym innym przypadku (fallback do dotychczasowej formuły).
```

_W `scoreVariantA()` i odpowiedniku w `scoreLegacy()` (legacy Variant A)_:
Przed wywołaniem `ValuationMetrics::forwardEvFcf(...)` dodać:
```php
$fwdFcfEst = $this->resolveForwardFcfEst($financials);
$evFcf = ValuationMetrics::forwardEvFcf($financials, $growthPct, $fwdFcfEst);
```

#### 3. `CVSModel` — przekazanie `$config['valuation']` do ValuationPillar

**File**: `src/CVS/CVSModel.php`

**Intent**: Przekazać nową sekcję config do obu budowań `ValuationPillar` w `__construct()`.

**Contract**: W obu miejscach gdzie tworzone jest `new ValuationPillar(...)` (peer-group, line ~59,
i legacy, line ~66), dodać `$config['valuation'] ?? []` jako piąty argument:
```php
new ValuationPillar(
    $config['benchmarks'],
    $resolver,            // lub null dla legacy
    $config['peer_group']['anchor_blend'],
    $config['peer_group']['anchor_weight'],
    $config['valuation'] ?? []  // ← nowy
)
```

### Success Criteria

#### Automated Verification

- PHPStan czysty: `composer stan`
- Pełna suita testów zielona: `vendor/bin/phpunit`

#### Manual Verification

- Live fetch + scoring MU → pillar_scores.valuation wyższy niż przed zmianą (weryfikacja
  przez porównanie z poprzednim rescorem lub przez tymczasowe wyłączenie flagi
  `use_forward_fcf_estimate: false` i porównanie).
- Live fetch + scoring AAPL (zdrowa spółka, trailing FCF ≈ forward FCF) → pillar_scores.valuation
  niemal identyczny jak przed zmianą (≤ 2 pkt różnicy, w górę lub w dół).
- `use_forward_fcf_estimate: false` w configu → zachowanie identyczne jak przed tym plastrem.

**Po automated — pauza na manual, potem commit fazy 2.**

---

## Phase 3: Testy FR-011

### Overview

Weryfikacja jednostkowa i integracyjna na syntetycznych fixture'ach. Trzy scenariusze:
MU-style trough (normalizacja działa), healthy company (brak regresji), null forward_eps
(fallback zachowany).

### Changes Required

#### 1. Nowe testy w `ValuationMetricsTest`

**File**: `tests/CVS/Valuation/ValuationMetricsTest.php`

**Intent**: Przetestować `forwardEvFcf()` z nowym trzecim parametrem w izolacji.

**Contract**: 3 nowe metody testowe po istniejących testach `forwardEvFcf`:

- `test_forward_ev_fcf_uses_estimate_when_provided()` — gdy `$fwdFcfEst = 2_000_000.0`
  (wyższy niż trailing), zwrócone EV/FCF ratio powinno być niższe niż bez estymaty
  (normalnie `1_000_000 × (1.0+g)²`). Asercja: wynik z fwdFcfEst < wynik bez.

- `test_forward_ev_fcf_falls_back_when_estimate_is_null()` — gdy `$fwdFcfEst = null`,
  wynik identyczny z wywołaniem oryginalnej formuły (`forwardEvFcf($f, $g)` bez trzeciego arg).

- `test_forward_ev_fcf_returns_null_when_estimate_zero_or_negative()` — gdy `$fwdFcfEst = 0.0`
  lub ujemny, zwraca `null` (mianownik nieważny).

#### 2. Nowe testy integracyjne w `CVSModelTest`

**File**: `tests/CVS/CVSModelTest.php`

**Intent**: Zweryfikować end-to-end że normalizacja FCF poprawia score dla trough-FCF
i nie psuje zdrowej spółki.

**Contract**: 2 nowe metody testowe korzystające z `baseFinancials()` + overrides:

- `test_valuation_score_improves_for_trough_fcf_company()` — MU-style fixture:
  `free_cash_flow = 500_000_000` (nisko), `forward_eps = 8.0`, `trailing_eps = 1.0`
  (=> `forward_fcf_est = 8 × 500M/1 = 4_000M`).
  Porównaj z identycznym fixtures ale `forward_fcf_est = null` (lub feature flag off).
  Asercja: `pillar_scores['valuation']` z normalizacją > bez normalizacji.
  Note: `baseFinancials()` musi teraz przyjmować `forward_fcf_est` via overrides lub
  metoda wymaga dodania klucza do base fixtures — użyj override.

- `test_valuation_score_stable_for_healthy_fcf_company()` — zdrowa firma:
  `free_cash_flow = 1_500_000`, `forward_eps = 7.5`, `trailing_eps = 6.0`
  (ratio = 1500/6 × 7.5 = 1875 = forward_fcf_est — zbliżone do trailing_fcf × (1+g)).
  Asercja: `abs(pillar_scores['valuation'] z normalizacją - bez) <= 5.0` (stabilność ≤5 pkt).

### Success Criteria

#### Automated Verification

- `vendor/bin/phpunit tests/CVS/Valuation/ValuationMetricsTest.php` → zielony (+3 testy)
- `vendor/bin/phpunit tests/CVS/CVSModelTest.php` → zielony (+2 testy)
- Pełna suita: `vendor/bin/phpunit` → zielona
- PHPStan czysty: `composer stan`

#### Manual Verification

- Diff `pillar_scores.valuation` dla MU przed/po: wyraźna poprawa (MU powinno
  przejść z ~30–35 do ~50+ w wycenie, zależnie od aktualnych danych).
- Diff dla AAPL/MSFT (zdrowe): pillar_scores.valuation ≤ 3 pkt różnicy.

**Po automated — pauza na manual, potem commit fazy 3 i epilog.**

---

## Testing Strategy

### Unit Tests

- `ValuationMetricsTest::test_forward_ev_fcf_uses_estimate_when_provided()`
- `ValuationMetricsTest::test_forward_ev_fcf_falls_back_when_estimate_is_null()`
- `ValuationMetricsTest::test_forward_ev_fcf_returns_null_when_estimate_zero_or_negative()`

### Integration Tests

- `CVSModelTest::test_valuation_score_improves_for_trough_fcf_company()`
- `CVSModelTest::test_valuation_score_stable_for_healthy_fcf_company()`

### Manual Testing Steps

1. Live fetch MU (lub STX/INTC — inny trough-capex ticker) przez UI → `pillar_scores.valuation`
   powinien być wyższy niż bez normalizacji.
2. Live fetch AAPL/MSFT → `pillar_scores.valuation` niemal identyczny.
3. Tymczasowo ustaw `use_forward_fcf_estimate: false` w configu → oba testy scoringu
   wracają do wartości sprzed zmiany. Przywróć `true`.
4. Spółka bez `forward_eps` (np. małe/niszowe) → pillar_scores.valuation niezmieniony
   względem pre-normalization (fallback aktywny).

## Migration Notes

Brak zmian schematu DB. Brak nowych migracji SQL. Istniejące snapshoty 3.0 niezmienione.
`model_version` pozostaje `'3.0'` do rekalibracji skali (plaster 4). Nowe pole `forward_fcf_est`
jest efemeryczne — nie jest persystowane w `cvs_snapshots`.

## References

- shape-notes.md: `context/foundation/shape-notes.md` → FR-011, OQ-3 (forward FCF Yahoo: rozstrzygnięte)
- Lessons: `context/foundation/lessons.md` → "Filtruj shadow model_version…" (nieistotny dla tej zmiany)
- Hotfix kontekst: commit `442689d` — shadow row filtering
- Poprzednie plastery: `context/archive/2026-06-06-cvs-overlay-penalties/`, `context/archive/2026-06-08-cvs-earnings-timing/`
- ValuationMetrics: `src/CVS/Valuation/ValuationMetrics.php:106–118`
- ValuationPillar: `src/CVS/Pillars/ValuationPillar.php:125–171` (scoreVariantA)
- FinancialDataFetcher normalise FCF block: `src/Api/FinancialDataFetcher.php:407–468`
- CVSModel constructor: `src/CVS/CVSModel.php:44–71`
- Config: `config/cvs-weights.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Config + forward_fcf_est w FinancialDataFetcher

#### Automated

- [x] 1.1 PHPStan czysty (`composer stan`) — bc25728
- [x] 1.2 Pełna suita testów zielona (`vendor/bin/phpunit`) — bc25728

#### Manual

- [x] 1.3 Live fetch spółki → `$financials` zawiera klucz `forward_fcf_est` (float lub null) — bc25728
- [x] 1.4 Live fetch MU → `forward_fcf_est` wyraźnie wyższy niż `free_cash_flow` — bc25728

### Phase 2: ValuationMetrics + ValuationPillar + CVSModel

#### Automated

- [x] 2.1 PHPStan czysty (`composer stan`) — d4d04ed
- [x] 2.2 Pełna suita testów zielona (`vendor/bin/phpunit`) — d4d04ed

#### Manual

- [x] 2.3 Live scoring MU → `pillar_scores.valuation` wyższy niż przed zmianą — d4d04ed
- [x] 2.4 Live scoring AAPL → `pillar_scores.valuation` niemal identyczny jak przed — d4d04ed
- [x] 2.5 `use_forward_fcf_estimate: false` → zachowanie identyczne jak przed plastrem — d4d04ed

### Phase 3: Testy FR-011

#### Automated

- [x] 3.1 `ValuationMetricsTest` — 3 nowe testy zielone — 085deae
- [x] 3.2 `CVSModelTest` — 2 nowe testy zielone — 085deae
- [x] 3.3 Pełna suita zielona (`vendor/bin/phpunit`) — 085deae
- [x] 3.4 PHPStan czysty (`composer stan`) — 085deae

#### Manual

- [x] 3.5 Diff pillar_scores.valuation MU przed/po: wyraźna poprawa (>10 pkt) — 085deae
- [x] 3.6 Diff pillar_scores.valuation AAPL/MSFT: stabilność (≤ 3 pkt) — 085deae
