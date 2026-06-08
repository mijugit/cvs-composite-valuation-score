-- Phase 5 (slice 2): persist earnings-timing markers on CVS snapshots (FR-008).
-- days_since_earnings / days_to_earnings: integer day-counts injected at fetch-time
--   (DateTimeImmutable $referenceDate — determinism seam, FR-015; see
--   EarningsCalendarParser). May be NULL when the ticker has no `calendarEvents`
--   coverage. days_to_earnings may be NEGATIVE (Yahoo calendar/data-lag signal —
--   see EarningsGuard::state()'s 'in_transit' classification; not an error).
-- earnings_state: 'before' | 'after' | 'in_transit' | NULL — mirrors
--   CVSResult::$earningsTiming['state'] (see EarningsGuard::state()).
-- earnings_guard_active: 1 when the proximity guard is active for this snapshot
--   (state !== NULL), 0 otherwise; NULL when no earnings-calendar coverage at all
--   (mirrors $earningsTiming === null, distinguishing "no data" from "out of window").
--
-- Additive, nullable columns — no existing data is altered or lost (FR-019).
-- Column order: placed together right after model_version, alongside the other
-- per-snapshot model-output fields (mirrors migration 013's grouping rationale).
--
-- Rollback: ALTER TABLE cvs_snapshots
--             DROP COLUMN earnings_guard_active,
--             DROP COLUMN earnings_state,
--             DROP COLUMN days_to_earnings,
--             DROP COLUMN days_since_earnings;

ALTER TABLE cvs_snapshots
    ADD COLUMN days_since_earnings   INT          NULL AFTER model_version,
    ADD COLUMN days_to_earnings      INT          NULL AFTER days_since_earnings,
    ADD COLUMN earnings_state        VARCHAR(20)  NULL AFTER days_to_earnings,
    ADD COLUMN earnings_guard_active TINYINT(1)   NULL AFTER earnings_state;
