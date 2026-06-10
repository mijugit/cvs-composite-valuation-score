# Plan Brief — cvs-fcf-normalization

**Problem**: `ValuationPillar` używa `trailing_fcf × (1+g)²` jako forward FCF w mianowniku EV/FCF.
Dla spółek w trough capex-cycle (MU/Micron: niski trailing_fcf przez HBM capex) formuła
zawyża EV/FCF → fałszywa "drогość" mimo silnego odbicia EPS.

**Fix**: Gdy dostępne są `forward_eps` i `trailing_eps` od analityków, wyznacz
`forward_fcf_est = forward_eps × (trailing_fcf / trailing_eps)` i użyj jako mianownika
bezpośrednio (bez `(1+g)²` — forward_eps jest już 1yr forward). Fallback do dotychczasowej
formuły gdy dane niedostępne lub ratio poza granicami [0.3, 3.0].

**Zakres**: 3 fazy, ~8 plików, żadnych zmian DB ani nowych modułów Yahoo Finance.

---

## Phase 1: Config + forward_fcf_est w FinancialDataFetcher

**Files**: `config/cvs-weights.php`, `src/Api/FinancialDataFetcher.php`

1. Dodaj sekcję `'valuation'` do config (klucze: `use_forward_fcf_estimate`, `fcf_to_eps_ratio_min/max`)
2. W `normalise()` oblicz `forward_fcf_est` bezwarunkowo gdy dane dostępne — ValuationPillar
   zdecyduje czy użyć (ma dostęp do config)

---

## Phase 2: ValuationMetrics + ValuationPillar + CVSModel

**Files**: `src/CVS/Valuation/ValuationMetrics.php`, `src/CVS/Pillars/ValuationPillar.php`, `src/CVS/CVSModel.php`

1. `ValuationMetrics::forwardEvFcf()` — opcjonalny 3. parametr `?float $fwdFcfEst = null`;
   gdy podany — użyj wprost, gdy null — dotychczasowa formuła (backward-compatible)
2. `ValuationPillar` — nowe pole `$valuationConfig`, nowa metoda `resolveForwardFcfEst()`,
   wywołanie zaktualizowane w obu Variant A (peer-group i legacy)
3. `CVSModel` — przekazuje `$config['valuation'] ?? []` do obu konstruktorów `ValuationPillar`

---

## Phase 3: Testy

**Files**: `tests/CVS/Valuation/ValuationMetricsTest.php`, `tests/CVS/CVSModelTest.php`

- 3 testy jednostkowe `forwardEvFcf`: estimate used, fallback null, estimate zero/negative
- 2 testy integracyjne: trough-FCF score improves (MU-style), healthy company no regression
