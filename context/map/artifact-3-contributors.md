# Artifact 3 — Contributor context

Source: `git log --grep`, `git shortlog`, and `context/foundation/lessons.md`.

## This is a solo project

```
git log --format='%an' | sort -u
  MiJu
  mijugit
```

Both names are the same person (local vs. GitHub identity). 270 commits, no other contributors.
So "who has the context for area X" collapses to a different, still useful question for a solo
project: **which areas produced painful enough bugs that the lesson got written down**, i.e.
where tribal knowledge already had to be externalized because it wasn't safe to keep only in one
person's head across a multi-week gap.

## Hotspot bug clusters (from bugfix commit messages, last 12 months)

1. **`src/Portfolio` / LLM decision pipeline — by far the buggiest area.**
   `fix: rebalance execution deadlock (SQLSTATE 1205 lock wait timeout)`,
   `fix: unblock rebalance cycle — parser resilience, real prices, qty rule`,
   `fix: pass price_at_snapshot to LLM prompt instead of missing 'price' key`,
   `fix: DecisionParser handles markdown fences and quantity=0 for HOLD`,
   `fix: nextTradingDay timezone mismatch — use ET not Warsaw midnight`,
   `fix: portfolio shows today as next trading day instead of tomorrow`.
   Pattern: an LLM-driven autonomous agent making numeric/scheduling decisions is exactly where
   parsing fragility, timezone assumptions, and "the model said the right thing in prose but the
   structured field was wrong" bugs concentrate (see `lessons.md` → *"Reguły arytmetyczne z sumą
   kroczącą egzekwuj po stronie serwera, nie w prompcie"*).

2. **Shadow `model_version` snapshot reads.**
   `fix: filter shadow model_version rows out of "latest snapshot" reads`,
   `fix(snapshot): resolve HY093 duplicate named placeholder in UPDATE`.
   Recurring theme: once a table can hold multiple model-version rows per
   `(ticker, score_date)`, *every* "latest snapshot" read site is a fresh place to reintroduce the
   same bug. Already promoted to a standing rule in `lessons.md`.

3. **CLI/cron logging anti-pattern.**
   `fix(cron): replace error_log() with file logging in rescore + refresh_peer_medians`,
   `fix(price-alerts): write log to file instead of error_log()`,
   `fix(price-alerts): cast stop_swing from DB string to float`.
   Cyber_Folks shared hosting swallows `error_log()` output from CLI cron jobs silently — a
   `TypeError` hid for weeks before discovery. Now a standing rule for every new `bin/` script.

## Where the tribal knowledge already lives (don't rediscover it)

`context/foundation/lessons.md` is the append-only register these bugs were promoted into. Five
entries as of this writing, each with **Context / Problem / Rule / Applies to**:
UserRepository SELECT-column omissions, PHP template syntax (`php -l` before commit), PowerShell
heredoc + unicode in commit messages, `exec()` fire-and-forget needing `&`, CLI logging, shadow
model_version filtering, and LLM numeric-limit enforcement. Any Deep Focus / refactor session on
`src/Portfolio` or `src/TrackRecord` should read this file first — it is the closest thing this
project has to "ask the person who touched this last."

## Unknowns

- No second contributor exists yet to cross-check "is this really load-bearing or just how I did
  it" — every architectural judgment in this map is one person's view of their own code, not
  triangulated against a colleague's independent read.
