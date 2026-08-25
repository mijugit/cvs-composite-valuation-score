# Critical Review Loading Modal — Plan Brief

> Full plan: `context/changes/critical-review-loading-modal/plan.md`

## What & Why

Stage-1 AI generation already shows a nice full-screen "working on it" modal
(spinner + rotating stage text). Critical review (Claude/Gemini) only has a
small inline placeholder next to the button — this extends the same modal
experience to critical review, and establishes it as the standard for any
future async model-processing trigger on the analysis page.

## Starting Point

`#ai-modal` (stage-1) is a static HTML modal with free-standing
`showModal()`/`hideModal()` functions tightly coupled to its own element ids
and stage array. Critical review's async trigger→poll flow (Claude + Gemini,
independently, per `critical-review-models`) has no modal — just an inline
rotating placeholder text next to each provider's button.

## Desired End State

Triggering either critical-review provider opens the same modal experience as
stage-1: spinner, rotating stage text, dismiss button — open for the full
job duration, closing on completion/failure. Since providers can run
concurrently, each has its own independent modal instance. One shared JS
helper backs all three modal instances (stage-1 + 2 providers), so a fourth
future async flow reuses it rather than copying the logic again.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|---|---|---|---|
| Concurrent providers | Separate modal instance per provider | Claude and Gemini can genuinely run at once — a shared modal can't represent two independent jobs | Plan (user-selected, recommended) |
| Modal duration | Full duration — closes only on completion/failure | Matches stage-1's existing pattern exactly, one consistent mental model everywhere | Plan (user-selected, non-default) |
| Relationship to inline placeholder | Modal owns rotating progress; inline placeholder keeps only its error/timeout role | Mirrors stage-1 exactly (which has no inline rotation at all) — confirmed explicitly after a clarifying round | Plan (user-confirmed) |
| Reusability | One shared JS factory (`createProcessingModal`) used by all 3 flows, including a refactor of stage-1's existing code | This IS a standard (single implementation), not a convention to remember — matches this project's own recorded lesson that duplicated logic always drifts | Plan (user-selected, recommended) |
| CSS | No new styles — reuse `.ai-modal` chrome as-is | Already generic enough for a second/third instance | Plan |

## Scope

**In scope:**
- `createProcessingModal()` shared JS helper
- Stage-1 refactor to use it (behavior-preserving)
- Two new modal instances (Claude, Gemini) wired into the existing `crState`/`crStartPolling`/`crStopPolling` provider-keyed pattern
- Dismiss buttons on the new modals (hide-only, mirrors stage-1's cancel)

**Out of scope:**
- Actually cancelling/aborting an in-flight generation
- Toast-stacking layout for the rare case where 3 modals are open at once
- Any CSS changes
- Any backend/data-model changes

## Architecture / Approach

A small factory function owns one modal's show/hide/rotation lifecycle,
parametrized by element ids, a stage-message array, and a rotation interval.
Stage-1 and both critical-review providers each get their own instance of it.
Critical review's existing provider-keyed `crState` object gains a `modal`
field per provider; `crStartPolling`/`crStopPolling` call `.show()`/`.hide()`
on it instead of the old inline rotation logic.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Shared helper + refactor + wiring | Modal for both providers, stage-1 unchanged behaviorally | Regressing stage-1's working modal during the refactor |

**Prerequisites:** None — builds directly on `critical-review-models` (already deployed).
**Estimated effort:** Single session, one phase, one file.

## Open Risks & Assumptions

- The rare case of stage-1 + both providers all generating simultaneously (3
  modals open at once) isn't specially handled — the most recent one visually
  covers the others. Explicitly accepted as out of scope.

## Success Criteria (Summary)

- Both critical-review providers show a modal identical in style/behavior to
  stage-1's, opening on trigger and closing on completion/failure.
- Triggering both providers shows two independent modal lifecycles.
- No regression to stage-1's existing modal behavior.
