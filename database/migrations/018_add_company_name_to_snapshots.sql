-- Watchlist tooltip UX: persist the company's full (long) name alongside each
-- CVS snapshot, so the dashboard watchlist chips can show "Tesla, Inc." on
-- hover instead of just the ticker. Sourced from FinancialDataFetcher's
-- assetProfile.longName (Yahoo Finance), written by SnapshotWriter on every
-- rescore. Existing watchlist tickers get this populated after their next
-- bin/rescore.php cron run.
-- Additive, NULL default: existing rows stay NULL; old code ignores the
-- column, new code writes it immediately.
-- Rollback: ALTER TABLE cvs_snapshots DROP COLUMN company_name;

ALTER TABLE cvs_snapshots
    ADD COLUMN company_name VARCHAR(255) NULL AFTER ticker;
