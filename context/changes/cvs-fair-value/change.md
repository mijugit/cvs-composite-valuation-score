---
change-id: cvs-fair-value
title: "X-01: CVS Implied Fair Value — kafelek + fan chart + AI prompt"
status: implemented
created: 2026-06-01
updated: 2026-06-01
roadmap_ref: X-01
prd_refs: []
---

# X-01 — CVS Implied Fair Value

Implikowana cena godziwa wyprowadzona z filaru Wyceny modelu CVS:
cena, przy której EV/FCF spółki = mediana sektora (parytet sektorowy).

**Formuła:**
```
Fair EV    = median_EV/FCF_sektora × FCF × (1 + growth_capped)²
Fair Price = (Fair EV − Net Debt) / Shares Outstanding
```

**Gdzie wyświetlana:**
- Kafelek „CVS Fair Value" w sekcji celów cenowych obok Min/Śr/Max analityków
- Żółta przerywana linia na fan chart (jak cel analityków)
- Sekcja w prompcie AI (Claude wyjaśnia metodę i premia/dyskonto vs cena)

**Guardy (bugfix po retro):**
- Currency mismatch guard: `financial_currency != currency` → null
  (TSM raportuje w TWD, cena w USD → wartość była $11,800 zamiast ~$185)
- Bounds guard: fair price poza 0.05×–10× ceny bieżącej → null

**Commits:** `588e0ab` (initial), `bfd1b35` (AI prompt), `fb561d9` (currency fix)

**Lesson:** Yahoo Finance zwraca FCF w walucie sprawozdawczej spółki
(TWD dla TSMC, HKD dla Alibaba itp.), nie w USD. Dla ADRów i spółek
notowanych w innej walucie niż raporty → guard walutowy konieczny.
