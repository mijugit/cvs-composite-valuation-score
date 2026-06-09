---
id: s-07-price-chart
title: "Price chart na detail page — 12-miesięczny wykres ceny + SPY"
status: implemented
roadmap_ref: S-07
created: 2026-05-28
updated: 2026-05-28
---

## Summary

Strona szczegółów `/analysis/{ticker}` dostaje wykres liniowy Chart.js
pokazujący znormalizowaną cenę spółki vs SPY za ostatnie 12 miesięcy.
Dane (`monthly_closes`, `spy_closes`) są już w `$financials` — brak zmian backendu.
Sekcja ukryta całkowicie jeśli `monthly_closes` jest puste.

## Decisions

| Decyzja | Wybór |
|---|---|
| Zawartość | Cena spółki + linia SPY (benchmark) |
| Okres | 12 miesięcy (ostatni rok) |
| Placement | Między tytułem a kartą wynikową (nad radarem) |
| Brak danych | Ukryj sekcję całkowicie |
| Normalizacja | Indeks 100 od pierwszego punktu (obie linie) |
| Implementacja | Inline `<script>` + `<style>` w analysis.php |
