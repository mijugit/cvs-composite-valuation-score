---
id: llm-free-wallet
title: "LLM_Free_Wallet — second, unconstrained LLM-driven portfolio"
status: implementing
created: 2026-08-06
updated: 2026-08-07
---

## Summary

New, parallel LLM-driven portfolio module alongside the existing (renamed "LLM Bazowy")
Portfolio module. Gives the model true decision freedom — no `DecisionEnforcer`-style
server-side overrides, no fixed quantitative rules — plus a persistent, self-authored
"legenda" (investment thesis) it writes each cycle and reads back on the next. Tests
whether an LLM with real interpretive freedom (including the right to challenge CVS
signals) and memory across cycles outperforms the fully deterministic baseline.

## Context

Origin: analysis of `DecisionService::buildSystemPrompt()` (2026-08-06) showed the
existing LLM portfolio is functionally a rules engine — `DecisionEnforcer` overrides
model decisions regardless of stated reasoning, so the model has no real room to
diverge from the algorithm. This is a deliberate, isolated experiment module (mirrors
the Lab module's precedent: multiple portfolio variants get their own module rather
than extending the `portfolio_state` singleton).

## Links

- PRD: `context/foundation/prd.md` (workspace root, "CVS — LLM_Free_Wallet")
- Shape notes: `context/foundation/shape-notes.md` (workspace root)
- Plan: `context/changes/llm-free-wallet/plan.md`
- Brief: `context/changes/llm-free-wallet/plan-brief.md`
