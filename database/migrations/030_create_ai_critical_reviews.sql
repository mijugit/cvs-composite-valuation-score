-- change: cvs-ai-critical-review
-- Async job tracking for the stage-2 "Recenzja krytyczna" feature. Separate
-- table from ai_analyses (etap 1) rather than an extended column there:
-- ai_analyses has UNIQUE(ticker) and every row is always a complete result;
-- changing that contract on a live, working table risks regressing etap 1.
--
-- A row here can sit in status='pending' while a background CLI job
-- (bin/generate_critical_review.php, fired via exec(... &)) does the real
-- work — measured at 90-140s+ with web search enabled, far beyond a
-- synchronous PHP request on CF. The web request only ever reads/writes this
-- table (markPending/markCompleted/markFailed), never blocks on Claude.
--
-- content/sources/model stay NULL while pending; markPending() never clears
-- an existing completed row's content, so a failed refresh doesn't lose the
-- last good result.

CREATE TABLE IF NOT EXISTS ai_critical_reviews (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ticker          VARCHAR(20)   NOT NULL,
    status          VARCHAR(16)   NOT NULL DEFAULT 'pending', -- pending | completed | failed
    content         TEXT          NULL,
    sources         TEXT          NULL, -- JSON-encoded list of {title, url}
    error_message   TEXT          NULL,
    model           VARCHAR(80)   NULL,
    tokens_input    INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_output   INT UNSIGNED  NOT NULL DEFAULT 0,
    generated_by    INT UNSIGNED  NULL,
    started_at      DATETIME      NOT NULL,
    generated_at    DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticker     (ticker),
    INDEX idx_generated_at   (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
