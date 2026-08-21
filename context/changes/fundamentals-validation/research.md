---
date: 2026-08-21T12:20:10+02:00
researcher: Claude
git_commit: 56063d116c089095e68df1ba7097983bdcee87e9
branch: main
repository: mijugit/cvs-composite-valuation-score
topic: "Fundamentals validation — grounding for prd-fundamentals-validation.md"
tags: [research, codebase, async-worker, gemini-client, cvs-snapshots, admin-auth, csrf]
status: complete
last_updated: 2026-08-21
last_updated_by: Claude
---

# Research: Fundamentals validation — implementation grounding

**Date**: 2026-08-21T12:20:10+02:00
**Researcher**: Claude
**Git Commit**: 56063d116c089095e68df1ba7097983bdcee87e9
**Branch**: main
**Repository**: mijugit/cvs-composite-valuation-score

## Research Question

Given `context/foundation/prd-fundamentals-validation.md` (admin-only, per-ticker LLM validation of suspect fundamental-data fields, with review-before-apply and single-ticker rescore), what exact existing code, patterns, and conventions must the implementation plan reuse or extend? Specifically:

1. The existing async background-worker pattern ("Recenzja krytyczna") — full end-to-end shape.
2. The existing Gemini API integration — is it reusable as-is for a new prompt/use-case?
3. How is a CVS score persisted to `cvs_snapshots` today, and does a single-ticker (non-batch) rescore-and-persist path already exist?
4. Admin-only route/auth/CSRF conventions for two new endpoints (trigger validation, confirm/apply diff).

## Summary

All four dimensions have direct, faithful precedents in the codebase — nothing here requires inventing a new architectural pattern:

- **Async worker**: `AiAnalysisController::criticalReview()` + `bin/generate_critical_review.php` + `AiCriticalReviewRepository` is a complete, working `markPending → exec(cmd.' &') → markCompleted/markFailed → poll` reference implementation. Copy its shape exactly.
- **Gemini client**: `src/Ai/GeminiClient.php` is a generic, reusable, typed transport client (mirrors `ClaudeClient`) — **not** `LlmGeminiService` (that's wallet-execution-only, a dead end for this feature). It already supports live web-search grounding via `options['tools']`, already wired end-to-end and exercised by `LlmGeminiContextGatherer`. No new HTTP client needed — only a new prompt-builder service.
- **Snapshot persistence**: `SnapshotWriter::persist()` → `CvsSnapshotRepository::save()` is the only writer of `cvs_snapshots`. **No single-ticker persist path exists today** — `AnalysisController::show()` and `AiAnalysisController` compute a `CVSResult` for display but never persist it. This must be built fresh, by direct composition of `FinancialDataFetcher` + `CVSModel` + `SnapshotWriter`, mirroring `bin/rescore.php`'s wiring exactly (including the `peer_bucket_override` merge-before-`calculate()` precedent, which is the template for merging `fundamental_overrides`).
- **Admin/CSRF**: No shared `requireAdmin()` helper exists on `AuthController` — every controller re-reads `is_admin` fresh from `UserRepository`, never trusting `$_SESSION['is_admin']` for the actual gate (that flag is documented as UI-hint-only). CSRF via `Request::verifyCsrf()` accepts both a POST field and an `X-CSRF-Token` header, already used by the exact AJAX trigger+poll pattern we need to copy.

One real gap surfaced: `PayloadCompleteness::missingEssentialFields()` is **only** called from `bin/rescore.php` today — no single-ticker code path calls it. The new single-ticker rescore path must add this call explicitly (per PRD FR-012), since there's no existing single-ticker precedent to copy for it.

## Detailed Findings

### 1. Existing async worker pattern ("Recenzja krytyczna")

**Trigger controller** — [`AiAnalysisController::criticalReview()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/AiAnalysisController.php#L296-L373):
- Order of checks (all fail fast, JSON + status code, never throws): `AuthController::requireAuth()` → `$req->verifyCsrf()` (403) → ticker non-empty (400) → DI sanity (500) → `function_exists('exec')` (500, "niedostępne na tym serwerze") → stage-1 analysis freshness (409) → `criticalReviewRepo->isPending($ticker)` (409, "już w trakcie generowania" — **duplicate-trigger guard, copy this**) → PRO gate (403).
- Then: usage-log at 0/0 tokens (quota-guard against duplicate rapid POSTs) → `markPending($ticker, $userId)` **before** firing the process → build `exec()` command with hardcoded PHP 8.2 binary path (`/usr/local/bin/php82`), output redirected to a dedicated log file, `exec($cmd . ' &')` to detach → `Response::json(['ok'=>true,'status'=>'pending'], 202)`.

**Poll controller** — [`criticalReviewStatus()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/AiAnalysisController.php#L379-L433): auth-only (GET, no CSRF needed), reads via `findByTicker()`, shapes JSON by `status` (`completed`/`failed`/`pending`/`none`).

**Background worker script** — [`bin/generate_critical_review.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/bin/generate_critical_review.php): CLI-only guard, dedicated log file (never `error_log()` — matches `lessons.md`'s cron-logging rule), loads `.env` manually, sets `$_SESSION = []` (no HTTP session in CLI — same workaround `bin/rescore.php` uses), does the slow work, calls `markCompleted()`/`markFailed()` on the repository.

**Repository** — [`AiCriticalReviewRepository`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/AiCriticalReviewRepository.php): table `ai_critical_reviews` ([migration 030](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/database/migrations/030_create_ai_critical_reviews.sql#L17-L33)), `UNIQUE KEY uq_ticker`, columns `status` (pending/completed/failed), `content`, `sources` (JSON), `error_message`, `model`, `tokens_input/output`, `generated_by`, `started_at`, `generated_at`. `markPending()` does INSERT-or-UPDATE-on-duplicate-key, deliberately leaves `content`/`sources` untouched. **Next free migration number is 039.**

**Frontend polling** — `templates/analysis.php:1498-1671` (all inline JS, IIFE): `crHandleClick()` POSTs with CSRF (both body field and header), `crStartPolling()` sets a 5s interval with a rotating cosmetic "stage" label plus a 5-minute hard-cap wall-clock timeout, `crPoll()` GETs the status endpoint and stops on `completed`/`failed`, swallows transient network errors, `crRenderCompleted()` does client-side markdown-ish rendering. **Resume-on-reload**: if server-rendered initial status is `pending`, JS auto-resumes polling on page load — important for our feature too, since the admin might navigate away mid-validation.

**Button gating** — `templates/analysis.php:1040-1049`: gated on `$canGenerateAi` (the **PRO** gate), **not** admin. No `is_admin` reference exists anywhere in `templates/analysis.php` today — our new buttons need their own admin-gated conditional block, following `templates/layout.php`'s pattern (see §4), not this PRO-gate pattern.

**"Dane źródłowe (surowe)" section** — `templates/analysis.php:748-797`, inside the tail of the same `card--result` div as the pillar-breakdown table (not its own card). It's a native `<details>/<summary>` disclosure, iterating a **hardcoded ordered associative array** `$rawFields` (25 entries: `current_price`, `revenue`, `ebitda`, `free_cash_flow`, `total_debt`, `gross_margins`, `pe_ratio`, `sector`, etc. — notably this list does **not** include `days_since_earnings`/`days_to_earnings`/`earnings_state`, which live elsewhere in the template as a chip, not in this raw table). Null-valued fields are silently skipped (varies per ticker). Each value goes through a `$fmtRaw($key, $val)` formatter (ratio→%, magnitude→B/M/K suffix). **No per-row markup hooks exist today** (no `data-field` attribute, no wrapper) — adding suspect/validated color-coding requires adding markup to this loop, e.g. `data-field="<?= $key ?>"` plus a CSS class keyed off a validation-status array the controller will need to compute and pass into the view.

### 2. Existing Gemini API integration

**Correction to an assumption in shape-notes/PRD**: `src/LlmGemini/LlmGeminiService.php` is **not** the Gemini HTTP client — its docblock says "Atomic write model for the LLM_Gemini_Wallet"; it only executes BUY/SELL/HOLD wallet transactions against PDO, never touches HTTP. The reusable client is [`src/Ai/GeminiClient.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/GeminiClient.php) (275 lines), invoked by `LlmGeminiDecisionService` (prompt/orchestration) and `LlmGeminiContextGatherer` (search-grounded lookup).

- Deliberately mirrors `ClaudeClient`'s public contract (stated in its own docblock: "callers built against one client can be copied to the other with only the client class swapped") — same `HttpTransport`/`CurlTransport` seam, same typed `AiResult`/`AiUsage`/`AiFailureKind`/`CacheableSystem` value objects, same `XClientFactory::fromConfig()` pattern, same never-throws contract, same exponential-backoff-within-`total_timeout`-budget retry shape.
- `sendMessage(array $messages, ?CacheableSystem $system, array $options)` is **fully generic** — no hardcoded wallet schema. `LlmGeminiContextGatherer` proves a second, independent prompt/use-case already reuses the exact same client class with zero wrapper.
- **Recommendation confirmed by research**: write only a new thin prompt-builder/response-parser service (analogous to `LlmGeminiDecisionService` or `LlmGeminiContextGatherer`) that calls `GeminiClientFactory::fromConfig($geminiConfig)->sendMessage(...)` directly — do not build a new HTTP client.
- **Config** — [`config/gemini.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/config/gemini.php#L17-L45): API key env var is `Gemini_CVS` (legacy, case-sensitive — not `GEMINI_API_KEY`), model `gemini-3.7-flash`, `timeout=180s`/`total_timeout=200s`/`max_retries=2` (deliberately generous — this client is cron/background-only today, unlike Claude's <30s user-facing budget).
- **Web search / grounding — already wired end-to-end**: `LlmGeminiContextGatherer::search()` passes `options['tools'] => [['googleSearch' => new \stdClass()]]`, and `GeminiClient::parseSuccess()` already extracts grounding citations from `candidates[0].groundingMetadata.groundingChunks[].web.{uri,title}` into `AiResult::$citations`. **This is exactly what our feature needs** (current company financials, not training-data recall) — mirror `ContextGatherer`'s pattern, not `LlmGeminiDecisionService`'s (which deliberately omits `tools` — a plain, non-grounded completion over the wallet's own data block).

### 3. Snapshot persistence and single-ticker rescore path

**`bin/rescore.php` pipeline** (per-ticker loop, [`bin/rescore.php:161-253`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/bin/rescore.php#L161-L253)):

```
fetch($ticker) → null? skip
  → PayloadCompleteness::missingEssentialFields() → non-empty? skip (no persist)
  → TickerIdentity::driftWarning() → log only, never skips
  → merge $financials['peer_bucket_override']   ← PRECEDENT for our override merge
  → CVSModel::calculate($ticker, $financials)
  → FairPriceCalculator::compute()
  → SnapshotWriter::persist($result, $price, $sector, $industry, ORIGIN_RESCORE, ...)
  → ATR zone cache upsert
  → AlertService::checkAndNotify() (queues; flushed once at end of whole run)
```

The actual writer is [`CvsSnapshotRepository::save()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/TrackRecord/CvsSnapshotRepository.php#L65-L220) — INSERT, with UPDATE-on-duplicate-key fallback keyed on `(ticker, score_date, model_version-NULL-safe, origin)`. It is wrapped by [`SnapshotWriter::persist()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/TrackRecord/SnapshotWriter.php#L48-L99), which fans one `CVSResult` out into a base row (live `model_version`) plus one row per shadow `model_version` (3.1/3.2 overlays) — **use `SnapshotWriter::persist()`, never call `CvsSnapshotRepository::save()` directly**, so the shadow-version fan-out and `model_version` stamping stay correct automatically.

**No single-ticker persist path exists today.** Searched every call site: `AnalysisController::show()` (single-ticker detail page) and `AiAnalysisController::generate()`/`sharePrompt()` all call `fetch()` + `CVSModel::calculate()` for display/prompting, but **never** call `SnapshotWriter::persist()` — they only *read* from `cvs_snapshots` (`findTrajectory()`). `AnalysisController::analyse()` persists to `analysis_history` (a separate per-user log table, migration 003) via `HistoryRepository::save()` — not `cvs_snapshots`. **The new feature's single-ticker rescore-and-persist path must be built fresh**, by directly instantiating `FinancialDataFetcher` + `CVSModel` + `SnapshotWriter` and mirroring `bin/rescore.php`'s exact wiring (same origin constant, same fair-value computation, same ATR/alert side effects if desired).

**Override merge point**: right after `fetch()` returns, before both `PayloadCompleteness::missingEssentialFields()` and `CVSModel::calculate()` — the identical insertion point `bin/rescore.php:204-207` already uses for `peer_bucket_override`. The new `fundamental_overrides` merge should sit alongside that existing merge, inside the new single-ticker code path (never inside `FinancialDataFetcher`/`CVSModel` themselves — both stay override-unaware, consistent with the existing precedent).

**Gap to close explicitly**: `PayloadCompleteness` is *only* called from `bin/rescore.php` — no single-ticker call site exists to copy. PRD FR-012 requires it to still gate the merged (override + fresh) data; this call must be added new to the single-ticker path, it is not "reuse an existing call," it's "add the first single-ticker call following the batch path's precedent."

**Migration conventions** (next number **039**): `CREATE TABLE IF NOT EXISTS`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, inline `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (current style per 035/037/038), `ticker VARCHAR(20) NOT NULL`, admin-attribution columns (`created_by INT UNSIGNED NULL COMMENT '...'`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NULL`), `uq_<table>_<col>`/`idx_<table>_<col>` naming, substantial why-comment header. [`037_create_peer_bucket_override.sql`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/database/migrations/037_create_peer_bucket_override.sql) is the closest template (admin-managed, per-ticker, audit-trail columns) — the plan needs to decide one-row-per-(ticker,field) vs. one-row-per-ticker-with-JSON-blob, since 037 is single-purpose-per-ticker and doesn't disambiguate that choice for us.

**`cvs_snapshots` UNIQUE-key / `model_version` history** (cross-checked against `lessons.md`'s two relevant rules): current key is `uq_ticker_day_version_origin (ticker, score_date, model_version, origin)` ([migration 016](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/database/migrations/016_add_origin_to_snapshots.sql#L19)). `model_version` is still schema-nullable (never migrated to `NOT NULL`) — the "never let it be NULL" rule from `lessons.md` is enforced at the **application layer** only, via `CVSResult::failed()` always stamping the live version. `origin` **is** `NOT NULL DEFAULT 'rescore'`. Any "latest snapshot" read the new feature adds (e.g., to show the admin the pre-validation score for the diff) must filter by live `model_version`, per the shadow-row lesson — using `SnapshotWriter::persist()` end-to-end avoids reimplementing this incorrectly.

### 4. Admin route / auth / CSRF conventions

**Routing** ([`src/Core/routes.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Core/routes.php)): routes grouped under `// ---` banner comments (optionally tagged `change: <slug>`), controllers instantiated once at top, thin `fn($req) => $controller->method($req)` closures. `/admin/*` prefix for form-based admin CRUD (`TickersController`, lines 126-129); `ticker_links` (JSON API with admin-bypass) is deliberately **not** `/admin/`-prefixed since it's user-facing with an admin escalation, not admin-only — see its own doc-comment contrasting the two patterns. **No router-level "admin" marker beyond the `/admin/` prefix convention** — gating happens entirely inside the controller.

**Two controller patterns for admin gating** — both **re-read `is_admin` fresh from the DB**, never trust the session flag for the actual gate:
- [`TickersController::requireAdmin()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Admin/TickersController.php#L321-L329) — private per-controller helper, redirects to `/dashboard` on failure, used by redirect+flash-message form-CRUD actions.
- [`TickerLinkController::isCurrentUserAdmin()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Links/TickerLinkController.php#L29-L32) — inline bool-returning check for JSON-API actions with mixed permission levels (used for delete-any-vs-delete-own branching), with an explicit doc-comment: *"never trusting `$_SESSION['is_admin']` alone (that flag is only used, elsewhere, as a display-only UI hint)"*.
- **No shared static helper exists on `AuthController`.** `AuthController::requireAuth()` itself only checks `$_SESSION['user_id']` — it doesn't touch `is_admin` at all.

**Session flag** — `$_SESSION['is_admin']` (bool), set at three points in [`AuthController.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Auth/AuthController.php) (login + two other post-verification flows), sourced from `users.is_admin` via `UserRepository`. Per documented convention, treat it as **UI-hint only**; the real gate always re-queries.

**Template gating** — [`templates/layout.php:58-72`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/templates/layout.php#L58-L72) is the *only* place in `templates/` that checks `is_admin` today: `<?php if (!empty($_SESSION['is_admin'])): ?>` — no template helper function, raw inline check. Our two new buttons in `templates/analysis.php` should use this exact same inline pattern (session flag for **display**, DB-fresh-read for the actual endpoint gate).

**CSRF** — [`Request::verifyCsrf()`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Core/Request.php#L82-L98) reads from POST field `_csrf` **or** header `X-CSRF-Token` — already dual-mode, works unchanged for our fetch/AJAX endpoints. The `criticalReview()`/`criticalReviewStatus()` pair (§1) is the best existing precedent for our exact shape (JSON trigger+poll over fetch, not form+redirect) — copy its check order (`requireAuth` → admin-check → `verifyCsrf` → business checks) and its `Response::json(['ok'=>false,...], 403)` failure shape.

## Code References

- [`src/Ai/AiAnalysisController.php:296-433`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/AiAnalysisController.php#L296-L433) — `criticalReview()` trigger + `criticalReviewStatus()` poll, the async pattern to copy
- [`bin/generate_critical_review.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/bin/generate_critical_review.php) — background worker script template
- [`src/Ai/AiCriticalReviewRepository.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/AiCriticalReviewRepository.php) — markPending/markCompleted/markFailed pattern
- [`database/migrations/030_create_ai_critical_reviews.sql`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/database/migrations/030_create_ai_critical_reviews.sql) — status-table schema template
- [`templates/analysis.php:748-797`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/templates/analysis.php#L748-L797) — "Dane źródłowe (surowe)" section to extend
- [`templates/analysis.php:1010-1109,1498-1671`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/templates/analysis.php#L1010-L1109) — critical-review button + polling JS to mirror
- [`src/Ai/GeminiClient.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/GeminiClient.php) — the reusable Gemini transport client
- [`src/Ai/GeminiClientFactory.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Ai/GeminiClientFactory.php) — construction entry point
- [`src/LlmGemini/LlmGeminiContextGatherer.php:40-112`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/LlmGemini/LlmGeminiContextGatherer.php#L40-L112) — web-search-grounded call pattern to copy
- [`config/gemini.php:17-45`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/config/gemini.php#L17-L45) — Gemini config keys
- [`bin/rescore.php:161-253`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/bin/rescore.php#L161-L253) — batch pipeline + peer_bucket_override merge precedent
- [`src/TrackRecord/SnapshotWriter.php:48-99`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/TrackRecord/SnapshotWriter.php#L48-L99) — the persist entry point to reuse
- [`src/TrackRecord/CvsSnapshotRepository.php:65-220`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/TrackRecord/CvsSnapshotRepository.php#L65-L220) — actual INSERT/UPDATE writer (called via SnapshotWriter, not directly)
- [`src/Api/PayloadCompleteness.php`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Api/PayloadCompleteness.php) — gate to add to the new single-ticker path
- [`database/migrations/037_create_peer_bucket_override.sql`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/database/migrations/037_create_peer_bucket_override.sql) — closest migration template
- [`src/Admin/TickersController.php:321-329`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Admin/TickersController.php#L321-L329) — `requireAdmin()` redirect-style pattern
- [`src/Links/TickerLinkController.php:29-32`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Links/TickerLinkController.php#L29-L32) — `isCurrentUserAdmin()` inline-bool pattern + doc-comment on session-flag distrust
- [`templates/layout.php:58-72`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/templates/layout.php#L58-L72) — the only existing `is_admin` template check
- [`src/Core/Request.php:82-98`](https://github.com/mijugit/cvs-composite-valuation-score/blob/56063d116c089095e68df1ba7097983bdcee87e9/src/Core/Request.php#L82-L98) — `verifyCsrf()` dual-mode (form field / header)

## Architecture Insights

- **Two AI-client families, one shared skeleton.** `ClaudeClient` and `GeminiClient` are deliberately structural mirrors (typed `AiResult`, never-throw, `HttpTransport` seam, factory). A new LLM-backed feature should always ask "which existing client + which existing thin-service pattern (`LlmGeminiDecisionService` vs `LlmGeminiContextGatherer`) does this most resemble?" rather than writing new transport code. For this feature, `ContextGatherer`'s search-grounded pattern is the match, not the wallet's non-grounded decision call.
- **Admin gating is deliberately per-controller, not centralized.** Two independent existing implementations (`TickersController::requireAdmin()`, `TickerLinkController::isCurrentUserAdmin()`) both re-read `is_admin` from the DB rather than sharing a helper or trusting the session. This is a *convention*, not an oversight — the new controller should follow the same shape rather than introducing a new shared `AuthController::requireAdmin()` (which would be the first of its kind and a bigger footprint than the feature needs).
- **The "merge an admin override into `$financials` right before scoring" pattern already exists once** (`peer_bucket_override` in `bin/rescore.php`). The new `fundamental_overrides` merge is the second instance of this pattern, not a novel one — same insertion point, same "fetcher/model stay override-unaware" discipline.
- **Single-ticker code paths are read-only by convention today.** Every existing single-ticker flow (`AnalysisController::show`, `AiAnalysisController::generate/sharePrompt`) deliberately never writes to `cvs_snapshots`. This feature is the *first* single-ticker write path — it has no bug to inherit, but also no shortcut to copy; `PayloadCompleteness` in particular must be added new rather than "reused," since nothing single-ticker calls it today.
- **The async job pattern (`markPending`/`exec &`/poll) is being reused for the second time.** Its first use (critical review) already encodes hard-won lessons (duplicate-trigger guard via `isPending()`, resume-on-reload, 5-minute wall-clock cap, dedicated log file per `lessons.md`'s cron-logging rule) — copying it wholesale inherits those fixes for free.

## Historical Context (from prior changes)

- `context/foundation/lessons.md` — "exec() fire-and-forget: dołącz ` &`" and "Skrypty CLI/cron: nie używaj error_log()" are both already correctly applied in the `generate_critical_review.php`/`criticalReview()` pair we're copying — no new risk here as long as the copy is faithful.
- `context/foundation/lessons.md` — "Filtruj shadow model_version przy każdym odczycie 'latest snapshot'" and "NULL w kolumnie UNIQUE nie chroni przed duplikatami" — both directly apply to the new single-ticker write path; using `SnapshotWriter::persist()` (not a hand-rolled INSERT) is the concrete way to honor both without re-deriving the fix.
- `context/foundation/lessons.md` — "Dwie implementacje jednej reguły zawsze się rozjadą" — reinforces: do not reimplement the override-merge logic in more than one place (e.g. don't duplicate it between an admin-trigger code path and `bin/rescore.php`'s batch loop) — the merge should live in one shared function/method both paths call.
- No prior `context/changes/**/` or `context/archive/**/` entries reference fundamental-data validation, Gemini-based backfill, or a `fundamental_overrides`-style table — this is genuinely new ground, not a repeat of an earlier attempt.

## Related Research

- `context/foundation/prd-fundamentals-validation.md` — the PRD this research grounds.
- `context/foundation/shape-notes-fundamentals-validation.md` — the shaping session (12 FRs, Socrates rounds) this PRD was generated from.

## Open Questions

1. **`fundamental_overrides` table shape**: one row per `(ticker, field_name)` pair, or one row per ticker with a JSON blob of overridden fields? Migration 037 doesn't disambiguate this — needs a plan-time decision balancing query simplicity (per-field rows make "is this specific field overridden" trivial) against write simplicity (JSON blob makes one atomic write per validation run). Owner: plan phase.
2. **Admin gate implementation**: duplicate the `isCurrentUserAdmin()`-style inline check in the new controller (matches existing convention exactly, more code duplication) vs. extract a shared helper now (less duplication, but deviates from the established per-controller pattern and would be the first of its kind). Owner: plan phase, lean toward following convention (duplicate) unless a third admin-only controller appears soon.
3. **Does the confirm/apply step need its own CSRF+admin-gated endpoint, or can it be folded into the poll endpoint's response handling?** The PRD's review-before-apply (FR-009) implies a distinct "admin clicks confirm after seeing the diff" action — this is a third endpoint beyond trigger+poll, not accounted for by the two-endpoint precedent researched here. Plan phase should size this explicitly.
4. **Side effects on single-ticker rescore**: `bin/rescore.php` also does ATR-zone cache upsert and alert-digest queuing per ticker. Should the new single-ticker rescore path replicate those too (full parity with batch), or only the score/snapshot part (minimal, since ATR/alerts are arguably batch-cadence concerns)? Owner: plan phase.
