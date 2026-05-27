<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar (b) — Sector benchmark comparison (EV/FCF vs. hardcoded sector medians).
 *
 * Score 0–100.
 *
 * Logic mirrors Python cvs_analyze.py calc_relative() (v1.6):
 *  - Variant A (FCF > 0): forward EV/FCF vs. sector median_ev_fcf
 *  - Variant B (FCF ≤ 0 or null): forward EV/Sales growth-adjusted vs. sector target
 *  - Sigmoid with k=3 maps ratio→score; ratio=1 → 50, ratio<1 → >50 (cheap), ratio>1 → <50 (expensive)
 *
 * Benchmarks are hardcoded per sector and injected via config/cvs-weights.php.
 *
 * Weight in CVS model: 0.25 (configured in config/cvs-weights.php).
 */
class SectorBenchmarkPillar
{
    /**
     * @param array<string, array<string, float|int>> $benchmarks  Sector benchmark data from config
     */
    public function __construct(private readonly array $benchmarks = []) {}

    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        if (empty($this->benchmarks)) {
            return 50.0;
        }

        // --- 1. Resolve sector benchmark ---
        $sector = $financials['sector'] ?? null;
        $bm     = $this->benchmarks[$sector] ?? $this->benchmarks['DEFAULT'] ?? null;

        if ($bm === null) {
            return 50.0;
        }

        // --- 2. Calculate Enterprise Value ---
        $price             = $financials['current_price']      ?? null;
        $sharesOutstanding = $financials['shares_outstanding'] ?? null;
        $totalDebt         = (float) ($financials['total_debt'] ?? 0.0);
        $cash              = (float) ($financials['cash']       ?? 0.0);

        if ($price === null || $sharesOutstanding === null || (float) $sharesOutstanding <= 0) {
            return 50.0;
        }

        $ev = ((float) $price * (float) $sharesOutstanding) + $totalDebt - $cash;

        if ($ev <= 0) {
            return 50.0;
        }

        // --- 3. Extract forward growth rate (%) ---
        $growthPct = $this->extractForwardGrowth($financials);

        if ($growthPct === null) {
            return 50.0;
        }

        // Cap growth to sector maximum.
        $growthPct = min($growthPct, (float) $bm['max_growth']);

        // --- 4. Variant A: forward EV/FCF (when FCF > 0) ---
        $fcf = $financials['free_cash_flow'] ?? null;

        if ($fcf !== null && (float) $fcf > 0) {
            $forwardFcf = (float) $fcf * ((1.0 + $growthPct / 100.0) ** 2);
            $evFcf      = $ev / $forwardFcf;
            $ratio      = $evFcf / (float) $bm['median_ev_fcf'];

            return $this->sigmoid($ratio);
        }

        // --- 5. Variant B: growth-adjusted EV/Sales (when FCF ≤ 0 or null) ---
        $revenue     = $financials['revenue']       ?? null;
        $grossMargin = $financials['gross_margins'] ?? null; // float 0–1 (e.g. 0.48)

        if ($revenue === null || (float) $revenue <= 0 || $grossMargin === null) {
            return 50.0;
        }

        $fwdSales   = (float) $revenue * ((1.0 + $growthPct / 100.0) ** 2);
        $evSales    = $ev / $fwdSales;

        // Adjusted: normalise EV/Sales by quality factor (growth × gross margin).
        $adjusted   = $evSales / max($growthPct * (float) $grossMargin, 0.001);

        // Sector target: median EV/Sales normalised by (half max_growth × median GM).
        $medianGmFrac = (float) $bm['median_gm'] / 100.0;
        $target       = (float) $bm['median_ev_sales']
                      / max(((float) $bm['max_growth'] / 2.0) * $medianGmFrac, 0.001);

        $ratio = $adjusted / max($target, 0.01);

        return $this->sigmoid($ratio);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Derive forward annual growth rate (%) from available financials.
     *
     * Priority order (mirrors Python v1.6 calc_relative, comparisons on fractions):
     *   a. EPS-based: forwardEps / trailingEps − 1  (as fraction)
     *      — skipped if > 2.0 (base effect) or > 3.5× revenue_growth (EPS/revenue gap)
     *   b. revenue_growth × 100  (when > 0)
     *   c. earnings_quarterly_growth × 100  (when 0 < value ≤ 2.0 fraction)
     *   d. null → caller returns neutral 50
     *
     * @param array<string, mixed> $financials
     * @return float|null  Growth rate in % (e.g. 16.6), or null if unavailable
     */
    private function extractForwardGrowth(array $financials): ?float
    {
        $forwardEps  = isset($financials['forward_eps'])  ? (float) $financials['forward_eps']  : null;
        $trailingEps = isset($financials['trailing_eps']) ? (float) $financials['trailing_eps'] : null;
        $revGrowth   = isset($financials['revenue_growth']) ? (float) $financials['revenue_growth'] : null;

        // a. EPS-based forward growth (fraction).
        if ($forwardEps !== null && $trailingEps !== null && $trailingEps > 0) {
            $epsFraction = ($forwardEps / $trailingEps) - 1.0;

            $baseEffect = $epsFraction > 2.0; // > 200%
            $epsRevGap  = $revGrowth !== null
                       && $revGrowth > 0
                       && ($epsFraction / $revGrowth) > 3.5;

            if (!$baseEffect && !$epsRevGap) {
                return $epsFraction * 100.0; // convert to %
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

        if ($eqg !== null && $eqg > 0 && $eqg <= 2.0) { // 0 < eqg ≤ 200%
            return $eqg * 100.0;
        }

        return null;
    }

    /**
     * Sigmoid score: ratio=1 → 50, ratio<1 → >50 (cheap), ratio>1 → <50 (expensive).
     *
     * Formula: 100 / (1 + exp(3 × (ratio − 1)))  — identical to Python v1.6.
     */
    private function sigmoid(float $ratio): float
    {
        $score = 100.0 / (1.0 + exp(3.0 * ($ratio - 1.0)));
        return round(min(100.0, max(0.0, $score)), 2);
    }
}
