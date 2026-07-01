# 02 — Invariant & Aggregate: "latest snapshot" must mean the live model version

## The invariant

> **For a given `(ticker, score_date)`, any query claiming to read "the latest CVS snapshot"
> must return exactly one row: the one whose `model_version` matches the currently-live model
> version in `config/cvs-weights.php`. Shadow rows (3.1, 3.2, …) and corpus-origin rows must never
> be mistaken for a live result.**

This is a real, already-violated invariant — not a hypothetical. Since `cvs-overlay-penalties`
(2026-06-08) started writing a second (shadow) row per `(ticker, score_date)`, an unfiltered
`MAX(score_date)` self-join returns **both** rows for the same day. It happened once in production
(screener showed 68 companies instead of 34, hotfix `442689d`) and is now a standing rule in
`context/foundation/lessons.md`: *"Filtruj shadow model_version przy każdym odczycie 'latest
snapshot'."*

## Where the rule lives today: everywhere, guarded by nothing

```
src/CVS/AnalysisController.php
src/Portfolio/DecisionService.php
src/Portfolio/PortfolioRepository.php
src/Screener/ScreenerController.php
src/Screener/ScreenerRepository.php
src/TrackRecord/CvsSnapshotRepository.php
src/TrackRecord/TrackRecordRepository.php
```

Seven files across four namespaces each independently write their own `MAX(score_date)` self-join
and each must remember, on their own, to add `AND model_version = :live_version`. There is no
single object responsible for answering "what is the latest live snapshot for ticker X" — the
rule is enforced by copy-paste discipline, which is precisely the DDD symptom from the lesson:
*"a piece of validation sits in a UI component, another copy sits in an API handler, a third
lives in a cron job — the rule is everywhere and nowhere."* Here it's not even validation, it's a
**read-side invariant**, arguably easier to violate because nothing fails loudly when it's wrong
— the query just silently returns extra rows.

## Why this is an aggregate problem, not a "be more careful" problem

DDD's answer to a scattered invariant is: pick **one aggregate** that is the sole gatekeeper for
that rule, and make every other read go through it (or through data it already validated) instead
of re-deriving the same SQL seven times.

**Proposed aggregate: `LiveCvsSnapshot`** (or, less DDD-purist but more honest about this being a
read model in a CRUD-shaped app: `LatestSnapshotFinder` as the single sanctioned query surface).

- **Consistency boundary:** "the set of rows that count as *a* live snapshot for a ticker on a
  day" — exactly the boundary the bug crossed.
- **Constructor/factory is the only place `model_version` filtering logic exists.** It reads the
  live version from `config/cvs-weights.php` once, internally, so callers cannot pass the wrong
  value or forget to pass one at all.
- **Public surface:** `LiveCvsSnapshot::latestFor(string $ticker): ?CvsSnapshot`,
  `LiveCvsSnapshot::latestForAll(array $tickers): array`,
  `LiveCvsSnapshot::sinceForTicker(string $ticker, DateTimeImmutable $since): array` — three
  methods that already cover everything the 7 current call sites need (single-ticker detail page,
  screener's bulk listing, track-record's history query).
- **Everything else deletes its own `MAX(score_date)` SQL** and calls this instead. The invariant
  becomes physically impossible to violate from a new call site, instead of "documented in
  lessons.md and hopefully read before writing SQL #8."

## What this refactor is NOT

- Not a rewrite of `cvs_snapshots`' schema — the migration-014/016/023 UNIQUE-constraint work
  already did the write-side half of this correctly (dual-write, versioned rows). This refactor
  is purely the **read side**.
- Not a new microservice/bounded-context split — `LiveCvsSnapshot` lives in `CVS\TrackRecord\` (or
  a new thin `CVS\Cvs\ReadModel\` if that gets crowded), reusable by `Screener`, `Portfolio`, and
  `AnalysisController` the same way `SnapshotWriter` (the write-side equivalent, already extracted
  in `cvs-calibration-corpus` phase 2) is reused today.
- Not blocking — this is a **candidate** for the next refactor slice via `/10x-plan`, following
  exactly the pattern this project already used for `SnapshotWriter` and `ValuationMetrics`
  (extract a small, tested, reused class; verify parity with golden-value tests before/after).
