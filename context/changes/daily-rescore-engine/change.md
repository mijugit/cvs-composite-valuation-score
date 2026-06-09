---
change-id: daily-rescore-engine
title: "F-04: Dzienny silnik re-scoringu + snapshoty CVS"
status: implemented
created: 2026-06-01
updated: 2026-06-01
roadmap_ref: F-04
prd_refs: [FR-010]
---

# F-04 — Dzienny silnik re-scoringu + snapshoty CVS

Zadanie CLI (`bin/rescore.php`) uruchamiane cronem 2× dziennie na Cyber_Folks
(po otwarciu i po zamknięciu NYSE), re-scoruje unię watchlist wszystkich userów
i zapisuje snapshoty CVS do nowej tabeli `cvs_snapshots`. Fundament pod S-02
(track record), S-03 (screener) i S-04 (alerty watchlisty).
