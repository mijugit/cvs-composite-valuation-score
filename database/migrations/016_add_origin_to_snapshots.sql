-- Phase 7 (slice 1, cvs-calibration-corpus): snapshot origin layer (FR-003).
-- Purpose: distinguish user-facing snapshots written by bin/rescore.php
-- (origin = 'rescore') from calibration-corpus snapshots written by the
-- peer-median crawl (origin = 'corpus'), and let both coexist for the same
-- (ticker, score_date, model_version) — a watchlist ticker that also sits in
-- the crawled sector gets two rows per day per version, by design.
-- Additive for data: existing rows backfill to 'rescore' via the DEFAULT;
-- only the unique index changes — no rows are altered or lost.
-- Pre-versioning rows (model_version IS NULL) keep their MySQL NULL-distinct
-- semantics; origin itself is NOT NULL so it never widens that loophole.
-- Rollback: ALTER TABLE cvs_snapshots
--             DROP INDEX uq_ticker_day_version_origin,
--             ADD UNIQUE KEY uq_ticker_day_version (ticker, score_date, model_version),
--             DROP COLUMN origin;

ALTER TABLE cvs_snapshots
    ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT 'rescore' AFTER model_version,
    DROP INDEX uq_ticker_day_version,
    ADD UNIQUE KEY uq_ticker_day_version_origin (ticker, score_date, model_version, origin);
