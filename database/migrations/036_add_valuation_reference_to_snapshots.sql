-- Migration 036: persist the Valuation pillar's peer-group resolution
--
-- CVSModel already computes, on every scoring run, WHICH benchmark the
-- Valuation pillar actually used:
--
--   valuation_reference = [
--     'source'  => 'subsector' | 'sector_fallback' | 'cold_start',
--     'bucket'  => <industry or sector name>,
--     'variant' => 'A' (EV/FCF) | 'B' (EV/Sales),
--   ]
--
-- ...and then throws it away. That is the authoritative answer to "did this
-- company get a real peer comparison, or did it fall back?", and it is
-- automatically correct per metric, because the pillar resolves whichever of
-- ev_fcf / ev_sales it actually used.
--
-- Without it, downstream code has to guess. The screener's "no peers" badge and
-- both wallet crons were guessing via peer_medians.sample_count for ev_fcf
-- alone — which mislabels every company valued through variant B (FCF <= 0),
-- and ev_sales buckets are consistently better populated than ev_fcf ones
-- (Technology: 94 vs 84). That guess withheld candidates from the autonomous
-- wallets on a false basis.
--
-- Additive and nullable: pre-migration rows keep NULL, which callers must read
-- as "unknown", never as "fell back".

ALTER TABLE cvs_snapshots
    ADD COLUMN valuation_source  VARCHAR(32)  NULL COMMENT 'subsector | sector_fallback | cold_start — which tier of the peer-group ladder the Valuation pillar landed on' AFTER pillar_scores,
    ADD COLUMN valuation_bucket  VARCHAR(160) NULL COMMENT 'The industry or sector bucket whose median was actually used' AFTER valuation_source,
    ADD COLUMN valuation_variant VARCHAR(8)   NULL COMMENT 'A = EV/FCF, B = EV/Sales — the metric this company was scored on' AFTER valuation_bucket;
