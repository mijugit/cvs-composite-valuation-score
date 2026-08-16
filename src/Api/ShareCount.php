<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * How many shares does this company actually have?
 *
 * Enterprise value is price x shares + debt - cash, so the share count sits
 * underneath every EV-based verdict the model reaches. Two things go wrong with
 * it, both silently, and both in the direction of "this looks cheap":
 *
 *  1. UNDERCOUNTING CLASSES. `sharesOutstanding` counts only the quoted class.
 *     Measured across this universe on 2026-08-16, 56 of 587 tickers (9.5%)
 *     diverge by more than 5%, with a long tail: VG +370%, SYM +366%,
 *     LEVI +283%, IBKR +276%, GOOGL +108%, ABNB +43%.
 *
 *  2. NO COUNT AT ALL. For 28 tickers (4.8% — MU, HD, CRM, LOW, TGT, ADI, MDT
 *     among them) Yahoo returns an empty array for `sharesOutstanding`,
 *     `impliedSharesOutstanding` and `marketCap` alike. Before this class the
 *     pillar then had no EV and returned a neutral 50, discarding 65% of the
 *     fundamental score on companies that were otherwise perfectly scorable.
 *
 * Pure and offline — no network, no clock, no database.
 */
final class ShareCount
{
    /** Officially filed count for the quoted class. Right for single-class companies. */
    public const SOURCE_REPORTED = 'reported';

    /**
     * Every class. Kept under the original label because snapshots already
     * carry it: the figure is the same whether Yahoo hands it over directly as
     * `impliedSharesOutstanding` or we divide market cap by price. Where both
     * exist they agree to the digit (GOOGL: 12,229,934,831).
     */
    public const SOURCE_ALL_CLASS = 'implied_market_cap';

    /** Arithmetic fallback: revenue / revenue-per-share. */
    public const SOURCE_DERIVED = 'revenue_per_share';

    /**
     * Above this gap the filed count and the all-class count are measuring
     * different things rather than rounding, and the all-class figure wins.
     * Below it, single-class tickers keep the officially filed number.
     */
    private const DIVERGENCE_THRESHOLD = 0.05;

    /**
     * @param float|null $reported        defaultKeyStatistics.sharesOutstanding
     * @param float|null $impliedField    defaultKeyStatistics.impliedSharesOutstanding
     * @param float|null $marketCap       price.marketCap (MAJOR currency)
     * @param float|null $priceMajor      quote in the MAJOR currency — a GBp price must
     *                                    already be divided by 100 or the quotient is 100x off
     * @param float|null $revenue         financialData.totalRevenue
     * @param float|null $revenuePerShare financialData.revenuePerShare (same currency as revenue)
     *
     * @return array{count: float|null, source: string|null}
     */
    public static function resolve(
        ?float $reported,
        ?float $impliedField,
        ?float $marketCap,
        ?float $priceMajor,
        ?float $revenue,
        ?float $revenuePerShare
    ): array {
        // The all-class figure: taken directly when Yahoo publishes it,
        // otherwise derived from market cap.
        $allClass = self::positive($impliedField);
        if ($allClass === null && $marketCap !== null && $priceMajor !== null && $priceMajor > 0.0) {
            $allClass = self::positive($marketCap / $priceMajor);
        }

        $reported = self::positive($reported);

        if ($allClass !== null) {
            // No filed count to compare against, or the two disagree by enough
            // that they cannot both be describing the same set of shares.
            if ($reported === null || abs($allClass / $reported - 1.0) > self::DIVERGENCE_THRESHOLD) {
                return ['count' => $allClass, 'source' => self::SOURCE_ALL_CLASS];
            }
            return ['count' => $reported, 'source' => self::SOURCE_REPORTED];
        }

        if ($reported !== null) {
            return ['count' => $reported, 'source' => self::SOURCE_REPORTED];
        }

        // Last resort. revenuePerShare is computed on DILUTED shares, so the
        // quotient covers every class; against tickers whose true figure is
        // known it lands within ~1% (AAPL +0.9%, NVDA +0.4%, GOOGL -1.0%).
        //
        // floatShares is deliberately NOT a rung here, even though it is the
        // only field present for those 28 tickers: it excludes closely-held
        // stock, so it undercounts — on GOOGL by 10.6% — and undercounting is
        // exactly the "looks cheap" error this class exists to prevent.
        // netIncomeToCommon/trailingEps was rejected too, because it breaks on
        // ADRs reporting EPS in another currency (JD: 9.93B vs a true ~1.39B).
        if ($revenue !== null && $revenuePerShare !== null && $revenuePerShare > 0.0) {
            $derived = self::positive($revenue / $revenuePerShare);
            if ($derived !== null) {
                return ['count' => $derived, 'source' => self::SOURCE_DERIVED];
            }
        }

        return ['count' => null, 'source' => null];
    }

    /** A share count that is not a positive, finite number is not a share count. */
    private static function positive(?float $value): ?float
    {
        if ($value === null || !is_finite($value) || $value <= 0.0) {
            return null;
        }
        return $value;
    }
}
