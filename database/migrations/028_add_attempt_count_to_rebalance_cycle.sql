-- Migration 028: Add attempt_count to rebalance_cycle
--
-- Enables bounded daily retries. Previously the UNIQUE(cycle_date) gate blocked
-- any re-run once a cycle row existed, so a transient LLM timeout locked the
-- portfolio for the whole day. attempt_count lets CycleRepository::claimForRun()
-- re-enter a failed cycle (status llm_failed/failed) up to a configured maximum,
-- reusing the same row (cycle_date stays UNIQUE).
--
-- Semantics:
--   attempt_count — number of times the cycle has been started (1 on first run,
--                   incremented on each retry). Compared against
--                   config/portfolio.php['strategy']['max_daily_attempts'].
--
-- Depends on: migration 024 (F-01)

ALTER TABLE rebalance_cycle
    ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status;
