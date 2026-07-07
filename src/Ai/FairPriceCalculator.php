<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * CVS implied fair value: price at which Valuation pillar = 50 (sector-median parity).
 * Fair EV = median_ev_fcf × FCF × (1 + growth_capped)²
 * Fair Price = (Fair EV - debt + cash) / shares
 *
 * Extracted from AiAnalysisController — change: cvs-ai-critical-review — so
 * bin/generate_critical_review.php (no HTTP request, no controller instance)
 * can compute the same figure the etap-1 endpoints use.
 */
final class FairPriceCalculator
{
    /**
     * @param array<string, mixed> $financials
     * @param array<string, mixed> $cvsConfig  Full config/cvs-weights.php
     */
    public static function compute(array $financials, array $cvsConfig): ?float
    {
        $sector     = (string) ($financials['sector'] ?? 'DEFAULT');
        $benchmarks = $cvsConfig['benchmarks'] ?? [];
        $bm         = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? [];
        $medEvFcf   = (float) ($bm['median_ev_fcf'] ?? 0);
        $maxGrowth  = (float) ($bm['max_growth']    ?? 20);

        $fcf = (float) ($financials['free_cash_flow'] ?? 0);
        if ($fcf <= 0) $fcf = (float) ($financials['free_cash_flow_adjusted'] ?? 0);

        $debt   = (float) ($financials['total_debt']         ?? 0);
        $cash   = (float) ($financials['cash']               ?? 0);
        $shares = (float) ($financials['shares_outstanding'] ?? 0);

        $fwdEps   = (float) ($financials['forward_eps']  ?? 0);
        $trailEps = (float) ($financials['trailing_eps'] ?? 0);
        $growth   = null;
        if ($fwdEps > 0 && $trailEps > 0) {
            $implied = ($fwdEps / $trailEps - 1) * 100;
            if ($implied > 0 && $implied <= 200) $growth = $implied;
        }
        if ($growth === null) {
            $rg = (float) ($financials['revenue_growth'] ?? 0);
            if ($rg > 0) $growth = $rg * 100;
        }
        if ($growth !== null) $growth = min($growth, $maxGrowth);

        if ($fcf <= 0 || $growth === null || $medEvFcf <= 0 || $shares <= 0) {
            return null;
        }

        $fwdFcf = $fcf * (1 + $growth / 100) ** 2;
        $fairEv = $medEvFcf * $fwdFcf;
        $price  = ($fairEv - $debt + $cash) / $shares;

        if ($price <= 0) {
            return null;
        }

        // Sanity bounds: fair value must be within 0.05x - 10x current price.
        // Values outside this range indicate a data quality problem (currency mismatch,
        // unusual share structure, stale FCF) — suppress rather than mislead.
        $currentPrice = (float) ($financials['current_price'] ?? 0);
        if ($currentPrice > 0) {
            $ratio = $price / $currentPrice;
            if ($ratio > 10.0 || $ratio < 0.05) {
                return null;
            }
        }

        return round($price, 2);
    }
}
