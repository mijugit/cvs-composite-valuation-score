# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project-specific rules

### CVS model

- **Never hardcode weights or thresholds.** Always read from `config/cvs-weights.php` — this is FR-010. The config is injected into `CVSModel::__construct(array $config)`.
- **Quality Gate is binary.** A company that fails returns `CVSResult::failed($ticker, $failures)` — not a CVS score of 0. Do not assign any numerical score when the gate rejects.
- **CVS must be deterministic.** Same `$financials` input → identical CVS and recommendation. No randomness, no `date()`/`time()` calls inside scoring logic.
- **S-05 dual-mode architecture (3 pillars):**
  - `ValuationPillar` (EV/FCF vs sector medians from `config/cvs-weights.php → benchmarks`). Returns neutral 50 only when growth data is unavailable or `shares_outstanding` is null.
  - `MomentumPillar` — computed twice with different `roc_weights` per mode (swing vs fundamental).
  - `QualityPillar` — GM vs sector median, leverage (net debt/EBITDA), forward growth.
  - **Swing (1–4M):** valuation 40% / momentum 45% / quality 15%
  - **Fundamental (6–12M):** valuation 65% / momentum 15% / quality 20%
  - Both scores displayed simultaneously; `CVSResult::cvs()` returns swing for backward compat.
  - Golden signals: `strong` (both ≥58), `watchlist` (fund≥58 + swing<58), `momentum`, null.
  - `Financial Services` and `Real Estate` sectors work but have lower model accuracy (known limitation).

### Disclaimer — mandatory on every result

Every CVS result **must** carry:
```
Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
```
It is baked into `CVSResult::toArray()` under key `disclaimer`. Never omit it from API responses or template renders.

### Auth & security

- CSRF token (`$_SESSION['csrf_token']`) must be validated on **every POST** via `$request->verifyCsrf()`. Do not skip this in new routes.
- Auth guard: call `AuthController::requireAuth()` at the top of every protected controller action. It redirects to `/login` on failure — no exception thrown.
- Generic auth error messages only: never reveal whether an e-mail exists in the database.

### Data fetching & caching

- Yahoo Finance is fetched in `FinancialDataFetcher` via cURL. Results are cached in `$_SESSION` for `config/cvs-weights.php → data_source.cache_ttl` seconds (default 3600). Do not bypass the cache.
- Max tickers per request is enforced by `data_source.max_tickers` (default 10) — checked in `AnalysisController::analyse()`.

### Language convention

- **UI strings, error messages, labels** → Polish (`pl`)
- **Code** (class names, method names, variables, comments, docblocks) → English

### Routing

Add new routes only in `src/Core/routes.php`. Named URL parameters use `{name}` syntax (e.g. `/analysis/{ticker}`); the Router extracts them into `Request::param()`.

## Phase 2 conventions (new components)

These rules govern the new phase-2 components (AI analysis, screener, alerts, mailer, scheduler). They exist to protect the phase-2 guardrails — AI failure must not break the page, external-API cost control, CVS determinism — so apply them from the first line of new code.

### Static analysis
- Run PHPStan (level 6) before committing new code: `composer stan` (config in `phpstan.neon`, paths `src/`). Keep it green — the suite currently reports no errors.
- Keep `declare(strict_types=1)` and explicit type hints on all function boundaries (existing rule). PHPStan enforces it; do not weaken types to silence errors — fix the type.

### Claude API
- Provider is Claude API (Anthropic). All language-model calls go through a single client in `src/Ai/` — never scatter cURL calls across controllers. Reuse the proven pattern from another project's `claude-client.php` (Messages API, headers `x-api-key` + `anthropic-version`).
- The client MUST set a request timeout (< 30s, to keep user-perceived total within the NFR) and retry with backoff on transient failures. On error/timeout/quota, return a typed failure (not an exception that bubbles to the page) so the detail page and CVS result still render.
- API key comes from `.env` (`$_ENV`), never hard-coded. Use prompt caching (stable system prompt) to control cost — pairs with the shared analysis cache.
- The AI narrative is grounded in model-computed CVS/analyst data passed into the prompt; the AI layer never modifies a CVS score (determinism guardrail). Build it with the `/claude-api` skill.

### Transactional email
- Use PHPMailer via Composer (`composer require phpmailer/phpmailer`). All outbound mail goes through one service in `src/Mail/` — transactional only (alerts, PRO-code contact). No bulk/marketing.
- SMTP config (host/port/user/password/encryption/from) comes from `.env`, not hard-coded constants. Mirror the proven deliverability touches: domain-based Message-ID, suppressed X-Mailer, HTML + plain-text AltBody, List-Unsubscribe header.
- Graceful failure: if SMTP unconfigured or send throws, `error_log` and return false — never let a mail failure break the request flow. Keep volume low; never loop-send.

### Background / scheduled work
- Scheduled re-scoring runs from a standalone CLI entrypoint in `bin/` (e.g. `bin/rescore.php`), invoked by Cyber_Folks cron — type "Ścieżka" with an explicit PHP 8.2 binary path (CF CLI defaults to PHP 7.4; hard-code the 8.2 path). Prefer the path/CLI type over URL: no `max_execution_time` limit for a multi-ticker batch.
- The job MUST be idempotent (safe to run twice/day), guarded by a "last run" timestamp. A lazy-trigger by the first authenticated request of the day is an optional fallback, not the primary mechanism.
- Never couple scheduled work to an HTTP request lifecycle beyond the lazy-trigger guard.

### New namespaces & migrations
- New sub-namespaces under `CVS\`: `Ai\` (LLM client + analysis), `Mail\` (mailer), `Alerts\`, `Screener\`, `TrackRecord\`. Mirror directories under `src/` (PSR-4).
- New tables (shared AI analyses, CVS snapshots, PRO codes/usage, alert prefs) go in `database/migrations/` as numbered SQL files (`NNN_*.sql`), additive only — never break existing tables.

## Commands

```bash
# Install dependencies (required before first run)
composer install

# Run the full test suite
vendor/bin/phpunit

# Run a single test class
vendor/bin/phpunit tests/CVS/CVSModelTest.php

# Run tests with verbose output
vendor/bin/phpunit --testdox

# Start a local dev server (document root is public/)
php -S localhost:8000 -t public

# Audit dependencies for known CVEs (requires Composer 2.4+)
composer audit
```

> Tests run fully offline — no Yahoo Finance calls. `FinancialDataFetcher` is not exercised by the test suite; use synthetic `$financials` arrays from `CVSModelTest::baseFinancials()` as the canonical fixture shape.

## Architecture

**Front controller** — every HTTP request enters through `public/index.php`, which loads `.env`, boots the session, instantiates `CVS\Core\Router`, and requires `src/Core/routes.php`. There are no other PHP entry points in `public/`.

**Namespace root** — `CVS\` maps to `src/` via PSR-4 (see `composer.json`). Sub-namespaces mirror directories:

```
CVS\Core\       — Router, Request, Response, Database (PDO singleton)
CVS\Auth\       — AuthController, UserRepository
CVS\CVS\        — CVSModel, QualityGate, CVSResult, QualityGateResult, AnalysisController
CVS\CVS\Pillars\ — three pillar classes: ValuationPillar, MomentumPillar, QualityPillar
CVS\Api\        — FinancialDataFetcher (Yahoo Finance via cURL)
```

**CVS calculation flow:**
1. `AnalysisController` calls `FinancialDataFetcher::fetch($ticker)` → normalised `$financials` array
2. `CVSModel::calculate()` runs `QualityGate::evaluate()` — **binary pass/fail**
3. On pass: four pillars each return a score 0–100; weighted sum → CVS score
4. `CVSResult` value object carries score, pillar breakdown, recommendation label, and disclaimer

**Templates** — `Response::view('name')` buffers `templates/name.php` into `$content`, then renders it inside `templates/layout.php`. No template engine; plain PHP.

## Database

Single table at MVP: `users` (see `database/migrations/001_create_users.sql`). Connection via `CVS\Core\Database::connection()` (PDO singleton). Credentials from `.env` → `$_ENV`.

## Lessons learned

See: `context/foundation/lessons.md`
