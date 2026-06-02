-- S-02: model-track-record
-- Adds price_at_snapshot to enable track record evaluation.
-- Price is stored at time of scoring so we can compare "then vs now".
-- Existing rows get NULL (2 days of data without price -- acceptable).

ALTER TABLE cvs_snapshots
    ADD COLUMN price_at_snapshot DECIMAL(10,2) NULL AFTER scored_at;
