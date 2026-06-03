-- Phase 3 (F3-P1): add model versioning and subsector to CVS snapshots.
-- model_version: isolates track-record across methodology changes.
-- industry:      Yahoo Finance sub-sector; used by peer-group resolver.
-- Existing rows get NULL — treated as pre-versioning (excluded from versioned track-record).
-- Column order: industry before model_version so related fields sit together next to sector.

ALTER TABLE cvs_snapshots
    ADD COLUMN industry      VARCHAR(150) NULL AFTER sector,
    ADD COLUMN model_version VARCHAR(20)  NULL AFTER industry;

-- Index to filter track-record by version efficiently.
CREATE INDEX idx_snapshots_version ON cvs_snapshots (model_version, score_date);
