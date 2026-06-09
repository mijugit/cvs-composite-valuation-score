---
change-id: claude-api-client
title: "F-02: Klient Claude API w src/Ai z guardrailami"
status: archived
created: 2026-05-31
updated: 2026-05-31
archived_at: 2026-05-31T19:11:31Z
last_phase: 3
roadmap_ref: F-02
prd_refs: [FR-001, "NFR (AI: odporność/uziemienie/koszt)"]
---

# F-02 — Klient Claude API (fundament)

Jeden reużywalny klient Claude API w `src/Ai/` (`CVS\Ai\`) z typed-failure,
retry/backoff, prompt-cachingiem i seamem transportu testowalnym offline.
Fundament pod north star S-01 (ai-divergence-analysis). BEZ logiki analizy,
DB-cache i bramy PRO — to osobne zmiany.

Artefakty: `plan.md`, `plan-brief.md`.
