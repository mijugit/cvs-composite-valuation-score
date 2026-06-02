-- S-03: cvs-screener
-- Adds sector column to enable sector filtering in the screener.
-- Existing rows get NULL (will be populated on next cron rescore).

ALTER TABLE cvs_snapshots
    ADD COLUMN sector VARCHAR(50) NULL AFTER ticker;
