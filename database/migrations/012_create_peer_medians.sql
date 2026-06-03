-- Phase 3 (F3-P1): peer-group median store
-- Empirical medians computed from rolling population batch, versioned by model_version.
-- sample_count drives the min-sample-count threshold (N) in MedianResolver.
-- level='industry' for subsector buckets, level='sector' for coarse-sector fallback + anchor.
-- Additive — does not touch existing tables.

CREATE TABLE IF NOT EXISTS peer_medians (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    level         VARCHAR(20)   NOT NULL,          -- 'industry' | 'sector'
    bucket_key    VARCHAR(150)  NOT NULL,           -- industry name or sector name
    parent_sector VARCHAR(100)  NULL,               -- sector owning this industry bucket (NULL for sector rows)
    model_version VARCHAR(20)   NOT NULL,           -- e.g. '3.0'
    metric_type   VARCHAR(20)   NOT NULL,           -- 'ev_fcf' | 'ev_sales' | 'gm'
    median_value  DECIMAL(14,4) NULL,               -- computed median; NULL when sample_count < N
    sample_count  INT UNSIGNED  NOT NULL DEFAULT 0, -- number of tickers that contributed
    computed_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bucket_version_metric (level, bucket_key, model_version, metric_type),
    INDEX idx_level_sector  (level, parent_sector),
    INDEX idx_computed_at   (computed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
