-- Migration 037: admin-defined peer groups
--
-- Yahoo classifies by corporate form, not by what a company actually competes
-- on. Two cases this exists for, and they behave differently:
--
--   1. SEGMENT DOMINANCE (time-varying). Samsung is filed under Consumer
--      Electronics, Micron and SK hynix under Semiconductors, Seagate/WDC/
--      SanDisk under Computer Hardware — yet in this cycle they all live or die
--      on memory pricing. That grouping is true today and may be false in two
--      years, so these overrides carry a review_date.
--
--   2. REGION + REGULATION (structural). Polish banks are a peer group because
--      they share a regulator, a rate environment and a market; Yahoo's global
--      "Banks - Regional" lumps them in with US regionals. Nothing about that
--      expires, so review_date stays NULL.
--
-- Additive by design: Yahoo's `industry` is never modified. The override only
-- changes which bucket the Valuation pillar benchmarks against, and every
-- snapshot records the bucket actually used (migration 036), so a historical
-- score stays explainable and the grouping stays falsifiable — the track record
-- can be read per grouping rather than silently mixing regimes.
--
-- Guardrail the reason column exists to enforce: an override is a CLASSIFICATION
-- decision ("this company competes with those"), never a SCORE decision ("this
-- company should rank higher"). The audit trail is what keeps that honest.

CREATE TABLE IF NOT EXISTS peer_bucket_override (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    ticker      VARCHAR(20)     NOT NULL,
    bucket_key  VARCHAR(160)    NOT NULL COMMENT 'Peer bucket to benchmark against. An existing Yahoo industry name reclassifies; a new name creates a custom group.',
    reason      TEXT            NULL     COMMENT 'Why this company belongs with that group — required reading before anyone changes it',
    review_date DATE            NULL     COMMENT 'NULL = structural grouping (region/regulator). A date = cycle-dependent, revisit then.',
    created_by  INT UNSIGNED    NULL     COMMENT 'users.id of the admin who set it',
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NULL,
    UNIQUE KEY uq_pbo_ticker (ticker),
    INDEX idx_pbo_bucket (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
