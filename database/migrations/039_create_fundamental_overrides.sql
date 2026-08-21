-- Migration 039: admin-confirmed fundamental-data overrides
--
-- change: fundamentals-validation
--
-- FinancialDataFetcher never persists raw fundamentals — everything is fetched
-- live from Yahoo on every call. An audit (2026-08-20) and a 3-provider LLM
-- experiment confirmed Yahoo sometimes returns fields that are wrong but NOT
-- NULL (days_since_earnings off by ~37x; free_cash_flow higher than its own
-- operating_cash_flow, which is mathematically impossible) — invisible to the
-- only existing quality check (PayloadCompleteness, which only looks at
-- `revenue`). This table stores admin-confirmed replacement values that take
-- priority over the live Yahoo fetch until the admin re-triggers validation
-- for that field.
--
-- One row per (ticker, field_name) — chosen over a per-ticker JSON blob so
-- "is this specific field overridden" is a trivial lookup for the raw-data
-- table's per-field coloring, and a single field can be re-validated without
-- touching the others.
--
-- `value` is nullable on purpose: a row with status='checked_no_data' and a
-- NULL value means "an admin asked, the LLM searched, and genuinely found
-- nothing" — distinct from a field nobody has ever checked (no row at all).
-- Conflating the two would repeat the exact class of bug this feature exists
-- to catch: see context/foundation/lessons.md, "Brak danych to nie zero" and
-- "Wartość neutralna to milcząca awaria" — a checked-but-empty result must
-- never be silently indistinguishable from "unknown".

CREATE TABLE IF NOT EXISTS fundamental_overrides (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    ticker       VARCHAR(20)   NOT NULL,
    field_name   VARCHAR(60)   NOT NULL COMMENT 'FinancialDataFetcher::normalise() array key this overrides — see FundamentalFieldRegistry for the whitelist',
    value        VARCHAR(255)  NULL     COMMENT 'Stringified typed value; NULL when status=checked_no_data. Cast back to int/float by FundamentalOverrideMerger per FundamentalFieldRegistry::FIELD_TYPES.',
    status       VARCHAR(16)   NOT NULL COMMENT 'validated | checked_no_data',
    source       VARCHAR(32)   NOT NULL DEFAULT 'gemini_validation' COMMENT 'gemini_validation | local_calculation (e.g. moving_average_200)',
    validated_by INT UNSIGNED  NULL     COMMENT 'users.id of the admin who confirmed it',
    validated_at DATETIME      NOT NULL,
    UNIQUE KEY uq_fo_ticker_field (ticker, field_name),
    INDEX idx_fo_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
