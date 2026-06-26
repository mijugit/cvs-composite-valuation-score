---
id: virtual-portfolio-ledger
title: "F-02: Virtual portfolio ledger contract"
status: implemented
created: 2026-06-26
updated: 2026-06-26
roadmap_ref: F-02
---

## Summary

Stable persistence contract for the virtual portfolio: mutable singleton state (cash + holdings) with an immutable transaction audit trail. Extends the `rebalance_cycle` table from F-01 with execution summary columns. Provides `PortfolioRepository` (read model) and `PortfolioService` (atomic write model) for the full rebalance engine to consume.

## Context

Foundation piece for the Virtual Portfolio module. F-01 delivered the scheduler gate and the minimal `rebalance_cycle` table. F-02 extends that table and creates the portfolio persistence layer. F-03 (LLM contract) and S-01 (read-only view) both depend on this ledger being stable before they can be implemented.

## Links

- PRD: `context/foundation/prd-virtual-portfolio.md`
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (F-02)
- F-01 plan: `context/changes/rebalance-scheduler-and-calendar/plan.md`
- Plan: `context/changes/virtual-portfolio-ledger/plan.md`
- Brief: `context/changes/virtual-portfolio-ledger/plan-brief.md`
- Unlocks: F-03, S-01, S-02, S-03, S-04, S-05
