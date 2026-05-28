-- Migration 002 — create watchlist table
-- Run once after 001_create_users.sql.

CREATE TABLE IF NOT EXISTS watchlist (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    ticker     VARCHAR(20)  NOT NULL,
    added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_watchlist_user_ticker (user_id, ticker),
    CONSTRAINT fk_watchlist_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
