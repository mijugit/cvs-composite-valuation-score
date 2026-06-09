<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: CVS Earnings Timing Awareness (Faza 5, plaster 2)

- **Plan**: context/changes/cvs-earnings-timing/plan.md
- **Scope**: Phases 1-4 of 4 (full plan)
- **Date**: 2026-06-08
- **Verdict**: APPROVED
- **Findings**: 0 critical, 0 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Verification (re-run fresh this session)

- `php -l templates/analysis.php templates/screener.php` → no syntax errors
- `vendor/bin/phpstan analyse` (level 6) → No errors (40/40)
- `vendor/bin/phpunit` → OK (333 tests, 718 assertions)

## Plan-drift sub-agent summary (12/12 MATCH)

All "Changes Required" items across all 4 phases verified MATCH against actual code with file:line evidence:
1. `FinancialDataFetcher::MODULES` gained `'calendarEvents'`
2. `$referenceDate` created once in `fetch()`, threaded through `normalise()`
3. `EarningsCalendarParser::parse()` — correct contract, floor/ceil math, negative `days_to` allowed, first-of-range date handling
4. `$financials` gained `days_since_earnings`/`days_to_earnings`
5. `earnings_guard` config block shaped exactly per plan
6. `EarningsGuard::state/penalty` — precedence and formula match plan verbatim
7. `CVSModel::computeEarningsTiming()` + extended `computeOverlay()` wiring
8. `CVSResult::$earningsTiming` readonly field, `passed()`/`toArray()` wiring, `failed()` untouched
9. Migration 015 — additive, nullable, correct `AFTER` placement, documented rollback
10. `CvsSnapshotRepository::save()` — 4 new bind params in both INSERT and UPDATE
11. `templates/analysis.php` — independent badge block, not nested in `$overlay !== null`
12. `templates/screener.php` — `$earningsChip` closure + `<th>`/`<td>` mirroring `$signalChip`

Scope check: diff contains exactly the 10 planned production files + 4 expected test files. No scope creep.

## Safety/quality/pattern sub-agent summary

- **Security**: no SQL injection (prepared statements, named params); no XSS (`htmlspecialchars` / closed `match()` enums in templates); no hardcoded secrets.
- **Performance**: `calendarEvents` rides the existing `quoteSummary` call — no new round-trip (FR-018/NFR confirmed).
- **Reliability**: `EarningsCalendarParser` returns `null` on every missing/malformed path, never throws; `CvsSnapshotRepository::save()` guards all new fields with `isset()` before casting.
- **Determinism (FR-015)**: `$referenceDate = new DateTimeImmutable()` appears exactly once in `fetch()`; zero live `date()`/`time()`/`new DateTime*` calls in `EarningsCalendarParser`, `EarningsGuard`, or `CVSModel`.
- **Data safety (FR-019)**: migration 015 is additive-only (`ADD COLUMN`, all nullable), documented rollback present.
- **Pattern compliance**: `EarningsCalendarParser`/`EarningsGuard` mirror `EarningsTrendParser`/`OverlayPenalties` (final, static, pure, config-driven thresholds — no hardcoded magic numbers); `earnings_guard` config mirrors `overlays` shape; new test files follow sibling naming/structure conventions including determinism-guard test cases.

## Findings

### F1 — Helper named `epoch()` instead of plan's suggested `raw()`

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: src/Forecast/EarningsCalendarParser.php:124-129
- **Detail**: The plan's contract said the unwrap helper should be "1:1 z `EarningsTrendParser::raw()`" (i.e. named `raw()`). The implementation names it `epoch()`. Functionally identical — unwraps `{"raw": x, "fmt": "y"}` and converts to an integer epoch. Arguably clearer naming for what it returns in this context. Zero behavioral drift; fully covered by `EarningsCalendarParserTest::test_is_deterministic_for_identical_inputs` and friends.
- **Fix**: Leave as-is — `epoch()` is arguably clearer than mirroring `raw()` verbatim here; renaming now would only churn a well-tested, working private helper for no functional gain.
- **Decision**: ACCEPTED (left as-is per user's "save report only" choice — finding is cosmetic, no fix warranted)
