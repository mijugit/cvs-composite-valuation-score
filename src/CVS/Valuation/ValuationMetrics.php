<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

/**
 * Pure valuation metric calculations — no I/O, no state.
 *
 * Single source of truth for forward EV/FCF and EV/Sales formulas shared
 * by ValuationPillar (scoring) and the peer-median pipeline (batch crawl).
 * Both consumers must use this class so the medians and the scores are always
 * computed with the same arithmetic.
 *
 * Mirrors the logic previously embedded in ValuationPillar::score() (Phase 3
 * refactor extracts it here without changing behaviour — covered by regression
 * tests on CVSModelTest::baseFinancials() fixtures).
 */
class ValuationMetrics
{
    /**
     * Derive forward annual growth rate (%) from available financials.
     *
     * Priority order (mirrors Python v1.6 calc_relative):
     *   a. EPS-based: forwardEps / trailingEps − 1
     *      — skipped if > 2.0 (base effect) or > 3.5× revenue_growth (EPS/revenue gap)
     *   b. revenue_growth × 100  (when > 0)
     *   c. earnings_quarterly_growth × 100  (when 0 < value ≤ 2.0 fraction)
     *   d. null → caller returns neutral 50 / skips ticker
     *
     * @param array<string, mixed> $financials
     * @return float|null  Growth rate in % (e.g. 16.6), or null if unavailable
     */
    public static function extractForwardGrowth(array $financials): ?float
    {
        $forwardEps  = isset($financials['forward_eps'])  ? (float) $financials['forward_eps']  : null;
        $trailingEps = isset($financials['trailing_eps']) ? (float) $financials['trailing_eps'] : null;
        $revGrowth   = isset($financials['revenue_growth']) ? (float) $financials['revenue_growth'] : null;

        // a. EPS-based forward growth (fraction → %).
        if ($forwardEps !== null && $trailingEps !== null && $trailingEps > 0) {
            $epsFraction = ($forwardEps / $trailingEps) - 1.0;
            $baseEffect  = $epsFraction > 2.0;
            $epsRevGap   = $revGrowth !== null
                        && $revGrowth > 0
                        && ($epsFraction / $revGrowth) > 3.5;

            if (!$baseEffect && !$epsRevGap) {
                return $epsFraction * 100.0;
            }
        }

        // b. Revenue growth (fraction → %).
        if ($revGrowth !== null && $revGrowth > 0) {
            return $revGrowth * 100.0;
        }

        // c. Earnings quarterly growth (fraction → %).
        $eqg = isset($financials['earnings_quarterly_growth'])
             ? (float) $financials['earnings_quarterly_growth']
             : null;

        if ($eqg !== null && $eqg > 0 && $eqg <= 2.0) {
            return $eqg * 100.0;
        }

        return null;
    }

    /**
     * Compute Enterprise Value from financials.
     *
     * EV = marketCap + totalDebt − cash
     * Returns null when price or shares_outstanding are absent/non-positive,
     * or when the resulting EV ≤ 0.
     *
     * @param array<string, mixed> $financials
     * @return float|null
     */
    public static function enterpriseValue(array $financials): ?float
    {
        $price  = $financials['current_price']      ?? null;
        $shares = $financials['shares_outstanding'] ?? null;

        if ($price === null || $shares === null || (float) $shares <= 0) {
            return null;
        }

        $debt = (float) ($financials['total_debt'] ?? 0.0);
        $cash = (float) ($financials['cash']       ?? 0.0);
        $ev   = ((float) $price * (float) $shares) + $debt - $cash;

        return $ev > 0 ? $ev : null;
    }

    /**
     * Resolve the forward FCF estimate for use as an EV/FCF denominator (FR-011).
     *
     * Shared by ValuationPillar (scoring) and FairPriceCalculator (fair value) so
     * both compute a company's forward FCF the same way — the same guardrail
     * MedianResolver::fromConfig() gives the peer-median ladder ("every caller
     * shares one configuration"). Before this was extracted, FairPriceCalculator
     * always used trailing_fcf × (1+g)² while the pillar preferred this analyst
     * estimate, so the two disagreed — sometimes about direction — for exactly
     * the companies where the estimate diverges most from a trailing projection
     * (e.g. NVDA post-beat, where forward FCF outruns trailing growth math).
     *
     * Returns the estimate (float) when the feature flag is on and the FCF/EPS
     * conversion ratio (free_cash_flow / shares_outstanding / trailing_eps) sits
     * within [fcf_to_eps_ratio_min, fcf_to_eps_ratio_max]; null otherwise —
     * callers fall back to trailing_fcf × (1+g)².
     *
     * @param array<string, mixed> $financials
     * @param array<string, mixed> $valuationConfig config['valuation'] section
     */
    public static function resolveForwardFcfEst(array $financials, array $valuationConfig = []): ?float
    {
        if (!($valuationConfig['use_forward_fcf_estimate'] ?? true)) {
            return null;
        }

        $fwdFcfEst   = $financials['forward_fcf_est']        ?? null;
        $trailingEps = isset($financials['trailing_eps'])       ? (float) $financials['trailing_eps']       : null;
        $fcf         = isset($financials['free_cash_flow'])     ? (float) $financials['free_cash_flow']     : null;
        $shares      = isset($financials['shares_outstanding']) ? (float) $financials['shares_outstanding'] : null;

        if ($fwdFcfEst === null) {
            return null;
        }
        if ($trailingEps === null || $trailingEps <= 0.0) {
            return null;
        }
        if ($fcf === null || $fcf <= 0.0) {
            return null;
        }
        if ($shares === null || $shares <= 0.0) {
            return null;
        }

        // Ratio = FCF per share / trailing EPS — dimensionless "FCF conversion ratio".
        // Typical range [0.3, 3.0]: FCF is usually 30–300% of EPS.
        // Outside bounds → pathological case (near-zero EPS, extreme capex cycle) → fallback.
        $fcfPerShare = $fcf / $shares;
        $ratio       = $fcfPerShare / $trailingEps;
        $ratioMin    = (float) ($valuationConfig['fcf_to_eps_ratio_min'] ?? 0.3);
        $ratioMax    = (float) ($valuationConfig['fcf_to_eps_ratio_max'] ?? 3.0);

        if ($ratio < $ratioMin || $ratio > $ratioMax) {
            return null;
        }

        return (float) $fwdFcfEst;
    }

    /**
     * Forward EV/FCF ratio (Variant A — used when FCF > 0).
     *
     * Projects FCF two years forward using growth rate, then divides EV.
     * Returns null when any required input is absent or EV cannot be computed.
     *
     * FR-011: when $fwdFcfEst is provided (analyst-derived forward FCF, computed in
     * FinancialDataFetcher::normalise() as forward_eps × fcf/trailing_eps), it is used
     * directly as the denominator — forward_eps is already a 1-year forward estimate so
     * no additional (1+g)² projection is applied. When null, falls back to the original
     * trailing_fcf × (1+g)² formula (pre-normalization behaviour preserved).
     *
     * @param array<string, mixed> $financials
     * @param float                $growthPct   Forward growth rate in % (already capped to max_growth)
     * @param float|null           $fwdFcfEst   Analyst forward FCF estimate (FR-011); null = use growth formula
     * @return float|null
     */
    public static function forwardEvFcf(
        array  $financials,
        float  $growthPct,
        ?float $fwdFcfEst = null
    ): ?float {
        $ev  = self::enterpriseValue($financials);
        $fcf = $financials['free_cash_flow'] ?? null;

        if ($ev === null || $fcf === null || (float) $fcf <= 0) {
            return null;
        }

        // FR-011: use analyst forward FCF estimate when provided (ValuationPillar applies
        // bounds check before passing); otherwise fall back to trailing_fcf × (1+g)².
        $forwardFcf = ($fwdFcfEst !== null)
            ? $fwdFcfEst
            : (float) $fcf * ((1.0 + $growthPct / 100.0) ** 2);

        return $forwardFcf > 0 ? $ev / $forwardFcf : null;
    }

    /**
     * Growth-adjusted forward EV/Sales ratio (Variant B — FCF ≤ 0 or null).
     *
     * Normalises EV/Sales by a quality factor (growth × gross margin) and
     * returns the adjusted ratio. Returns null when revenue, gross margin,
     * or EV are absent/non-positive.
     *
     * @param array<string, mixed> $financials
     * @param float                $growthPct   Forward growth rate in % (already capped to max_growth)
     * @return float|null  Adjusted EV/Sales (not yet divided by sector target)
     */
    public static function forwardEvSalesAdjusted(array $financials, float $growthPct): ?float
    {
        $ev          = self::enterpriseValue($financials);
        $revenue     = $financials['revenue']       ?? null;
        $grossMargin = $financials['gross_margins'] ?? null; // float 0–1

        if ($ev === null || $revenue === null || (float) $revenue <= 0 || $grossMargin === null) {
            return null;
        }

        $fwdSales = (float) $revenue * ((1.0 + $growthPct / 100.0) ** 2);
        $evSales  = $ev / $fwdSales;

        // Normalise by growth × gross margin (quality factor).
        $qualityFactor = $growthPct * (float) $grossMargin;

        return $evSales / max($qualityFactor, 0.001);
    }

    /**
     * Sector target for the growth-adjusted EV/Sales ratio.
     *
     * Used as the denominator in Variant B scoring:
     *   ratio = forwardEvSalesAdjusted / sectorEvSalesTarget
     *
     * @param float $medianEvSales  Sector median EV/Sales from benchmarks
     * @param float $maxGrowth      Sector max growth cap from benchmarks
     * @param float $medianGmFrac   Sector median gross margin as fraction (0–1)
     * @return float
     */
    public static function sectorEvSalesTarget(
        float $medianEvSales,
        float $maxGrowth,
        float $medianGmFrac
    ): float {
        return $medianEvSales / max(($maxGrowth / 2.0) * $medianGmFrac, 0.001);
    }
}
