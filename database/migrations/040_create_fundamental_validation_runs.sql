-- Migration 040: fundamental-data validation job status
--
-- change: fundamentals-validation
--
-- Mirrors ai_critical_reviews (migration 030) exactly: one row per ticker
-- tracking a background job's pending/completed/failed state. The difference
-- is what "completed" carries — a structured diff (proposed new values,
-- not-yet-applied) rather than free text, because FR-009 requires the admin
-- to review old-vs-new values before anything is written to
-- fundamental_overrides. This table is the PROPOSED state; fundamental_overrides
-- (migration 039) is the APPLIED state — the same separation of concerns
-- ai_critical_reviews (job status) has from cvs_snapshots (applied result).
--
-- `notes` carries Gemini's free-text explanation for the admin to read, but is
-- never parsed back into `diff` — see context/foundation/lessons.md's caution
-- about LLM narrative drifting from what a model actually computed; here the
-- narrative must never be mistaken for a value.

CREATE TABLE IF NOT EXISTS fundamental_validation_runs (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    ticker           VARCHAR(20)   NOT NULL,
    status           VARCHAR(16)   NOT NULL DEFAULT 'pending' COMMENT 'pending | completed | failed',
    mode             VARCHAR(16)   NOT NULL COMMENT 'all | missing',
    requested_fields TEXT          NULL     COMMENT 'JSON list of field names sent for validation',
    diff             TEXT          NULL     COMMENT 'JSON: field_name => {old, new, status}, not yet applied',
    notes            TEXT          NULL     COMMENT 'Gemini free-text explanation — display-only, never parsed into diff',
    error_message    TEXT          NULL,
    model            VARCHAR(80)   NULL,
    requested_by     INT UNSIGNED  NULL,
    requested_at     DATETIME      NOT NULL,
    completed_at     DATETIME      NULL,
    UNIQUE KEY uq_fvr_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
