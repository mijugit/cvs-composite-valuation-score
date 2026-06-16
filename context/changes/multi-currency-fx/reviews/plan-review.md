<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Multi-Currency FX Conversion

- **Plan**: context/changes/multi-currency-fx/plan.md
- **Mode**: Deep
- **Date**: 2026-06-16
- **Verdict**: REVISE → SOUND (po triage)
- **Findings**: 1 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | WARNING |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | FAIL |
| Plan Completeness | PASS |

## Grounding
7/7 paths ✓, symbols ✓ (fetchSpyCloses/fetchChartData, model_version='3.0'), Progress↔Phase 5/5 ✓, brief↔plan ✓. Pozytyw: QualityGate ratio-only (QualityGate.php:33-78) — konwersja gate-safe.

## Findings

### F1 — Track-record miesza ceny natywne (3.0) i USD (4.0)

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — realny tradeoff; przemyśl przed decyzją
- **Dimension**: Blind Spots
- **Location**: Faza 3 + 5
- **Detail**: TrackRecordRepository liczy zwrot (price_now−price_then)/price_then ([:67-69]). Filtr wersji działa tylko gdy przekazany model_version, a TrackRecordController woła getEvaluations/getForTicker bez wersji (TrackRecordController.php:36,70) → null → brak filtra. Po bumpie 3.0→4.0 + price USD, zagraniczne tickery dostają garbage zwrot (KRW 3.0 vs USD 4.0).
- **Fix**: Przekaż live model_version do getEvaluations/getForTicker (wzorzec findAllLatest) + notka o resecie okna historii w Migration Notes.
- **Decision**: FIXED (Fix in plan — Faza 3 #4, kryteria 3.4/3.6, Migration Notes)

### F2 — Kryterium sukcesu ADR sprzeczne z uznanym ryzykiem ADR-ratio

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — realny tradeoff; przemyśl przed decyzją
- **Dimension**: End-State Alignment
- **Location**: Faza 4, kryterium 4.6
- **Detail**: 4.6 obiecuje "TSM ADR FV się pokazuje", ale Open Risks przyznaje ryzyko ADR-ratio (shares ≠ ADR price) → błędne EV → suppress przez bounds. Promise-gap.
- **Fix**: Złagodź 4.6 do "ADR nie pokazuje błędnych liczb (poprawny FV LUB świadomie suppress); score na USD".
- **Decision**: FIXED (Fix in plan — kryterium 4.6 + Progress 4.6)

### F3 — Pobranie "ostatniego kursu FX" niedoprecyzowane

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — szybka decyzja; fix oczywisty
- **Dimension**: Plan Completeness
- **Location**: Faza 1, fetchFxRateToUsd
- **Detail**: fetchChartData zwraca całą serię; range '3y' pobrałby 3 lata kursu niepotrzebnie.
- **Fix**: Doprecyzuj — ostatni niepusty close, minimalny range (np. 5d/1d).
- **Decision**: FIXED (Fix in plan — Faza 1 Contract)
