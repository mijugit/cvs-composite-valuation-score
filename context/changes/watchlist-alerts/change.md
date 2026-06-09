---
change-id: watchlist-alerts
title: "S-04: Alerty watchlisty — mail przy zmianie stanu CVS"
status: implemented
created: 2026-06-02
updated: 2026-06-02
roadmap_ref: S-04
prd_refs: [FR-011, FR-012]
---

# S-04 — Alerty watchlisty

Mail do usera gdy spółka z jego watchlisty zmieni reko_swing lub golden_signal.
Deduplikacja przez tabelę alert_sent (alert tylko gdy stan różny od ostatniego
wysłanego). Globalne ON/OFF per user + per-ticker disable. Domyślnie OFF.
Alerty wyzwalane w bin/rescore.php po save().
