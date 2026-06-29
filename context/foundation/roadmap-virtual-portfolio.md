---
project: CVS — Virtual Portfolio with AI-Supported Rebalancing
version: 1
status: draft
created: 2026-06-26
updated: 2026-06-26
prd_version: 1
main_goal: market-feedback
top_blocker: decisions
---

# Roadmap: CVS Virtual Portfolio

> Derived from `context/foundation/prd-virtual-portfolio.md` (v1) + auto-researched codebase baseline.
> Edit in place; archive when superseded.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Vision recap

CVS needs a shared virtual portfolio module that turns CVS screener signals and LLM decisions into observable portfolio actions over time. The product goal is dual-use: public educational sandbox plus internal calibration lab. The key requirement is autonomous daily operation with full auditability, while preserving deterministic CVS scoring and not degrading user-facing analysis UX.

## North star

**S-02: First autonomous rebalance visible to user** — smallest end-to-end slice proving the core hypothesis: the system can decide, execute, and explain a daily virtual rebalance without human approval.

North star here means first user-visible proof that the full workflow works from trigger to understandable result.

## At a glance

| ID   | Change ID                         | Outcome (user can …)                                       | Prerequisites | PRD refs                    | Status   |
| ---- | --------------------------------- | ---------------------------------------------------------- | ------------- | --------------------------- | -------- |
| F-01 | rebalance-scheduler-and-calendar  | (foundation) deterministic schedule + market-open gate     | —             | FR-001, FR-002, FR-010      | ready    |
| F-02 | virtual-portfolio-ledger          | (foundation) portfolio state and immutable cycle ledger    | —             | FR-011, NFR-002, NFR-005    | ready    |
| F-03 | llm-decision-contract-and-retry   | (foundation) strict decision schema + one-retry policy     | F-01, F-02    | FR-005, FR-009, Guardrails  | implemented |
| S-01 | portfolio-readonly-view           | user can see holdings, cash, latest cycle status           | F-02          | US-01, FR-012, FR-017       | implemented |
| S-02 | first-autonomous-rebalance        | user can see first executed BUY/SELL/HOLD/NO_ACTION cycle | F-01, F-02, F-03 | US-03, FR-003, FR-004, FR-006, FR-007, FR-016 | implemented |
| H-01 | portfolio-strategy-hardening      | live CVS swing strategy, hard risk guards, P&L exits, retries, live UX | S-02 | FR-003–010, Guardrails, NFR-002 | implemented |
| S-03 | full-history-and-reason-log       | user can browse full rebalance history with reasons        | S-02          | US-02, FR-011, FR-013       | proposed |
| S-04 | screener-to-portfolio-link        | user can compare held vs recommended symbols               | S-01, S-02    | FR-015, Scope Modified      | proposed |
| S-05 | portfolio-stats-and-performance   | user can see portfolio performance vs start capital        | S-02, S-03    | FR-014, Open Question #4    | proposed |

## Streams

Navigation aid: this groups items sharing dependency chains; canonical order remains in Foundations and Slices.

| Stream | Theme                     | Chain                                | Note |
| ------ | ------------------------- | ------------------------------------ | ---- |
| A      | Core automation           | `F-01` -> `F-03` -> `S-02` -> `S-03` | Main validation path for market feedback. |
| B      | Portfolio surface         | `F-02` -> `S-01` -> `S-04`           | User-facing read model parallel to automation hardening. |
| C      | Performance analytics     | `S-05`                               | Depends on Open Question about metric model; intentionally late. |

## Baseline

What's already in place in the codebase as of 2026-06-26 (auto-researched).

- **Frontend:** partial — server-rendered templates exist, no dedicated virtual-portfolio view yet.
- **Backend / API:** present — multiple controllers and modular namespaces are in place.
- **Data:** present — PDO singleton and migration pattern already used.
- **Auth:** present — `requireAuth`, sessions, role checks and CSRF verification are implemented.
- **Deploy / infra:** present — cron-capable runtime and CI workflow exist.
- **Observability:** partial — structured domain logs are not standardized; mostly `error_log`-based tracing.

## Foundations

### F-01: Rebalance scheduler and market calendar gate

- **Outcome:** (foundation) single deterministic scheduler gate for daily run at US close minus 30 minutes with market-open validation.
- **Change ID:** rebalance-scheduler-and-calendar
- **PRD refs:** FR-001, FR-002, FR-010, NFR-004, Business Logic 1-2
- **Unlocks:** S-02, S-03
- **Prerequisites:** —
- **Parallel with:** F-02
- **Blockers:** —
- **Unknowns:**
  - Which source of US market holidays is canonical (library vs local table)? — Owner: user. Block: no.
- **Risk:** Wrong DST handling causes silent misfire windows and invalid cycle timing.
- **Status:** ready

### F-02: Virtual portfolio ledger contract

- **Outcome:** (foundation) stable persistence contract for positions, cash balance, cycle status, and immutable history rows.
- **Change ID:** virtual-portfolio-ledger
- **PRD refs:** FR-011, FR-017, NFR-002, NFR-005, Business Logic 7
- **Unlocks:** S-01, S-02, S-03, S-04, S-05
- **Prerequisites:** —
- **Parallel with:** F-01
- **Blockers:** —
- **Unknowns:**
  - Should history payload keep full raw LLM response or sanitized reason-only projection? — Owner: user. Block: no.
- **Risk:** Weak event model early will force breaking migrations when history volume grows.
- **Status:** ready

### F-03: LLM decision contract and retry safety

- **Outcome:** (foundation) strict input-output decision schema with guaranteed single retry and explicit failed cycle semantics.
- **Change ID:** llm-decision-contract-and-retry
- **PRD refs:** FR-005, FR-009, Guardrails, US-03 acceptance criteria
- **Unlocks:** S-02, S-03
- **Prerequisites:** F-01, F-02
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - What is the final public reason detail level shown to users? — Owner: user. Block: no.
- **Risk:** Loose contract can produce nondeterministic portfolio transitions and hard-to-debug failures.
- **Status:** proposed

## Slices

### S-01: Portfolio read-only view

- **Outcome:** user can open portfolio page and see holdings, cash, total value, and latest cycle status.
- **Change ID:** portfolio-readonly-view
- **PRD refs:** US-01, FR-012, FR-017
- **Prerequisites:** F-02
- **Parallel with:** F-01, F-03
- **Blockers:** —
- **Unknowns:**
  - Which exact field order is preferred in holdings table for educational readability? — Owner: user. Block: no.
- **Risk:** If this ships too late, feedback on usability starts after backend complexity is already locked.
- **Status:** ready

### S-02: First autonomous rebalance visible to user

- **Outcome:** user can see one completed autonomous rebalance cycle (including no-action path when selected by model).
- **Change ID:** first-autonomous-rebalance
- **PRD refs:** US-03, FR-003, FR-004, FR-005, FR-006, FR-007, FR-008, FR-009, FR-016
- **Prerequisites:** F-01, F-02, F-03
- **Parallel with:** S-01
- **Blockers:** —
- **Unknowns:** —
- **Decisions:**
  - BUY prioritization when cash is insufficient: LLM receives cash constraint in prompt and self-ranks BUY candidates — fully autonomous, consistent with the model-driven philosophy.
- **Risk:** Core hypothesis fails if first cycle cannot consistently produce valid deterministic state transitions.
- **Status:** ready

### S-03: Full history and reason timeline

- **Outcome:** user can browse complete rebalance timeline with action, reason, status and portfolio impact.
- **Change ID:** full-history-and-reason-log
- **PRD refs:** US-02, FR-011, FR-013, Guardrails
- **Prerequisites:** S-02
- **Parallel with:** S-04
- **Blockers:** —
- **Unknowns:**
  - Should failed cycles be displayed inline in same timeline or grouped as separate operational events? — Owner: user. Block: no.
- **Risk:** Audit value drops if history model is not human-readable at first glance.
- **Status:** proposed

### S-04: Screener to portfolio linkage

- **Outcome:** user can compare symbols currently held vs symbols recommended by screener but not held.
- **Change ID:** screener-to-portfolio-link
- **PRD refs:** FR-015, Scope of Change (Modified)
- **Prerequisites:** S-01, S-02
- **Parallel with:** S-03
- **Blockers:** —
- **Unknowns:**
  - Should this comparison live on portfolio screen only, or also expose markers inside screener rows? — Owner: user. Block: no.
- **Risk:** Weak linkage undermines educational value and model-calibration interpretation.
- **Status:** proposed

### S-05: Portfolio stats and performance panel

- **Outcome:** user can view performance against starting 10 000 USD with consistent metric semantics.
- **Change ID:** portfolio-stats-and-performance
- **PRD refs:** FR-014, Open Questions #4
- **Prerequisites:** S-02, S-03
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Decisions:**
  - Primary metric for MVP: simple P&L % vs starting 10 000 USD + benchmark comparison vs SPY (S&P 500).
- **Risk:** Wrong metric choice distorts perceived model quality and breaks comparability over time.
- **Status:** proposed

## Backlog Handoff

| Roadmap ID | Change ID                        | Suggested issue title                               | Ready for `/10x-plan` | Notes |
| ---------- | -------------------------------- | --------------------------------------------------- | --------------------- | ----- |
| F-01       | rebalance-scheduler-and-calendar | Add scheduler and market-open calendar gate         | yes                   | — |
| F-02       | virtual-portfolio-ledger         | Create virtual portfolio ledger and cycle history   | yes                   | — |
| F-03       | llm-decision-contract-and-retry  | Introduce LLM decision schema and retry policy      | no                    | Depends on F-01 and F-02 |
| S-01       | portfolio-readonly-view          | Build read-only portfolio page for logged user      | yes                   | Depends on F-02 |
| S-02       | first-autonomous-rebalance       | Deliver first autonomous rebalance visible in UI    | yes                   | BUY priority: LLM self-ranks with cash constraint in prompt |
| S-03       | full-history-and-reason-log      | Add full timeline of rebalance decisions and reasons| no                    | Depends on S-02 |
| S-04       | screener-to-portfolio-link       | Link screener recommendations with held positions   | no                    | Depends on S-01 and S-02 |
| S-05       | portfolio-stats-and-performance  | Add portfolio performance panel vs starting capital | no                    | Metric: P&L % + SPY benchmark; depends on S-02, S-03 |

## Open Roadmap Questions

1. **US market calendar source** — Owner: user. Block: F-01 partial (implementation detail, not sequencing blocker).
2. **BUY prioritization under insufficient cash** — **DECIDED:** LLM self-ranks BUY candidates when cash constraint is passed in prompt. Block: resolved.
3. **Public reasoning detail level** — Owner: user. Block: roadmap-wide no, affects S-03 UX semantics.
4. **Canonical stats metric for MVP** — **DECIDED:** simple P&L % vs 10 000 USD starting capital + benchmark vs SPY. Block: resolved.

## Parked

- **Real broker integration** — Why parked: PRD Non-Goal #1.
- **Manual per-transaction approval flow** — Why parked: PRD Non-Goal #2.
- **Transaction-cost and slippage simulation** — Why parked: PRD Non-Goal #3.
- **Per-user private portfolio variants** — Why parked: PRD Non-Goal #4.
- **History retention caps/archiving policy** — Why parked: PRD Non-Goal #5.

## Done

- —
