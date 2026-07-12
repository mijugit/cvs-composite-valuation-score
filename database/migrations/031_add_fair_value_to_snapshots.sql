-- Persist CVS Fair Value alongside each snapshot so the screener can show a
-- margin-vs-price column without recomputing it per row (FairPriceCalculator
-- needs the full $financials array, which snapshot rows don't carry).
-- Additive-only — existing rows get NULL until the next rescore run.

ALTER TABLE cvs_snapshots
    ADD COLUMN fair_value_price DECIMAL(10,2) NULL AFTER native_price;
