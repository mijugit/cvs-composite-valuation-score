-- Migration 033: Admin-curated favourite links per ticker (screener right-click menu).
--
-- Read by ScreenerRepository (bulk-loaded per ticker set, same "compute from
-- data" pattern as ticker_zone/cvs_snapshots), written only through
-- TickerLinkController (admin-gated, max 10 per ticker enforced in PHP —
-- consistent with data_source.max_watchlist being a PHP-side limit, not a
-- DB constraint).

CREATE TABLE IF NOT EXISTS ticker_links (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticker       VARCHAR(20)  NOT NULL,
    label        VARCHAR(80)  NOT NULL,
    url          VARCHAR(500) NOT NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_ticker_links_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
