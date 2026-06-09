---
id: cvs-overlay-penalties
title: "Faza 5 plaster 1 — overlaye kar post-agregacyjnych (rewizja prognoz + cena-vs-target) za model_version 3.1"
status: implemented
created: 2026-06-06
updated: 2026-06-08
---

# CVS overlay penalties (phase 5, slice 1)

Pierwszy plaster fazy 5: dwa deterministyczne overlaye jako kary post-agregacyjne — Overlay A
(rewizja prognoz, źródło `earningsTrend.epsTrend` +1q) i Overlay B (cena-vs-target, `upside` z
ForecastParser) — liczone w trybie CIENIOWYM za `model_version` 3.1 (pokazywana rekomendacja
zostaje 3.0 do rekalibracji). Walidacja referencyjna: `cvs-composite-valuation-score/sim_overlay.php`.
PRD: `context/foundation/prd.md` (FR-001..FR-005, FR-015..FR-019).
