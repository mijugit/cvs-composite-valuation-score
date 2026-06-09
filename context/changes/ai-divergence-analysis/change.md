---
change-id: ai-divergence-analysis
title: "S-01: Analiza AI wyjaśniająca rozjazd CVS vs analitycy (North Star)"
status: implemented
created: 2026-06-01
updated: 2026-06-01
roadmap_ref: S-01
prd_refs: [US-01, FR-001, FR-002, FR-004]
---

# S-01 — Analiza AI wyjaśniająca rozjazd CVS vs analitycy

User PRO na `/analysis/{ticker}` generuje analizę AI (4 sekcje: Ocena CVS /
Opinia rynku / Rozjazd i jego przyczyna / Komu wierzyć i w jakim horyzoncie).
Analiza trafia do współdzielonego cache (7 dni), widoczna dla wszystkich
zalogowanych userów. PRO może odświeżyć co 24h. Narracja uziemiona w danych
CVS + Yahoo Finance (bez web-browsing przez AI). North Star fazy 2.
