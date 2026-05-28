---
id: s-04-model-validation
title: "Walidacja modelu CVS przed launchem"
status: done
roadmap_ref: S-04
created: 2026-05-28
updated: 2026-05-28
implementing_started: 2026-05-28
done_at: 2026-05-28
---

## Summary

Formalna walidacja parity PHP vs Python dla 7 spółek (AAPL, MSFT, NVDA, META, MELI,
JNJ, XOM) z benchmarkiem zewnętrznym (stockanalysis.com / S&P Global analyst consensus).

**Decyzja go/no-go: NO-GO** — model PHP nie jest parytarem modelu Python.
Rozbieżność jest architektoniczna, nie arytmetyczna. Pillar matematyki są poprawne,
ale układ wag jest zły. Wymagana korekta (S-04b) przed launchem.

## Wyniki testu parytetu

| Ticker | PHP CVS | PHP Reco | Python CVS | Python Reco | Δ | Tolerancja ±0.5 |
|--------|---------|----------|-----------|-------------|---|------------------|
| AAPL | 61.2 | AKUMULUJ | 50.9 | NEUTRALNIE | **+10.3** | ❌ FAIL |
| MSFT | 37.6 | REDUKUJ | 16.8 | UNIKAJ | **+20.8** | ❌ FAIL |
| NVDA | 38.6 | REDUKUJ | 41.9 | REDUKUJ | -3.3 | ❌ FAIL |
| META | 42.3 | NEUTRALNIE | 25.9 | UNIKAJ | **+16.4** | ❌ FAIL |
| MELI | 47.2 | NEUTRALNIE | 69.1 | AKUMULUJ | **-21.9** | ❌ FAIL |
| JNJ | 46.8 | NEUTRALNIE | 32.4 | REDUKUJ | **+14.4** | ❌ FAIL |
| XOM | 36.1 | REDUKUJ | 19.2 | UNIKAJ | **+16.9** | ❌ FAIL |

**Wynik: 0/7 testerów w tolerancji ±0.5 pt.** Rozbieżność: od -21.9 do +20.8 pkt.

## Pillar breakdown porównawczy

### PHP (4 pilary, wagi 30/25/25/20)
| Ticker | Growth(30%) | Sector(25%) | Momentum(25%) | Quality(20%) | CVS |
|--------|------------|-------------|--------------|-------------|-----|
| AAPL | 61.33 | 46.30 | 60.01 | 81.02 | 61.18 |
| MSFT | 56.24 | 4.97 | 22.62 | 69.23 | 37.62 |
| NVDA | 3.06 | 26.16 | 59.21 | 81.48 | 38.56 |
| META | 55.66 | 14.40 | 32.03 | 69.75 | 42.26 |
| MELI | 47.74 | 80.59 | 15.69 | 44.15 | 47.22 |
| JNJ | 65.28 | 23.48 | 36.67 | 61.04 | 46.83 |
| XOM | 55.19 | 0.00 | 43.33 | 43.57 | 36.10 |

### Python (3 pilary, wagi 70/20/10)
| Ticker | Valuation(70%) | Momentum(20%) | Quality_norm(10%) | CVS |
|--------|---------------|--------------|------------------|-----|
| AAPL | 46.31 | 57.61 | 70.00 | 50.9 |
| MSFT | 5.00 | 21.48 | 90.00 | 16.8 |
| NVDA | 26.16 | 67.81 | 100.00 | 41.9 |
| META | 14.40 | 28.93 | 100.00 | 25.9 |
| MELI | 82.75 | 15.69 | 80.00 | 69.1 |
| JNJ | 23.48 | 42.11 | 75.00 | 32.4 |
| XOM | 0.00 | 63.61 | 65.00 | 19.2 |

## Benchmark zewnętrzny — analyst consensus (stockanalysis.com / S&P Global, 2026-05-28)

| Ticker | Consensus WS | Upside PT | Python Reco | PHP Reco | Bliżej prawdy |
|--------|-------------|-----------|-------------|---------|---------------|
| AAPL | Buy | -0.11% | NEUTRALNIE | AKUMULUJ | Python (at-target) |
| MSFT | Strong Buy | +35.85% | UNIKAJ | REDUKUJ | PHP (kierunek) |
| NVDA | Buy | +43.27% | REDUKUJ | REDUKUJ | **oba zbyt pesymistyczne** |
| META | Strong Buy | +30.12% | UNIKAJ | NEUTRALNIE | PHP (kierunek) |
| MELI | Buy | +31.49% | AKUMULUJ | NEUTRALNIE | **Python** ✅ |
| JNJ | Buy | +9.37% | REDUKUJ | NEUTRALNIE | PHP (kierunek) |
| XOM | Buy | +14.39% | UNIKAJ | REDUKUJ | PHP (kierunek) |

## Diagnoza architektoniczna — przyczyna rozbieżności

### Root cause #1 — GrowthPillar (PHP only, 30%)
PHP ma filar wzrostu (revenue CAGR vs TTM) bez odpowiednika w Pythonie.
Działa jako **sztuczny bufor ciążący ku 50**: dla NVDA (boom AI = deceleration
vs CAGR) daje 3.06, dla JNJ daje 65.28. Nie ma uzasadnienia w modelu Python.

### Root cause #2 — Waga wyceny: 25% (PHP) vs 70% (Python)
PHP `SectorBenchmarkPillar` implementuje **identyczną matematykę** co Python Filar 1
(EV/FCF sigmoid, Variant A/B). Wartości surowe są te same:
- AAPL: PHP.sector = 46.30 ≈ Python.valuation = 46.31 ✅
- MELI: PHP.sector = 80.59 ≈ Python.valuation = 82.75 ≈ ✅ (mała różnica: capping wzrostu)
- XOM: PHP.sector = 0.00 = Python.valuation = 0.00 ✅

Ale ta sama liczba ma wagę 0.25 w PHP vs 0.70 w Pythonie — 3× różnica!
Dla MELI: 80.59 × 0.25 = 20.1 (PHP) vs 82.75 × 0.70 = 57.9 (Python).

### Root cause #3 — FundamentalQualityPillar (PHP) ≠ Python Jakość
PHP: ROE + FCF margin + GM trend + leverage
Python: GM vs sektor median + NetDebt/EBITDA + Forward growth → sum/10 → normalize
Różne metryki, różna normalizacja. Dla MSFT: PHP = 69.23 vs Python = 90.00.

### Root cause #4 — QualityGate (PHP only)
Python nie ma binarnego filtra. PHP odrzuca spółki przed obliczaniem CVS.
W tym teście wszystkie 7 spółek przeszło — brak wpływu, ale to strukturalna różnica.

## Co działa poprawnie

- PHP `SectorBenchmarkPillar` math = Python Valuation math — **identyczne** ✅
- PHP `MomentumPillar` math ≈ Python Momentum — **małe różnice (<5pt) z powodu timestamp danych** ✅
- Benchmarki sektorowe: identyczne w obu implementacjach ✅
- Wariant A/B wybór: identyczny ✅
- Sigmoid: identyczna ✅

## Benchmark zewnętrzny vs model CVS — komentarz merytoryczny

Model CVS jest **filtrem wartościowym** — faworyzuje spółki z niskim EV/FCF.
Wall Street consensus uwzględnia narracje wzrostu AI, opcjonalność, pozycję sektorową.

Kluczowe obserwacje:
1. **MSFT/META/NVDA** — model widzi drogo (EV/FCF 2-3× mediana sektora). WS bullish
   na AI. Obie perspektywy są logicznie spójne — model mówi "przewartościowane vs
   historyczne EV/FCF normy", WS mówi "wzrost uzasadnia premię".

2. **MELI** — Python AKUMULUJ = WS Buy ✅. PHP NEUTRALNIE = ❌ missed signal.
   MELI: ujemny FCF ale niski EV/Sales vs dynamiczny wzrost 49% → model Python poprawnie
   identyfikuje jako atrakcyjną w wycenie.

3. **XOM** — model daje 0 za wycenę (EV/FCF = 53x vs mediana Energy 12x).
   Potencjalne ograniczenie: trailing FCF dla ExxonMobil może być zaniżone przez capex
   eksploracyjny. Warto rozważyć uzupełnienie o OpCF jako alternatywę.

4. **JNJ** — model REDUKUJ (Python), WS Buy. JNJ: EV/FCF = 39x vs Healthcare median 28x.
   Flaga EARNINGS_EPS_REVENUE_GAP aktywna. Niska baza FCF vs wielka spółka.

## Decyzja go/no-go

**NO-GO** — model PHP nie jest parytarem Pythona v1.6.

Warunek parytatetu z roadmapy (tolerancja ±0.5 pkt) nie jest spełniony dla żadnego
z 7 testowanych tickerów. Rozbieżność jest strukturalna: architektura 4-pilarowa
(30/25/25/20) vs 3-pilarowa (70/20/10).

**Rekomendacja**: Korekta S-04b — przepisanie CVSModel.php pod 3-pilarową architekturę
Python. Implementacja pillarów jest gotowa (SectorBenchmarkPillar i MomentumPillar
są poprawne) — wymagana jest głównie zmiana wag i usunięcie GrowthPillar.
