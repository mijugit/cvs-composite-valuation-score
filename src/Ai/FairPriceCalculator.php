<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\ProfitabilityMetrics;

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
     * Implied fair price for a financial: the peer-median book multiple applied
     * to the company's own book value per share.
     *
     *   Fair Price = median_pb_roe × ROE × book_value_per_share
     *
     * The ROE term is what keeps this honest. A bank's book multiple is a
     * function of the return that earns it, so the pillar equates it at
     * P/B ÷ ROE = peer median; fair value has to sit on the same axis or the two
     * disagree in adjacent screener columns — the failure this class was already
     * fixed for once. Without it, ING.WA's fair value read $70.55 against a
     * $120.32 price (-41%) purely for being the most profitable bank in its
     * group. A bank with no positive ROE falls back to the plain median P/B.
     *
     * @param array<string, mixed> $financials
     * @param array<string, mixed> $cvsConfig
     */
    private static function computeForFinancial(
        array $financials,
        array $cvsConfig,
        ?MedianResolver $resolver,
        string $sector
    ): ?float {
        $bvps = isset($financials['book_value_per_share'])
            ? (float) $financials['book_value_per_share']
            : 0.0;
        if ($bvps <= 0) {
            return null;
        }

        $benchmarks = $cvsConfig['benchmarks'] ?? [];
        $bm         = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? [];

        // Match the pillar's axis or the two contradict each other in adjacent
        // screener columns — the exact failure this class was fixed for once
        // already. Since the pillar equates a bank at P/B ÷ ROE = peer median,
        // the fair book multiple is that median TIMES the company's own ROE.
        $roe       = isset($financials['return_on_equity']) ? (float) $financials['return_on_equity'] : null;
        $roeSource = $financials['return_on_equity_source'] ?? null;
        $pbRoeCfg  = is_array($cvsConfig['financials']['pb_roe'] ?? null) ? $cvsConfig['financials']['pb_roe'] : [];
        $roeConditioningEnabled = !empty($pbRoeCfg['enabled']);

        // Same two declines as ValuationPillar::scoreVariantC(), on the same
        // inputs — fair value has to agree with the pillar about whether it has
        // an opinion at all, not just about the axis once it does. A true ROE
        // gap (neither Yahoo nor P/B÷P/E) or a reported-vs-derived ROE mismatch
        // (a broken price/book, typically a currency-mismatched cross-listing)
        // both mean the inputs cannot be trusted; a fair price computed from them
        // anyway would contradict a pillar that declined to score the same
        // company right next to it.
        if ($roeConditioningEnabled) {
            if ($roe === null) {
                return null;
            }
            if ($roeSource === 'yahoo' && $roe > 0.0) {
                $maxDivergence = (float) ($pbRoeCfg['max_roe_divergence'] ?? 0.0);
                $roeCheck      = $maxDivergence > 0.0 ? ProfitabilityMetrics::deriveRoe($financials) : null;
                if ($roeCheck !== null && $roeCheck > 0.0) {
                    $divergence = max($roeCheck / $roe, $roe / $roeCheck);
                    if ($divergence > $maxDivergence) {
                        return null;
                    }
                }
            }
        }

        $useRoe = $roeConditioningEnabled && $roe !== null && $roe > 0.0;

        $metric   = $useRoe ? 'pb_roe' : 'pb';
        $medianMx = (float) ($bm[$useRoe ? 'median_pb_roe' : 'median_pb'] ?? 0);

        if ($resolver !== null) {
            $resolved = $resolver->resolve(
                (string) ($financials['industry'] ?? ''),
                $sector,
                $metric
            );
            if ($resolved->isValid()) {
                $medianMx = (float) $resolved->value;
            }
        }

        if ($medianMx <= 0) {
            return null;
        }

        $medianPb = $useRoe ? $medianMx * $roe : $medianMx;
        if ($medianPb <= 0) {
            return null;
        }

        $price = round($medianPb * $bvps, 2);
        if ($price <= 0) {
            return null;
        }

        // Same sanity band as the main path: a figure outside it means a data
        // problem (currency mismatch, odd share structure), not an opportunity.
        $currentPrice = (float) ($financials['current_price'] ?? 0);
        if ($currentPrice > 0) {
            $ratio = $price / $currentPrice;
            if ($ratio > 10.0 || $ratio < 0.05) {
                return null;
            }
        }

        return $price;
    }

    /**
     * @param array<string, mixed> $financials
     * @param array<string, mixed> $cvsConfig  Full config/cvs-weights.php
     * @param MedianResolver|null  $resolver   Peer-group medians (phase 3). Pass it
     *        wherever one is available: without it this falls back to the STATIC
     *        sector benchmark, which is what made fair value contradict the
     *        Valuation pillar sitting next to it in the screener — ASB.WA scored
     *        as fairly valued against its industry median of 10.3x while the FV
     *        column, still using Technology's static 32x, claimed +722% upside.
     */
    public static function compute(array $financials, array $cvsConfig, ?MedianResolver $resolver = null): ?float
    {
        $sector     = (string) ($financials['sector'] ?? 'DEFAULT');

        // Financials get their own formula. The EV/FCF path below cannot produce
        // a number for a bank — there is no meaningful free cash flow — so the FV
        // column was simply blank for the whole sector.
        $financialSectors = is_array($cvsConfig['financials']['sectors'] ?? null)
            ? $cvsConfig['financials']['sectors']
            : [];
        if (in_array($sector, $financialSectors, true)) {
            return self::computeForFinancial($financials, $cvsConfig, $resolver, $sector);
        }

        $benchmarks = $cvsConfig['benchmarks'] ?? [];
        $bm         = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? [];
        $medEvFcf   = (float) ($bm['median_ev_fcf'] ?? 0);
        // max_growth stays sector-level: it is a cap on extrapolated growth, not
        // a peer multiple, and peer_medians carries no equivalent. Worth revisiting
        // — 60% for Technology is generous for a distributor — but that is a
        // methodology change, not this fix.
        $maxGrowth  = (float) ($bm['max_growth']    ?? 20);

        // Same resolution ladder ValuationPillar uses: industry median when the
        // bucket is deep enough, else sector, else the static benchmark above.
        if ($resolver !== null) {
            $resolved = $resolver->resolve(
                (string) ($financials['industry'] ?? ''),
                $sector,
                'ev_fcf'
            );
            if ($resolved->isValid()) {
                $medEvFcf = (float) $resolved->value;
            }
        }

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
