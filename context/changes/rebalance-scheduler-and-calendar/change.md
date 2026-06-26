---
id: rebalance-scheduler-and-calendar
title: "F-01: Rebalance scheduler and market calendar gate"
status: implementing
created: 2026-06-26
updated: 2026-06-26
roadmap_ref: F-01
---

## Summary

Deterministic daily scheduler gate for the virtual portfolio rebalance cycle. Includes DST-aware market-open check (NYSE calendar with local holiday table), idempotency via a minimal `rebalance_cycle` DB table, and the `bin/portfolio-rebalance.php` CLI entry point that gates the full rebalance engine.

## Context

Foundation piece for the Virtual Portfolio module (prd-virtual-portfolio.md). Must fire ~30 min before NYSE close, handle DST transitions between Europe/Warsaw and America/New_York, skip non-trading days with `market_closed` log, and prevent duplicate runs within the same cycle window.

## Links

- PRD: `context/foundation/prd-virtual-portfolio.md`
- Roadmap: `context/foundation/roadmap-virtual-portfolio.md` (F-01)
- Plan: `context/changes/rebalance-scheduler-and-calendar/plan.md`
- Brief: `context/changes/rebalance-scheduler-and-calendar/plan-brief.md`
- Unlocks: F-03, S-01, S-02
