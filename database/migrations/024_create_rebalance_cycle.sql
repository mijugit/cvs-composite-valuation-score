-- Migration 024: Create rebalance_cycle table
--
-- Minimal cycle table for the virtual portfolio scheduler gate (F-01).
-- Provides DB-level idempotency via UNIQUE KEY on cycle_date.
--
-- F-02 will extend this table with portfolio execution summary columns
-- (cash_before, cash_after, executed_count, skipped_count, notes, portfolio_value_usd).
-- F-03 will extend it further with LLM audit columns
-- (retry_count, llm_raw_response, llm_failure_kind, llm_decision_json).
--
-- status values used by F-01:
--   started          — cycle began, engine running
--   completed        — cycle finished successfully
--   failed           — unexpected exception during execution
--   market_closed    — (informational) written when market is closed; no cycle row created
--   outside_window   — (informational) script fired outside rebalance window; no row created
--
-- F-02/F-03 will add: llm_failed, no_action, insufficient_cash

CREATE TABLE IF NOT EXISTS rebalance_cycle (
    id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cycle_date   DATE            NOT NULL,
    status       VARCHAR(32)     NOT NULL DEFAULT 'started',
    started_at   DATETIME        NOT NULL,
    finished_at  DATETIME        NULL,
    UNIQUE KEY uq_cycle_date (cycle_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
