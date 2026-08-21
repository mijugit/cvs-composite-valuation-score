# Fundamentals Validation — Plan Brief

> Full plan: `context/changes/fundamentals-validation/plan.md`
> Research: `context/changes/fundamentals-validation/research.md`
> PRD: `context/foundation/prd-fundamentals-validation.md`

## What & Why

Yahoo Finance sometimes returns fundamental-data fields that are wrong but **not NULL** —
confirmed live: `days_since_earnings` off by ~37x for real tickers, `free_cash_flow` internally
impossible (higher than `operating_cash_flow`). Nothing in the system today checks for this; the
only quality gate is a single NULL-check on `revenue`. We add an admin-only, per-ticker "validate"
action that flags suspect fields locally (free, no LLM), sends only those to Gemini with web
search, shows a diff for review, and — once confirmed — persists the correction with priority
over Yahoo and rescoring that one ticker.

## Starting Point

`FinancialDataFetcher::fetch()` pulls ~50 fields live from Yahoo on every call; nothing is ever
persisted raw, so there's no history to audit for staleness. The only existing quality check
(`PayloadCompleteness`) looks at one field. No code path rescoring a single ticker (outside the
full nightly batch) exists. An existing, working async-job pattern ("Recenzja krytyczna") and a
generic, reusable Gemini client (already supporting web search) both already exist and are copied
wholesale rather than reinvented.

## Desired End State

An admin opens any ticker's analysis page, sees suspect/missing fields highlighted red in the
existing "raw data" section, clicks one of two buttons ("check missing" / "check everything"),
watches a background job run, reviews an inline old-vs-new diff, and confirms it. The confirmed
values become permanent overrides (until manually re-triggered), the ticker rescoring immediately
with full parity to the nightly batch (score, snapshot, ATR zone, alert digest), and the nightly
batch picks up the override automatically from then on with zero code changes of its own.

## Key Decisions Made

| Decision | Choice | Why | Source |
|---|---|---|---|
| Override table shape | One row per (ticker, field) | Trivial per-field lookup for UI coloring | Plan (asked) |
| Diff presentation | In-place in existing raw-data table | One view, no new component | Plan (asked) |
| Rescore side effects | Full parity with nightly batch (ATR + alerts) | Consistency over minimalism | Plan (asked) |
| Confirm flow | AJAX in-page, no reload | Matches existing critical-review UX | Plan (asked) |
| Partial LLM failure | Partial success — apply what came back | No wasted progress | Plan (asked) |
| Field color states | Three (suspect/validated/checked-no-data) | Distinguishes "never checked" from "checked, truly absent" — matches lessons.md | Plan (asked) |
| Test scope | Unit only, offline, fake HTTP transport | Matches every existing AI-client test in this codebase | Plan (asked) |
| MA200 data source | Wider one-off fetch, validation-worker-only | Zero cost impact on hot path (batch/every page load) | Plan (research finding) |
| Earnings fields sent to LLM | Dates, not day-counts | Avoids LLM guessing "today"; day-count derived locally | Plan (from manual experiment) |
| LLM provider | Gemini only, reused client | Already integrated, already supports web search | Shape/PRD |
| Mechanism | Manual per-ticker trigger, not a daily cron | Original idea rejected during shaping — smaller, safer MVP | Shape/PRD |

## Scope

**In scope:**
- Local, free consistency + cadence rules flagging suspect fields
- Local MA200 calculation (pure math, no LLM)
- Admin-triggered Gemini validation with web search, for one ticker at a time
- Review-before-apply diff UI
- Permanent override storage + merge into scoring
- Full-parity single-ticker rescore (score, snapshot, ATR zone, alerts)

**Out of scope:**
- Automatic/scheduled validation across the whole universe
- TTL/expiry on applied overrides
- Any LLM provider other than Gemini in this flow
- Validating fields that don't feed CVS scoring
- Widening the default fetch for every caller
- Integration tests against the live Gemini API

## Architecture / Approach

Two new tables separate **proposed** state (`fundamental_validation_runs` — one pending/completed/
failed job per ticker, holding the not-yet-applied diff) from **applied** state
(`fundamental_overrides` — confirmed values actually merged into scoring) — mirroring the existing
`ai_critical_reviews`-vs-`cvs_snapshots` split. A single `FundamentalFieldRegistry` class is the
one source of truth for which fields are in scope and their types, referenced by the detector, the
merger, and the prompt-builder so the whitelist can't drift between them.

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. Data layer & local rules | Two migrations, suspect-field detector, override merge/repo, local MA200 calc — fully unit-tested, no LLM/UI | Getting the "suspect but not every NULL" distinction wrong |
| 2. Gemini validation service | Prompt-builder + response parser reusing `GeminiClient`, job-status repo | JSON parsing robustness against a non-conforming response |
| 3. Single-ticker rescore | Composer mirroring the nightly batch's per-ticker body | Missing a batch side-effect (ATR/alerts) the confirm flow needs |
| 4. Async worker + endpoints | Trigger/poll/confirm, admin+CSRF gated | Confirm endpoint doing too much in one request |
| 5. UI | Extended raw-data table, coloring, two buttons, diff/confirm JS | The `continue`-on-null removal accidentally un-hiding unrelated noise |

**Prerequisites:** None outside this repo — all dependencies (Gemini client, async pattern,
admin-auth pattern) already exist and are proven in production.
**Estimated effort:** ~1-2 weeks after-hours, 5 phases, matches the PRD's `delivery_weeks: 2`.

## Open Risks & Assumptions

- Gemini's plain-text JSON response (no enforced schema — `GeminiClient` doesn't support
  `responseSchema`) is assumed reliable enough based on the manual 3-provider experiment; if it
  degrades in practice, Phase 2's parser needs to get more defensive, not the architecture.
- Full ATR/alert parity on manual rescore means the confirm endpoint does meaningfully more work
  per request than the trigger/poll pair — worth watching request latency once implemented.
- The `SuspectFieldDetector`'s `SUSPECT_CADENCE_DAYS = 150` threshold is a judgment call, not
  empirically tuned across the whole ticker universe — may need adjustment after real-world use
  surfaces false positives/negatives on non-US fiscal calendars.

## Success Criteria (Summary)

- An admin can validate, review, and apply a correction for one ticker end-to-end without touching
  code or the database directly.
- The nightly batch continues to run unmodified and correctly picks up any applied override.
- A field that's correctly NULL (e.g. `trailing_pe` on negative EPS) is never flagged as suspect.
