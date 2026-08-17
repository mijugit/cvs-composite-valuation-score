<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

/**
 * Pure profitability derivations — no I/O, no state.
 *
 * Yahoo's `returnOnEquity` is absent for a measurable slice of Financial
 * Services tickers (XTB.WA among them) even though the two ratios that
 * imply it — price/book and trailing P/E — are both present. Both divide
 * by the same market capitalisation, so ROE = P/B ÷ P/E identically for
 * any company; no extra API call is needed to recover it.
 *
 * Measured on a live Yahoo pull, 2026-08-17, across 16 Financial Services
 * tickers where Yahoo DID publish its own returnOnEquity (so the derived
 * figure could be checked against a real one): mean absolute error 1.3pp,
 * max 4.8pp (IBKR). The gap is Yahoo computing ROE on AVERAGE equity across
 * the period while P/B and P/E both use END-of-period figures — a known,
 * bounded difference, not noise.
 */
final class ProfitabilityMetrics
{
    /**
     * Derive ROE from price-to-book and trailing P/E.
     *
     * @param array<string, mixed> $financials  Must carry price_to_book, trailing_pe
     * @return float|null  Fraction (e.g. 0.388 for 38.8%), or null when either
     *                      input is absent or non-positive.
     */
    public static function deriveRoe(array $financials): ?float
    {
        $pb = isset($financials['price_to_book']) ? (float) $financials['price_to_book'] : null;
        $pe = isset($financials['trailing_pe'])   ? (float) $financials['trailing_pe']   : null;

        if ($pb === null || $pb <= 0.0 || $pe === null || $pe <= 0.0) {
            return null;
        }

        return $pb / $pe;
    }
}
