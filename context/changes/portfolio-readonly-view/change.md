---
id: portfolio-readonly-view
title: "S-01: Portfolio read-only view"
status: implemented
created: 2026-06-26
updated: 2026-06-26
roadmap_ref: S-01
---

## Summary

Read-only portfolio page at `/portfolio` for all logged-in users. Shows current cash, holdings with live-ish prices (JOIN to cvs_snapshots), total portfolio value, and the latest rebalance cycle status. Empty state with next trading day date when no cycles have run yet.

## Context

First user-visible surface for the virtual portfolio. Depends on F-02 (PortfolioRepository, portfolio tables) and the data already seeded on production (10 000 USD cash, migrations 024–027 applied). Unlocks S-04 (screener linkage).

## Links

- PRD: `context/foundation/prd-virtual-portfolio.md` (US-01, FR-012, FR-017)
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (S-01)
- F-02 plan: `context/changes/virtual-portfolio-ledger/plan.md`
- Plan: `context/changes/portfolio-readonly-view/plan.md`
- Brief: `context/changes/portfolio-readonly-view/plan-brief.md`
