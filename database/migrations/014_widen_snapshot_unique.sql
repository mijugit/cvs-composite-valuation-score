-- Phase 5 (slice 1): widen the cvs_snapshots UNIQUE key to include model_version.
-- Purpose: allow a shadow row (model_version = 3.1) to coexist with the base row
-- (model_version = 3.0) for the same (ticker, score_date) — required for shadow-mode
-- persistence (FR-016/FR-019; see CvsSnapshotRepository::save() upsert contract).
-- Additive for data: only the unique index changes — no rows are altered or lost.
-- Pre-versioning rows (model_version IS NULL) remain valid: MySQL treats each NULL
-- as distinct for UNIQUE-index purposes, so they do not collide with one another.
-- Rollback: DROP INDEX uq_ticker_day_version; ADD UNIQUE KEY uq_ticker_day (ticker, score_date);

ALTER TABLE cvs_snapshots
    DROP INDEX uq_ticker_day,
    ADD UNIQUE KEY uq_ticker_day_version (ticker, score_date, model_version);
