# Critical Review Loading Modal — Implementation Plan

## Overview

Extends the existing stage-1 AI generation modal pattern (`#ai-modal` — full-screen
overlay, spinner, rotating stage-message text) to the critical-review flow (Claude
and Gemini), and extracts the show/hide/stage-rotation logic into one shared,
reusable JS helper so this becomes the standing pattern for any future async
model-processing trigger on the analysis page — not three copies of the same logic.

## Current State Analysis

- Stage-1 AI generation (`templates/analysis.php`) has a single, static `#ai-modal`
  div (full-screen dimmed overlay, `.ai-modal__spinner`, `#ai-modal-status` rotating
  text via `setInterval`, a `#btn-ai-cancel` dismiss button that only hides the
  modal — the underlying fetch keeps running). `showModal()`/`hideModal()` are
  free functions tightly coupled to those specific element ids and to stage-1's
  own `stages` array.
- Critical review (Claude + Gemini, change: critical-review-models) has NO modal —
  only an inline placeholder `<p id="critical-review-placeholder-{provider}">`
  next to the trigger button, whose text rotates through `crStages` via
  `crShowMessage(provider, text, isError)` + a `stageTimer` inside `crStartPolling()`.
  The button is disabled for the duration; the placeholder is also reused (with
  `isError=true`) to show the final error/timeout message once polling stops.
- The `.ai-modal` / `.ai-modal__inner` / `.ai-modal__spinner` / `.ai-modal__status`
  CSS classes (`public/css/app.css`) are already generic — no new CSS is needed for
  a second or third modal instance, only new HTML nodes with new ids.
- Since `critical-review-models`, Claude and Gemini can generate concurrently for
  the same ticker — a single shared modal instance cannot represent two
  independent in-flight jobs at once.

### Key Discoveries:

- `templates/analysis.php:1291-1304` (`showModal()`/`hideModal()`) — the exact
  logic to generalize; the rotation interval (7000ms, stage-1) and the critical-
  review `stageTimer` (8000ms) are already close cousins of the same pattern.
- `templates/analysis.php` critical-review JS (`crState`, `crStartPolling`,
  `crStopPolling`, `crShowMessage`) — provider-keyed already (per
  `critical-review-models`), the natural place to attach a per-provider modal
  instance.
- No new CSS: `.ai-modal` chrome is reused as-is for the two new instances.

## Desired End State

Triggering a critical review (either tab) shows the same full-screen modal
experience as stage-1 generation — spinner, rotating stage text, a dismiss button —
for the entire duration of that provider's job, closing only when polling detects
`completed` or `failed`. Triggering both providers shows two independent job
lifecycles (each modal opens/closes on its own provider's completion). The inline
placeholder next to each button no longer rotates its own stage text during
generation (the modal owns that signal now, exactly mirroring stage-1, which has
no inline equivalent) — it's reserved for the final error/timeout message, as it
already is today for that case. One shared JS helper backs all three modal
instances (stage-1, Claude, Gemini), so a future fourth async flow reuses it
instead of copying show/hide/rotation logic a fourth time.

Verify by: triggering a Claude review, confirming the modal opens immediately with
rotating text and closes on completion; triggering Gemini while Claude is still
running, confirming Gemini's modal opens independently without disturbing Claude's;
dismissing a modal mid-generation and confirming the job still completes normally
(button re-enables, content renders) even though the modal was closed early.

## What We're NOT Doing

- Not changing stage-1's user-visible behavior — same stages, same 7000ms cadence,
  same modal chrome; only the underlying implementation moves to the shared helper.
- Not adding a mechanism to actually cancel/abort an in-flight generation — the
  dismiss button only hides the modal, identical to stage-1's existing
  `#btn-ai-cancel` semantics.
- Not solving the rare triple-concurrency case (stage-1 + both critical-review
  providers all mid-generation at once) with a stacking/toast layout — each modal
  is still a full-screen centered overlay; if more than one happens to be open at
  once, the most recently shown one visually covers the earlier one (acceptable
  given this is an edge case, not the primary design target).
- Not touching the CSS — `.ai-modal` and friends are already generic enough for
  three instances.

## Implementation Approach

Extract a small factory, `createProcessingModal(modalId, statusId, stages,
intervalMs)`, returning `{show, hide}` bound to one static HTML modal instance.
Refactor stage-1's `showModal()`/`hideModal()` to call it (behavior-preserving).
Add two new static modal divs (`critical-review-modal-claude`,
`critical-review-modal-gemini`) using the same `.ai-modal` chrome, each with its
own dismiss button. Instantiate one `createProcessingModal(...)` per provider
inside the existing `CR_PROVIDERS.forEach` setup loop (alongside the rest of
`crState`), reusing the existing `crStages` array for both (the label set varies
by *section* of the analysis — stage-1 vs critical review — not by provider,
matching the confirmed design decision). Wire `.show()` into `crStartPolling()`
and `.hide()` into `crStopPolling()`. Remove the `stageTimer`/`crShowMessage`
rotation call from `crStartPolling()` — the modal now owns that signal — leaving
`crShowMessage` for its remaining job: the final error/timeout message.

## Phase 1: Shared modal helper + stage-1 refactor + critical-review wiring

### Overview

One phase — small, single-file scope (`templates/analysis.php`), no CSS or backend
changes.

### Changes Required:

#### 1. Shared JS helper

**File**: `templates/analysis.php` (inline `<script>` block, defined before its first use — ahead of stage-1's existing modal code)

**Intent**: A small factory function that owns one modal instance's show/hide/
stage-rotation lifecycle, so stage-1, Claude, and Gemini (and any future async
model-processing trigger on this page) all call the same implementation instead
of each keeping its own copy.

**Contract**: `function createProcessingModal(modalId, statusId, stages, intervalMs)` returning `{ show: function(), hide: function() }`. `show()` resets to `stages[0]`, unhides the modal, and starts the rotation interval; `hide()` clears the interval and hides the modal. Null-safe if either element id is missing from the DOM (mirrors the existing defensive `if (modal) ...` style already used elsewhere in this file).

#### 2. Stage-1 refactor

**File**: `templates/analysis.php` (existing `showModal()`/`hideModal()`/`stageTimer`/`stageIdx` block, ~line 1275-1304)

**Intent**: Replace the inline show/hide/rotation logic with one `createProcessingModal('ai-modal', 'ai-modal-status', stages, 7000)` instance; replace call sites (`showModal()`/`hideModal()` in `doGenerate()`) with the instance's `.show()`/`.hide()`. No behavior change — same element ids, same stages array, same interval.

**Contract**: `aiModal = createProcessingModal('ai-modal', 'ai-modal-status', stages, 7000)`; `doGenerate()` calls `aiModal.show()` / `aiModal.hide()` in place of the removed free functions.

#### 3. New modal markup (Claude + Gemini)

**File**: `templates/analysis.php` (near the other modals — `#pro-modal`, `#ai-modal`, `#share-modal` — inside the existing `<?php if (!empty($cachedAi)): ?>` block where `$crProviderLabels` is already in scope)

**Intent**: Two new static modal divs, one per provider, using the exact same `.ai-modal` chrome as stage-1's, plus a dismiss button matching stage-1's `#btn-ai-cancel` (hide-only, doesn't stop the background job).

**Contract**: Generated via a `foreach ($crProviderLabels as $crProviderKey => ...)` loop — `id="critical-review-modal-<?= $crProviderKey ?>"` (the modal), `id="critical-review-modal-status-<?= $crProviderKey ?>"` (the rotating text), a `.cr-modal-dismiss-btn` button with `data-provider="<?= $crProviderKey ?>"`.

#### 4. Wire critical-review JS to the shared helper

**File**: `templates/analysis.php` (existing `CR_PROVIDERS.forEach` setup loop, `crStartPolling()`, `crStopPolling()`)

**Intent**: Each provider's `crState[provider]` gains a `modal` instance created via `createProcessingModal('critical-review-modal-' + provider, 'critical-review-modal-status-' + provider, crStages, 8000)`. `crStartPolling(provider)` calls `st.modal.show()` in place of its current `crShowMessage(provider, crStages[0], false)` + `stageTimer` setup; `crStopPolling(provider)` calls `st.modal.hide()`. Wire the new dismiss buttons (`.cr-modal-dismiss-btn`, one per provider) to call that provider's `st.modal.hide()` only — the poll timer keeps running underneath, unaffected (mirrors stage-1's cancel semantics exactly).

**Contract**: `crState[provider].modal = createProcessingModal(...)`; `crStartPolling()`/`crStopPolling()` bodies updated to call `.show()`/`.hide()` instead of the removed inline rotation; `crShowMessage()` itself is unchanged (still used for the post-poll error/timeout text) but is no longer called from `crStartPolling()`'s stage-rotation path.

### Success Criteria:

#### Automated Verification:

- `php -l templates/analysis.php` passes
- `composer stan` reports no new errors (unaffected — template isn't PHPStan-scoped, but this confirms no accidental PHP-side breakage)

#### Manual Verification:

- Triggering stage-1 AI generation still shows the modal exactly as before (same stages, same cadence, closes on completion) — regression check
- Triggering a Claude critical review shows its own modal immediately, with rotating text, closing only when that job completes or fails
- Triggering Gemini while Claude is still generating shows Gemini's own modal independently — Claude's modal (if still open) is unaffected
- Dismissing a critical-review modal mid-generation (via the new dismiss button) hides only that modal; the job keeps running in the background and completes normally (button re-enables, content renders, no orphaned state)
- The inline placeholder next to each critical-review button no longer shows rotating stage text during generation; it still shows the correct message on failure/timeout
- No visual or functional regression elsewhere on the analysis page

---

## Testing Strategy

### Unit Tests:

- None — this is a template/JS-only change with no PHP logic to unit test (mirrors the project's existing convention of manual verification for template/JS work).

### Integration Tests:

- None new.

### Manual Testing Steps:

1. On `/analysis/{ticker}` as a PRO user with no cached stage-1 analysis, trigger stage-1 generation and confirm the modal behaves exactly as before.
2. Trigger a Claude critical review; confirm the Claude modal opens immediately and shows rotating stage text.
3. While Claude is still generating, switch to the Gemini tab and trigger a Gemini review; confirm a second, independent modal opens for Gemini without disturbing Claude's.
4. Dismiss the Gemini modal via its dismiss button; confirm the page returns to normal (button still disabled, no modal), and that Gemini's review still completes and renders correctly once done.
5. Let a review reach `failed` or the 5-minute timeout; confirm the inline placeholder shows the correct error/timeout text (unchanged from before this change).

## Performance Considerations

None — this is a client-side-only UI change with no new network calls or backend load.

## Migration Notes

Not applicable — no data model or deployment changes.

## References

- Prior art: `templates/analysis.php` stage-1 modal (`#ai-modal`, `showModal()`/`hideModal()`)
- Prior art: `context/changes/critical-review-models/plan.md` — the provider-keyed `crState` pattern this phase extends

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Shared modal helper + stage-1 refactor + critical-review wiring

#### Automated

- [x] 1.1 `php -l templates/analysis.php` passes — 39c47ef
- [x] 1.2 `composer stan` reports no new errors — 39c47ef

#### Manual

- [ ] 1.3 Stage-1 modal behaves exactly as before (regression check)
- [ ] 1.4 Claude critical-review modal opens/closes correctly, independent lifecycle
- [ ] 1.5 Gemini modal opens independently while Claude is still generating
- [ ] 1.6 Dismiss button hides the modal only; job completes normally in the background
- [ ] 1.7 Inline placeholder no longer rotates during generation; still correct on failure/timeout
- [ ] 1.8 No regression elsewhere on the analysis page
