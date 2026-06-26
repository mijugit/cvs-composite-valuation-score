---
id: llm-decision-contract-and-retry
title: "F-03: LLM decision contract and retry safety"
status: planned
created: 2026-06-26
updated: 2026-06-26
roadmap_ref: F-03
---

## Summary

Strict JSON decision schema for LLM-driven portfolio rebalancing, with a guaranteed two-attempt policy (try + 1 retry) at the service level. Extends rebalance_cycle with LLM audit columns, introduces DecisionParser (schema validation) and DecisionService (prompt builder + retry orchestrator), and wires the full end-to-end cycle into bin/portfolio-rebalance.php — replacing the F-01 engine stub.

## Context

Final foundation piece before user-visible slices. Depends on F-01 (scheduler gate, rebalance_cycle table) and F-02 (PortfolioService::executeCycle, CycleRepository). Uses the existing ClaudeClient pattern from src/Ai/ — follows AiDivergenceService as the canonical template. After F-03, the system can run an autonomous rebalance cycle end-to-end.

## Links

- PRD: `context/foundation/prd-virtual-portfolio.md`
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (F-03)
- F-01 plan: `context/changes/rebalance-scheduler-and-calendar/plan.md`
- F-02 plan: `context/changes/virtual-portfolio-ledger/plan.md`
- Plan: `context/changes/llm-decision-contract-and-retry/plan.md`
- Brief: `context/changes/llm-decision-contract-and-retry/plan-brief.md`
- Unlocks: S-01, S-02, S-03, S-04, S-05
