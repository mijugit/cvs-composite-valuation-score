# Repo Map — CVS (Composite Valuation Score)

> Working artifacts: [artifact-1-territory.md](artifact-1-territory.md) ·
> [artifact-2-structure.md](artifact-2-structure.md) ·
> [artifact-3-contributors.md](artifact-3-contributors.md)
> Method: Wide Scan (git history + import-graph + bugfix archaeology), no Deep Focus yet —
> this map exists to *decide* where a future Deep Focus should go, not to replace it.

## TL;DR

CVS is a 2-month-old, single-contributor PHP 8.2 monolith (no framework, PSR-4, 270 commits)
scoring US equities with a deterministic, config-driven model. The busiest area historically is
the scoring engine itself (`src/CVS`), but the **highest-risk area right now is `src/Portfolio`**
— a brand-new (100% built in the last month), LLM-driven autonomous paper-trading agent that
already produced more bugfix commits per week of existence than any other module. `src/Core`
(router substrate) and `src/Auth` are the most-depended-upon namespaces but are thin and stable.
There is no architectural enforcement (no `deptrac`, no import-boundary test) — the clean
namespace layering holds by convention (`CLAUDE.md`) and by one person's discipline, not by a
checked rule.

## Strefy ryzyka (risk zones)

1. **`src/Portfolio` + LLM decision pipeline — highest risk.** 6 distinct bugfix commits in one
   month: parser fragility on LLM output, a missing dict key reaching the prompt, a deadlock under
   concurrent rebalance, two separate timezone bugs. The recurring shape of the risk: an LLM can
   *describe* a numeric rule correctly in prose while getting the structured field wrong — already
   promoted to a standing rule (`lessons.md`), but any new decision-contract field is a fresh
   chance to reintroduce it.
2. **Shadow-snapshot reads (`src/TrackRecord`, `src/CVS` writers).** Any new "latest snapshot"
   query is a fresh opportunity to forget to filter by live `model_version`, silently doubling
   listings. Happened once already (hotfix `442689d`), explicitly documented as a recurring trap.
3. **`config/cvs-weights.php` ↔ `src/CVS`.** High co-change count (21) is *good* — it's evidence
   the "never hardcode weights" rule (FR-010) is actually followed — but it also means this single
   file is a single point of failure for the whole scoring engine; no schema/type validation on
   it beyond what PHP arrays give for free.
4. **CLI cron scripts (`bin/*.php`).** Cyber_Folks shared hosting silently swallows `error_log()`
   from CLI — a real bug (float cast) hid for weeks. Rule is now standing, but any *new* `bin/`
   script that skips the file-logging convention regresses silently, by design of the hosting
   environment (no visibility without the rule).

## Lokalne centra (load-bearing modules)

- **`CVS\Core`** (26 fan-in) — Router/Request/Response/Database. Thin (5 files) but everything
  depends on it; a bug here is a full-app outage, not a feature regression.
- **`CVS\Auth`** (14 fan-in) — `AuthController::requireAuth()` guard called at the top of every
  protected action. Also thin (2 files) — the entire access-control surface is two files deep.
- **`CVS\CVS`** (15 files, the biggest namespace) — `CVSModel`/`CVSResult`/`QualityGate`/`Pillars/`.
  The deterministic core the whole product's credibility rests on (must-stay-pure: no `date()`,
  no randomness, config-only weights).
- **`src/Core/routes.php`** — the one deliberate fan-out point (imports every feature controller).
  Not a violation — it's the composition root, and CLAUDE.md names it as the only place new routes
  belong.

## Entry pointy

- **HTTP:** `public/index.php` (30 lines) — the *only* web entry point. No other files under
  `public/` execute PHP.
- **CLI/cron (parallel entry surface, no web request involved):** 8 scripts in `bin/` —
  `rescore.php` (2×/day re-scoring), `refresh_peer_medians.php`, `check_price_alerts.php`,
  `portfolio-rebalance.php` (the autonomous agent's daily cycle), plus 4 smaller/manual scripts.

## Unknowns (things this map couldn't establish)

- No architecture-boundary test exists, so "the namespace layering is clean" is an observation
  about *today*, not a guarantee about tomorrow's PR.
- Single contributor — every "this is load-bearing" judgment in this map is one person reading
  their own code; nothing here was cross-checked by a second opinion.
- `src/Portfolio`'s bug rate could mean either "genuinely the hardest problem in the codebase"
  (LLM-driven autonomous decisions under real money-like constraints) or "just new and still
  settling" — twelve months from now, its bugfix rate relative to age is the number that would
  actually answer this; not enough history exists yet to tell the two apart.
