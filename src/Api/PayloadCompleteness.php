<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Guards against scoring a Yahoo payload that arrived structurally intact but
 * materially empty.
 *
 * Yahoo's quoteSummary can return every requested module with a 200 and still
 * hand back an empty `incomeStatementHistory` array — observed live on MU over
 * 2026-08-13/14, while price/EBITDA/EPS/FCF all came through normally and the
 * request itself was healthy (sub-second, valid crumb, no rate limiting). The
 * result is a payload that looks fetchable but cannot support a meaningful
 * score.
 *
 * Persisting such a run is worse than skipping it: the snapshot carries no
 * usable numbers, yet its `score_date` becomes the ticker's newest row and so
 * hides the last good snapshot from
 * ScreenerRepository::findAllLatest()'s MAX(score_date) lookup. On MU that
 * silently removed the ticker from the LLM wallet's entire candidate universe,
 * which in turn made its open position unsellable (the executor prices trades
 * only from screener rows).
 *
 * Pure and I/O-free by design — same contract as the scoring pillars, so it is
 * unit-testable offline against a fixture payload.
 */
final class PayloadCompleteness
{
    /**
     * Fields without which a CVS score would be built on absent fundamentals
     * rather than on the company's actual figures.
     *
     * Deliberately minimal: this is a "did the upstream payload arrive empty"
     * check, NOT a second quality gate. Business judgements (margins, leverage,
     * liquidity) stay in QualityGate, which is allowed to reject a company on
     * merit. Anything listed here means "we cannot tell", not "the company is
     * weak".
     */
    private const ESSENTIAL_FIELDS = ['revenue'];

    /**
     * @param  array<string, mixed> $financials normalised FinancialDataFetcher payload
     * @return list<string>         essential fields that are absent or null; empty = usable
     */
    public static function missingEssentialFields(array $financials): array
    {
        $missing = [];
        foreach (self::ESSENTIAL_FIELDS as $field) {
            if (($financials[$field] ?? null) === null) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /** @param array<string, mixed> $financials */
    public static function isScorable(array $financials): bool
    {
        return self::missingEssentialFields($financials) === [];
    }
}
