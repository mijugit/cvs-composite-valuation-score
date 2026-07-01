# Artifact 1 — Territory (git history)

Source: `git log --since="12 months ago" --name-only`. Repo age ≈ 2 months (270 commits total,
first commit within this window) — the "12 month" window covers the entire project history, so
this is really "where has ALL the work happened", not a recent slice.

## Top-15 most-changed files

```
39  templates/analysis.php
29  public/css/app.css
22  templates/layout.php
22  src/Core/routes.php
19  src/Api/FinancialDataFetcher.php
18  config/cvs-weights.php
17  src/CVS/AnalysisController.php
14  src/TrackRecord/CvsSnapshotRepository.php
13  tests/TrackRecord/CvsSnapshotRepositoryTest.php
13  tests/CVS/CVSModelTest.php
13  public/js/app.js
11  bin/rescore.php
10  src/CVS/CVSModel.php
 9  templates/dashboard.php
 8  src/Ai/AiDivergenceService.php
```

## Top-changed directories (code only, docs folders excluded)

```
62  src/CVS            — core scoring model + pillars
30  src/Portfolio      — autonomous virtual-portfolio agent (newest area)
29  src/Core           — router/request/response substrate
28  src/TrackRecord
28  database/migrations
26  src/Ai
20  src/Api
18  config/cvs-weights.php (single file)
```

## Monthly touch trend (hotspot namespaces)

```
src/CVS         2026-05: 29   2026-06: 33
src/Portfolio                2026-06: 30   ← 100% June, brand-new
src/Ai          2026-05: 9    2026-06: 17
src/TrackRecord               2026-06: 28
src/Api         2026-05: 6    2026-06: 14
src/Core        2026-05: 7    2026-06: 22
```

**Reading:** `src/Portfolio` didn't exist before June and is already the 2nd-busiest area of the
whole codebase — an active, still-hardening feature (autonomous LLM-driven paper-trading agent).
`src/CVS` (the deterministic scoring core) is busy in both months but at a steady rate — expected,
since every new "shadow model" (3.1, 3.2) and peer-group refinement touches it.

## Directory co-change pairs (hidden coupling, top 12, docs excluded)

```
57  src/Core <-> templates              — every new route/feature touches both
54  src/TrackRecord <-> tests/TrackRecord — well-tested area, good hygiene
44  src/CVS <-> templates
36  public/css <-> templates
30  src/CVS <-> src/Core
29  src/CVS <-> tests/CVS
29  src/CVS <-> src/CVS/Pillars
24  src/CVS/Pillars <-> templates
24  src/Ai <-> tests/Ai
21  src/Portfolio <-> tests/Portfolio
21  config <-> src/CVS                  — confirms config-driven design (FR-010)
18  database/migrations <-> tests/TrackRecord
```

**Reading:** `config <-> src/CVS` co-changing 21 times is a good sign — it means weight/threshold
tuning really does go through `config/cvs-weights.php` rather than hardcoded constants (matches
the CLAUDE.md rule). `src/Core <-> templates` is the single biggest coupling — there is no
template-engine abstraction, so every new page is a `routes.php` entry + a `templates/*.php` file,
touched together by definition.
