-- Migration 025: Extend rebalance_cycle with F-02 portfolio execution summary columns
--
-- Adds financial summary columns written by PortfolioService::executeCycle() at cycle end.
-- LLM-specific columns (llm_raw_response, llm_decision_json, retry_count, llm_failure_kind)
-- are intentionally absent here — those are added by F-03 migration 027.
--
-- Depends on: migration 024 (rebalance_cycle table must exist)

ALTER TABLE rebalance_cycle
    ADD COLUMN cash_before         DECIMAL(12,2)    NULL         AFTER finished_at,
    ADD COLUMN cash_after          DECIMAL(12,2)    NULL         AFTER cash_before,
    ADD COLUMN portfolio_value_usd DECIMAL(12,2)    NULL         AFTER cash_after,
    ADD COLUMN executed_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER portfolio_value_usd,
    ADD COLUMN skipped_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER executed_count,
    ADD COLUMN notes               TEXT             NULL         AFTER skipped_count;
