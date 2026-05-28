---
id: s-04-model-validation
status: done
---

## Plan wykonania S-04

### Faza 1 — Statyczna analiza architektury
- [x] Przeczytać Python cvs_analyze.py (źródło prawdy)
- [x] Przeczytać PHP pillars: GrowthPillar, SectorBenchmarkPillar, MomentumPillar, FundamentalQualityPillar
- [x] Przeczytać CVSModel.php — wagi i flow
- [x] Porównać architektury: zidentyfikować rozbieżności strukturalne

### Faza 2 — Parity test Python vs PHP
- [x] Uruchomić Python cvs_analyze.py dla 7 spółek testowych
  - AAPL: 50.9 (NEUTRALNIE)
  - MSFT: 16.8 (UNIKAJ)
  - NVDA: 41.9 (REDUKUJ)
  - META: 25.9 (UNIKAJ)
  - MELI: 69.1 (AKUMULUJ)
  - JNJ: 32.4 (REDUKUJ)
  - XOM: 19.2 (UNIKAJ)
- [x] Pobrać PHP CVS scores przez browser (cvs.timeflow.fun)
  - AAPL: 61.2 (AKUMULUJ)
  - MSFT: 37.6 (REDUKUJ)
  - NVDA: 38.6 (REDUKUJ)
  - META: 42.3 (NEUTRALNIE)
  - MELI: 47.2 (NEUTRALNIE)
  - JNJ: 46.8 (NEUTRALNIE)
  - XOM: 36.1 (REDUKUJ)
- [x] Pobrać pillar breakdown PHP przez JSON API (/analysis POST)
- [x] Porównać wyniki: Δ od -21.9 do +20.8 — tolerancja ±0.5 FAIL (0/7)

### Faza 3 — Benchmark zewnętrzny (stockanalysis.com / S&P Global)
- [x] AAPL: Buy, target $310.51 (-0.11%)
- [x] MSFT: Strong Buy, target $560.63 (+35.85%)
- [x] NVDA: Buy, target $304.59 (+43.27%)
- [x] META: Strong Buy, target $826.60 (+30.12%)
- [x] MELI: Buy, target $2,230 (+31.49%)
- [x] JNJ: Buy, target $252.96 (+9.37%)
- [x] XOM: Buy, target $169.18 (+14.39%)

### Faza 4 — Dokumentacja i decyzja
- [x] Zapisać wyniki w change.md
- [x] Sformułować go/no-go: **NO-GO** — architektura niezgodna z Python v1.6
- [x] Zidentyfikować root causes (4 przyczyny)
- [x] Określić ścieżkę naprawy: S-04b — port 3-pilarowy 70/20/10

### Wymagane dalsze kroki (S-04b — korekta architektury)

1. **Usuń GrowthPillar.php** — brak odpowiednika w Pythonie
2. **Zamień FundamentalQualityPillar.php** na nową implementację Python Filar 3:
   - GM vs sektor median → 0-4 pkt
   - NetDebt/EBITDA (lub Cash/Revenue fallback) → 0-3 pkt
   - Forward growth > 10% → 3 pkt, > 0% → 1.5 pkt, else → 0 pkt
   - sum/10 × 100 → score 0-100
3. **Zmień wagi w config/cvs-weights.php**: `valuation: 0.70, momentum: 0.20, quality: 0.10`
4. **Zmień CVSModel.php**: 3 pilary zamiast 4, pillar_scores keys: valuation/momentum/quality
5. **Opcjonalnie**: usuń/przejrzyj QualityGate (Python go nie ma QG)
6. **Zaktualizuj templates**: etykiety pillarów, radar chart (3 osie)
7. **Zaktualizuj testy** i ponownie uruchomić parity test (cel: ±2 pkt dla danych live)
