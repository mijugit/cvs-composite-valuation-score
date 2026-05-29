# Repository Guidelines

CVS — Composite Valuation Score is a vanilla PHP 8.2+ web application that computes a composite investment score (0–100) for NYSE/NASDAQ stocks using four weighted pillars. Stack: PHP + MySQL + Apache, no framework, shared-hosting deployment target.

## Hard rules

- **Never hardcode CVS weights or thresholds.** All numerical model parameters live in `config/cvs-weights.php` (FR-010). Inject the full config array into `CVSModel`; never write a literal `0.30` or `72` in scoring code.
- **Quality Gate is binary — not a score.** When a company fails the gate, return `CVSResult::failed($ticker, $failures)`. A rejected company gets no CVS score at all, not score 0.
- **Disclaimer is mandatory on every CVS result.** `CVSResult::toArray()` emits it under key `disclaimer`. It must appear in every API response and every template that renders a result.
- **CSRF token required on every POST.** Call `$request->verifyCsrf()` before processing any form. New routes that skip this check will be rejected.
- **All HTTP requests enter through `public/index.php`.** Do not create additional `.php` files inside `public/` as direct entry points.
- **CVS must be deterministic.** Same `$financials` input → identical score and label across runs. No `date()`, `time()`, or randomness inside scoring logic.

## Project structure

- `src/Core/` — Router, Request, Response, Database (PDO singleton)
- `src/Auth/` — AuthController, UserRepository
- `src/CVS/` — CVSModel, QualityGate, CVSResult, AnalysisController
- `src/CVS/Pillars/` — four pillar classes (one scoring dimension each)
- `src/Api/` — FinancialDataFetcher (Yahoo Finance via cURL, session-cached)
- `config/` — `app.php` (env/db/session), `cvs-weights.php` (model parameters)
- `templates/` — `layout.php` wraps per-view templates; no template engine
- `public/` — front controller + static assets
- `database/migrations/` — SQL DDL files

Namespace root: `CVS\` → `src/` (PSR-4). See `@composer.json`.

## Commands

- `composer install` — install dependencies
- `php -S localhost:8000 -t public` — local dev server (document root is `public/`)
- `vendor/bin/phpunit` — full offline test suite
- `vendor/bin/phpunit tests/CVS/CVSModelTest.php` — single test class
- `composer audit` — CVE scan (requires Composer 2.4+)

## Conventions

- `declare(strict_types=1)` at the top of every PHP file.
- UI strings and user-visible messages → **Polish**. Code identifiers, comments, docblocks → **English**.
- New routes: `src/Core/routes.php` only, using `{param}` syntax for URL segments.
- Protected actions: call `AuthController::requireAuth()` (static) as the first line.
- Auth error messages must stay generic — never confirm whether an e-mail exists.
- `null` sector median values in `$financials` are expected (Yahoo Finance doesn't expose them); pillar (b) returns neutral 50 — this is not an error condition.

## Testing

PHPUnit 11, config at `@phpunit.xml`. Tests are fully offline — `FinancialDataFetcher` is not exercised. Use `CVSModelTest::baseFinancials()` as the canonical fixture shape for financial data arrays.

## Phase 2 conventions (new components)

New phase-2 work adds these sub-namespaces under `CVS\` (mirror dirs in `src/`): `Ai\` (Claude API client + analysis), `Mail\` (PHPMailer/SMTP), `Alerts\`, `Screener\`, `TrackRecord\`. New tables go in `database/migrations/` as numbered `NNN_*.sql`, additive only.

- **Claude API** — all LLM calls through one client in `src/Ai/` (timeout < 30s, errors → typed failure so the page never breaks, key from `.env`, prompt caching for cost). Build with the `/claude-api` skill.
- **Email** — PHPMailer via Composer, single service in `src/Mail/`, SMTP from `.env`, transactional only, graceful failure (log + return false).
- **Scheduled work** — CLI entrypoint in `bin/` run by cron (type "Ścieżka" with explicit PHP 8.2 path), idempotent; optional lazy-trigger fallback. Never couple to an HTTP request.
- **Static analysis** — run PHPStan (level 6) on `src/` before committing new code (`composer require --dev phpstan/phpstan` first).

Full rules and rationale: see `@CLAUDE.md` → "Phase 2 conventions".

## Architecture & depth

See `@CLAUDE.md` for the full CVS calculation flow, template rendering mechanic, caching strategy, and database setup.
