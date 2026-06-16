-- multi-currency-fx Phase 3: audit columns for FX conversion
-- Additive-only — existing 3.0 rows remain intact (NULL = pre-FX era).
-- price_at_snapshot semantics change to USD for new 4.0 rows; old 3.0
-- rows retain native price but are excluded by the live model_version filter.

ALTER TABLE cvs_snapshots
    ADD COLUMN fx_rate_to_usd  DOUBLE     NULL AFTER earnings_guard_active,
    ADD COLUMN native_currency VARCHAR(8) NULL AFTER fx_rate_to_usd,
    ADD COLUMN native_price    DOUBLE     NULL AFTER native_currency;
