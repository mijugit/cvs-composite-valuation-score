---
change-id: model-track-record
title: "S-02: Track record modelu CVS"
status: implemented
created: 2026-06-02
updated: 2026-06-02
roadmap_ref: S-02
prd_refs: [FR-007]
---

# S-02 — Track record modelu

Widok historycznej trafności rekomendacji CVS względem późniejszego
zachowania ceny. Strona /track-record (globalna) + /track-record/{ticker}
(per-spółka). Dane z tabeli cvs_snapshots (snapshoty od 2026-06-01).

Metodyka: kierunek ceny po N dniach (domyślnie 30) vs rekomendacja CVS.
Ceny porównywane przez self-join: stary snapshot vs najnowszy snapshot
tego samego tickera — zero dodatkowych API callów YF.
