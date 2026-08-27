-- change: ticker-logo-cache
-- Server-side cache of company logos fetched from logo.dev. One row per
-- ticker: the fetch script (bin/fetch_logos.php) skips any ticker already
-- present here, regardless of whether the outcome was `found` or
-- `not_found` — this table IS the skip-list that keeps the app from
-- hotlinking img.logo.dev on every page render and from re-querying the
-- Search API for tickers logo.dev simply has no coverage for.
--
-- Only two statuses: the fetch is atomic per ticker within one script run,
-- there is no intermediate/pending state requiring admin review (auto-pick
-- of the best Search API match is used instead).

CREATE TABLE IF NOT EXISTS ticker_logos (
    ticker       VARCHAR(20)               NOT NULL,
    domain       VARCHAR(255)               NULL,
    logo_path    VARCHAR(255)               NULL,
    status       ENUM('found','not_found') NOT NULL,
    fetched_at   DATETIME                  NOT NULL,
    updated_at   DATETIME                  NOT NULL,
    PRIMARY KEY (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
