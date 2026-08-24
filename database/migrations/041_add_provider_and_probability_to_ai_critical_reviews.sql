-- change: critical-review-models
-- Extends the stage-2 "Recenzja krytyczna" job table (migration 030) with a
-- `provider` dimension so a ticker can have one independent review per AI
-- provider (Claude, Gemini) instead of exactly one review overall, plus three
-- new columns for the bull/bear scenario-probability signal both providers
-- must now report alongside the narrative.
--
-- Additive only: `provider` is added NOT NULL DEFAULT 'claude', which MySQL
-- backfills onto every existing row automatically as part of the ADD COLUMN —
-- no separate UPDATE statement is needed, and no existing Claude review is
-- lost. The unique constraint widens from ticker-only to (ticker, provider)
-- so the two providers' rows for the same ticker can coexist.

ALTER TABLE ai_critical_reviews
    ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT 'claude' AFTER ticker,
    ADD COLUMN bull_probability TINYINT UNSIGNED NULL AFTER sources,
    ADD COLUMN bear_probability TINYINT UNSIGNED NULL AFTER bull_probability,
    ADD COLUMN probability_rationale TEXT NULL AFTER bear_probability,
    DROP KEY uq_ticker,
    ADD UNIQUE KEY uq_ticker_provider (ticker, provider);
