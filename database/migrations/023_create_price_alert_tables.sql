-- Phase 8 slice 3 (cvs-threshold-alerts): price-alert persistence.
-- Additive-only. Two tables:
--   ticker_zone  — per-ticker cache of the ATR entry zone (USD) + fx, written by
--                  bin/rescore.php so the light price-only cron can read it without
--                  recomputing the zone or calling quoteSummary.
--   price_alert  — per-user "price entered zone" alert state (enable flag + hysteresis).

CREATE TABLE IF NOT EXISTS ticker_zone (
    ticker          VARCHAR(20)  NOT NULL,
    zone_low        DOUBLE       NULL,
    zone_high       DOUBLE       NULL,
    stop_swing      DOUBLE       NULL,
    stop_fund       DOUBLE       NULL,
    fx_rate_to_usd  DOUBLE       NULL,
    source          VARCHAR(16)  NULL,
    computed_at     DATETIME     NOT NULL,
    PRIMARY KEY (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS price_alert (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    ticker        VARCHAR(20)  NOT NULL,
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    last_state    VARCHAR(8)   NULL,      -- 'in' | 'out' | NULL (never evaluated)
    last_sent_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_ticker (user_id, ticker),
    INDEX idx_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
