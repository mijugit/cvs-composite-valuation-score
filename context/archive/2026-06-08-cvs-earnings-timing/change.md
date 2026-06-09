---
change_id: cvs-earnings-timing
title: "Faza 5 plaster 2 — świadomość czasu wyników (dni od/do, earnings-proximity guard, badge)"
status: archived
created: 2026-06-08
updated: 2026-06-08
archived_at: "2026-06-08T20:31:22Z"
---

## Notes

Faza 5 plaster 2: świadomość czasu wyników (FR-006..FR-010 z `context/foundation/prd.md` —
dni od/do wyników, earnings-proximity guard, badge na detalu/screenerze). Drugi plaster w
ustalonej kolejności dostarczania z `context/foundation/shape-notes.md` (po plastrze 1
`cvs-overlay-penalties`, status: implemented i wdrożony na CF, SHA `3a7b279`).

Zakres (z PRD, sekcja "Świadomość czasu wyników"):
- FR-006: „dni od ostatnich wyników" — malejąca waga starych liczb, z pola już obecnego w danych
- FR-007 (must-have): „dni do następnych wyników" — sygnał okna przed-wynikowego
- FR-008 (modified): snapshoty CVS wzbogacone o znaczniki czasu wyników (addytywnie)
- FR-009: earnings-proximity guard — w oknie przed-wynikowym (~K sesji) model temperuje
  konwersję napędzaną momentum i flaguje; „dni od/do" liczone przy pobraniu i wstrzykiwane
  jako wejście (zachowuje determinizm — Socrates: liczenie bieżącej daty w logice score'a
  złamałoby determinizm)
- FR-010 (nice-to-have): badge czasu wyników (przed/po/w tranzycie) na screenerze i detalu

Zachowane: FR-015 (determinizm), FR-016 (skala rekomendacji), FR-017 (AI/szablony bez
regresji), FR-018 (tylko darmowe dane Yahoo), FR-019 (zmiany schematu addytywne).
