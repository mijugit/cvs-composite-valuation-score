-- Migration 027: Extend rebalance_cycle with LLM audit columns
--
-- Adds four columns written by CycleRepository::updateLlmRecord() after every
-- LLM attempt sequence (success or failure). Intentionally separate from F-02
-- portfolio execution columns to keep the ledger and LLM contracts distinct.
--
-- Column semantics:
--   retry_count      — 0 = only one attempt made, 1 = retry was triggered
--   llm_raw_response — full text of last LLM response or error description (TEXT, never truncated)
--   llm_failure_kind — AiFailureKind::value ('timeout', 'rate_limited', 'auth', etc.)
--                      or 'parse_error' when the API succeeded but JSON was invalid;
--                      NULL on success
--   llm_decision_json — validated JSON string as returned by LLM; NULL on failure
--
-- Depends on: migrations 024 (F-01), 025+026 (F-02)

ALTER TABLE rebalance_cycle
    ADD COLUMN retry_count        TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN llm_raw_response   TEXT             NULL               AFTER retry_count,
    ADD COLUMN llm_failure_kind   VARCHAR(32)      NULL               AFTER llm_raw_response,
    ADD COLUMN llm_decision_json  TEXT             NULL               AFTER llm_failure_kind;
