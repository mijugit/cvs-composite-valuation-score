-- S-04: watchlist-alerts
-- Three tables for the alert system.

-- Global alert preference per user (default OFF = 0).
CREATE TABLE IF NOT EXISTS user_alert_settings (
    user_id    INT UNSIGNED NOT NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-ticker opt-out (absence of row = alerts enabled for that ticker).
-- A row means the user has explicitly disabled alerts for this ticker.
CREATE TABLE IF NOT EXISTS user_alert_ticker (
    user_id  INT UNSIGNED NOT NULL,
    ticker   VARCHAR(20)  NOT NULL,
    disabled TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alert sent log — stores last known state per (user, ticker) for deduplication.
-- An alert is sent only when current reco/signal differs from last_reco/last_signal.
CREATE TABLE IF NOT EXISTS alert_sent (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    ticker      VARCHAR(20)  NOT NULL,
    last_reco   VARCHAR(60)  NULL,
    last_signal VARCHAR(20)  NULL,
    sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_ticker (user_id, ticker),
    INDEX idx_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
