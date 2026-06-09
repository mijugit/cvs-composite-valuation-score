---
change-id: pro-access
title: "F-05: Dostęp PRO — kody, brama, śledzenie zużycia, panel admina"
status: implemented
created: 2026-06-01
updated: 2026-06-01
roadmap_ref: F-05
prd_refs: [FR-003, FR-004]
---

# F-05 — Dostęp PRO

Kody PRO wydawane przez admina (`/admin/pro`), brama walidująca kod z sesji
przed generowaniem analizy AI, śledzenie zużycia (dzienny limit 10,
miesięczny 100 per user). Jeden globalny kod na start (`user_id = NULL`),
gotowość na indywidualne kody per user bez migracji.
Fundament pod S-01 (analiza AI).
