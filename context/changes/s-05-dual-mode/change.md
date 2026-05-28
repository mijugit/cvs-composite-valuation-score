---
id: s-05-dual-mode
title: "CVS Dual Mode — Swing (1–4M) + Fundamentalny (6–12M)"
status: implemented
roadmap_ref: S-05
created: 2026-05-28
updated: 2026-05-28
---

## Summary

Przebudowa modelu PHP z 4-pilarowego (30/25/25/20) na 3-pilarowy z dwoma trybami
scoringowymi kalkulowanymi zawsze równolegle. Każda karta wynikowa pokazuje oba score
jednocześnie. Złote sygnały (⭐/⭐⭐) zaznaczają najciekawsze setupy.

## Decyzja projektowa — odejście od Python v1.6

Python był prototypem do weryfikacji matematyki. PHP to produkt zoptymalizowany
pod swing trading (1–4 miesiące) z dodatkowym widokiem fundamentalnym (6–12 miesięcy).

Model CVS odpowiada na pytanie: **"Czy tu i teraz mamy wartość akcji godziwą
na krótki deal, a jeśli nie — kiedy warto czekać na wejście?"**

## Architektura dwóch trybów

```
CVS = w_val × Valuation + w_mom × Momentum + w_qual × Quality

Tryb Swing (1–4M):
  valuation: 0.40 | momentum: 0.45 | quality: 0.15
  ROC composite: 0.50 × ROC_1M + 0.30 × ROC_3M + 0.20 × ROC_6M

Tryb Fundamentalny (6–12M):
  valuation: 0.65 | momentum: 0.15 | quality: 0.20
  ROC composite: 0.30 × ROC_3M + 0.40 × ROC_6M + 0.30 × ROC_12M
```

Surowe score pilarów (Valuation, Momentum, Quality) są **identyczne** w obu trybach.
Różnią się tylko wagi w finalnym CVS. Radar chart pokazuje dwa zestawy danych
na jednym wykresie (dwie linie).

## Złote sygnały

| Swing | Fund | Sygnał | Etykieta |
|-------|------|--------|----------|
| ≥ 58 | ≥ 58 | ⭐⭐ | "Silny sygnał — wartość i momentum" |
| < 58 | ≥ 58 | ⭐ | "Setup — czekaj na momentum" |
| ≥ 58 | < 58 | — | "Momentum — nie value" |
| < 58 | < 58 | — | brak |

## Walidacja na danych S-04 (7 spółek, 2026-05-28)

| Ticker | Swing | Fund | Signal | Wall St. | Zgodność |
|--------|-------|------|--------|----------|----------|
| AAPL | 54 NEUTRAL | 53 NEUTRAL | — | Buy -0.1% | ✅ at-target |
| MSFT | 25 UNIKAJ | 25 UNIKAJ | — | Strong Buy +36% | ⚠️ model value-only |
| NVDA | 54 NEUTRAL | 47 NEUTRAL | — | Buy +43% | ✅ nie przepłacaj |
| META | 33 REDUKUJ | 34 REDUKUJ | — | Strong Buy +30% | ⚠️ model value-only |
| MELI | 46 NEUTRAL | 72 S.KUPUJ | ⭐ | Buy +31% | ✅ złoty sygnał działa |
| JNJ | 39 REDUKUJ | 37 REDUKUJ | — | Buy +9% | ✅ umiarkowana rozb. |
| XOM | 35 REDUKUJ | 23 UNIKAJ | — | Buy +14% | ⚠️ wymaga OpCF fix |

MSFT/META/NVDA — model poprawnie mówi "drogo vs multiples". Wall Street gra AI/growth.
Obie perspektywy są uczciwe — model jest screenerem wartościowym, nie growth-at-any-price.
