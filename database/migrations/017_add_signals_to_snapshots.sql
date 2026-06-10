-- Phase 7 (slice 2, cvs-predictive-signals): raw predictive-signal inputs (FR-022).
-- Purpose: persist the raw signal inputs (surprise_pct, breadth, 52w proximity,
-- beat_count_4q, plus the per-signal adjustments) used by the model_version 3.2
-- shadow, so the calibration corpus is re-weighable offline (grid-search, slice 5)
-- without re-fetching historical Yahoo data. Written for base/3.1/3.2 rows alike
-- (same raw inputs per ticker-day).
-- Additive, NULL default: existing rows stay NULL (no backfill); old code ignores
-- the column, new code writes it immediately.
-- Rollback: ALTER TABLE cvs_snapshots DROP COLUMN signals;

ALTER TABLE cvs_snapshots
    ADD COLUMN signals TEXT NULL AFTER pillar_scores;
