# Artifact 2 — Structure (namespace dependency graph)

Source: manual `grep` over `use CVS\...\` import statements (no `dependency-cruiser` — this is a
PHP/PSR-4 codebase, not JS/TS; the tool doesn't apply, so the same "static graph of imports"
signal was reconstructed with `grep -F` per namespace instead).

## Fan-in per `CVS\` namespace (how many files outside it import from it)

```
26  CVS\Core          ← Router, Request, Response, Database (PDO singleton)
14  CVS\Auth          ← AuthController::requireAuth() guard, UserRepository
 6  CVS\Api           ← FinancialDataFetcher
 5  CVS\CVS           ← CVSModel, CVSResult, QualityGate (the scoring engine itself)
 4  CVS\TrackRecord
 4  CVS\Mail
 3  CVS\Pro
 3  CVS\Ai
 2  CVS\Translation / CVS\Portfolio / CVS\Execution / CVS\Alerts
 1  CVS\Screener / CVS\History / CVS\Forecast / CVS\Admin
```

## File count per namespace (module size)

```
15  src/CVS/          (model + Pillars/)
12  src/Ai/
 9  src/Portfolio/
 7  src/TrackRecord/
 5  src/Core/  src/Alerts/
 4  src/Pro/   src/Forecast/
 2  src/Watchlist/ src/Translation/ src/Screener/ src/Auth/ src/Api/ src/Admin/
 1  src/Mail/  src/History/  src/Execution/
```

## The one deliberate fan-out point: `src/Core/routes.php`

`routes.php` imports controllers from **every** feature namespace (Ai, Alerts, Auth, CVS,
Screener, TrackRecord, Watchlist, Admin, Pro, Translation, Portfolio). This looks like a
"Core depends on everything" red flag at first glance, but it isn't structural coupling — it's
the composition root. CLAUDE.md confirms this is intentional: *"Add new routes only in
`src/Core/routes.php`"*. Every new feature registers itself here once; nothing else in `Core\`
imports feature code.

## Entry points

- `public/index.php` (30 lines) — the only HTTP front controller. Loads `.env`, boots session,
  builds `Router`, requires `routes.php`.
- `bin/*.php` (8 CLI scripts) — `rescore.php`, `refresh_peer_medians.php`,
  `check_price_alerts.php`, `portfolio-rebalance.php`, `score_distribution_report.php`,
  `gen_favicon.php`, `test_ai.php`, `test_mail.php`. All invoked by Cyber_Folks cron, not by any
  web request — a second, parallel "entry surface" that CLAUDE.md's conventions section governs
  separately (idempotency, own log file, no `error_log()`).

## Layering, as it actually is (not aspirational)

```
public/index.php ─▶ Core\Router ─▶ Core\routes.php ─▶ <feature>\*Controller
                                                              │
                                                              ▼
                                              CVS\CVS\* (scoring engine, config-driven)
                                              CVS\Api\FinancialDataFetcher (external data)
                                              CVS\Ai\* (Claude API, never throws)
                                              CVS\TrackRecord\* (historical snapshots)
```

No formal layering enforcement (no `deptrac`/architecture tests) — the boundaries hold today
because CLAUDE.md's namespace convention is followed by habit, not by a checked rule. This is the
biggest "unknown" surfaced by this artifact: nothing would catch a future PR that, say, imports
`CVS\Core\Database` directly into a template.
