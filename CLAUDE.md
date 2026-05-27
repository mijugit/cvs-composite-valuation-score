# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project-specific rules

### CVS model

- **Never hardcode weights or thresholds.** Always read from `config/cvs-weights.php` — this is FR-010. The config is injected into `CVSModel::__construct(array $config)`.
- **Quality Gate is binary.** A company that fails returns `CVSResult::failed($ticker, $failures)` — not a CVS score of 0. Do not assign any numerical score when the gate rejects.
- **CVS must be deterministic.** Same `$financials` input → identical CVS and recommendation. No randomness, no `date()`/`time()` calls inside scoring logic.
- **SectorBenchmarkPillar uses hardcoded sector benchmarks** (EV/FCF medians from `config/cvs-weights.php → benchmarks`). Returns neutral 50 only when growth data is unavailable or `shares_outstanding` is null. `Financial Services` and `Real Estate` sectors work but have lower model accuracy (known limitation).

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
CVS\CVS\Pillars\ — four pillar classes (one per scoring dimension)
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
