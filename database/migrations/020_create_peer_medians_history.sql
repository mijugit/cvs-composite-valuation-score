-- Append-only history of peer-median snapshots.
-- Each call to PeerMedianRepository::upsertMedian() appends one row here
-- in addition to upserting peer_medians. No UNIQUE constraint — every refresh
-- adds a new data point so the admin panel can render a time-series chart.
-- Table DDL mirrors peer_medians but replaces computed_at with snapshotted_at.
-- Additive only — does not touch existing tables.

CREATE TABLE IF NOT EXISTS peer_medians_history (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    level         VARCHAR(20)   NOT NULL,            -- 'industry' | 'sector'
    bucket_key    VARCHAR(150)  NOT NULL,             -- industry or sector name
    parent_sector VARCHAR(100)  NULL,                 -- owning sector (NULL for sector rows)
    model_version VARCHAR(20)   NOT NULL,             -- e.g. '3.0'
    metric_type   VARCHAR(20)   NOT NULL,             -- 'ev_fcf' | 'ev_sales' | 'gm'
    median_value  DECIMAL(14,4) NULL,                 -- computed median; NULL when sample_count < N
    sample_count  INT UNSIGNED  NOT NULL DEFAULT 0,
    snapshotted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_history_bucket   (level, bucket_key, metric_type),
    INDEX idx_history_snapshot (snapshotted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
