-- Migration 003 — create analysis_history table
-- Run once after 002_create_watchlist.sql.

CREATE TABLE IF NOT EXISTS analysis_history (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    ticker        VARCHAR(20)  NOT NULL,
    cvs_swing     DECIMAL(5,2) NULL,
    cvs_fund      DECIMAL(5,2) NULL,
    reco_swing    VARCHAR(50)  NULL,
    reco_fund     VARCHAR(50)  NULL,
    golden_signal VARCHAR(20)  NULL,
    quality_gate  TINYINT(1)   NOT NULL DEFAULT 0,
    analysed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_history_user_time (user_id, analysed_at DESC),
    CONSTRAINT fk_history_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
