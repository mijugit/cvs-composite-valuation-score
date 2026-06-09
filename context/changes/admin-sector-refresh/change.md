---
id: admin-sector-refresh
title: "Admin: panel odświeżania sektorów peer-group"
status: implemented
created: 2026-06-05
updated: 2026-06-05
completed: 2026-06-05
---

## Summary

Nowa sekcja panelu administracyjnego dostępna przez `/admin/sectors`. Pokazuje stan indeksowania sektorów (mediany peer-group z tabeli `peer_medians`) i pozwala adminowi wymusić odświeżenie dowolnego sektora niezależnie od harmonogramu cron.

## Context

Faza 3 projektu CVS (cvs-scoring-refinement) wdrożyła peer-group scoring bazujący na empirycznych medianach z tabeli `peer_medians`. Cron odświeża sektory rolująco (jeden dzień = jeden sektor). Brakuje narzędzia adminowego do diagnostyki stanu indeksowania i ręcznego wymuszenia odświeżenia.

## Links

- Plan: `context/changes/admin-sector-refresh/plan.md`
- Brief: `context/changes/admin-sector-refresh/plan-brief.md`
- Powiązana zmiana: `context/archive/` (cvs-scoring-refinement — faza 3)
