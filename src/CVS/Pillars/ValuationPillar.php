<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\ValuationMetrics;

/**
 * Pillar — Valuation (EV/FCF vs peer-group or sector medians).
 *
 * Phase 3 update: supports two modes, selected by constructor args.
 *
 * **Legacy mode** (resolver = null):
 *   Behaves exactly as before — uses static benchmarks from config.
 *   CVSModelTest fixtures and cold-start deployments use this path.
 *
 * **Peer-group mode** (resolver != null):
 *   Resolves the benchmark median dynamically (subsector → sector → static fallback).
 *   Also computes an anchor score at the sector level and applies anchor_blend
 *   (default: min) to guard against an overvalued whole subsector (FR-015).
 *
 * Score 0–100.
 *
 * Logic:
 *  - Variant A (FCF > 0): forward EV/FCF vs. resolved median_ev_fcf
 *  - Variant B (FCF ≤ 0 or null): growth-adjusted EV/Sales vs. resolved median_ev_sales
 *  - Sigmoid with k=3 maps ratio→score; ratio=1 → 50, ratio<1 → >50 (cheap)
 */
class ValuationPillar
{
    private string $lastSource       = 'cold_start';
    /** True when an admin-defined peer bucket replaced Yahoo's industry for this scoring run. */
    private bool   $lastOverrideActive = false;
    private string $lastBucketKey    = '';
    /** @var array<string, mixed> */
    private array  $lastSteps        = [];

    /**
     * @param array<string, array<string, float|int>> $benchmarks      Static benchmarks from config (fallback + legacy)
     * @param MedianResolver|null                     $resolver        Peer-group resolver (null = legacy mode)
     * @param string                                  $anchorBlend     'min' | 'weighted' (default 'min' — safe start)
     * @param float                                   $anchorWeight    Weight of sector anchor when blend='weighted' (0-1)
     * @param array<string, mixed>                    $valuationConfig config['valuation'] section — controls FR-011 FCF normalization
     */
    public function __construct(
        private readonly array           $benchmarks      = [],
        private readonly ?MedianResolver $resolver        = null,
        private readonly string          $anchorBlend     = 'min',
        private readonly float           $anchorWeight    = 0.3,
        private readonly array           $valuationConfig = [],
    ) {}

    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        return $this->resolver !== null
            ? $this->scoreWithPeerGroup($financials)
            : $this->scoreLegacy($financials);
    }

    // ------------------------------------------------------------------
    // Source info (for FR-005 transparency)
    // ------------------------------------------------------------------

    public function lastSource(): string
    {
        return $this->lastSource;
    }

    public function lastBucketKey(): string
    {
        return $this->lastBucketKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function steps(): array
    {
        return $this->lastSteps;
    }

    // ------------------------------------------------------------------
    // Peer-group path (Phase 3)
    // ------------------------------------------------------------------

    private function scoreWithPeerGroup(array $financials): float
    {
        assert($this->resolver !== null);

        $sector   = is_string($financials['sector']   ?? null) ? (string) $financials['sector']   : 'DEFAULT';
        $industry = is_string($financials['industry'] ?? null) ? (string) $financials['industry'] : '';

        // Admin-defined peer group (migration 037) substitutes the bucket for
        // BENCHMARK RESOLUTION ONLY. $financials['industry'] is left alone, so
        // the snapshot still records Yahoo's own classification and the override
        // stays additive and reversible. Yahoo files Samsung under Consumer
        // Electronics and Micron under Semiconductors even while both live on
        // memory pricing; this is where an operator's judgement about who
        // actually competes with whom enters the model.
        $override = is_string($financials['peer_bucket_override'] ?? null)
            ? trim((string) $financials['peer_bucket_override'])
            : '';
        if ($override !== '') {
            $industry = $override;
        }
        $this->lastOverrideActive = $override !== '';

        // Growth (needed for both variants).
        $growthPct = ValuationMetrics::extractForwardGrowth($financials);
        if ($growthPct === null) {
            $this->lastSource    = 'missing_growth';
            $this->lastBucketKey = '';
            return 50.0;
        }

        // Cap growth to sector max (from static benchmarks — not empirical).
        $bm = $this->benchmarks[$sector] ?? $this->benchmarks['DEFAULT'] ?? null;
        if ($bm !== null) {
            $growthPct = min($growthPct, (float) ($bm['max_growth'] ?? $growthPct));
        }

        // EV (shared by both variants).
        $ev = ValuationMetrics::enterpriseValue($financials);
        if ($ev === null) {
            return 50.0;
        }

        // --- Variant A: forward EV/FCF ---
        $fcf = $financials['free_cash_flow'] ?? null;
        if ($fcf !== null && (float) $fcf > 0) {
            return $this->scoreVariantA($financials, $growthPct, $ev, $sector, $industry);
        }

        // --- Variant B: growth-adjusted EV/Sales ---
        return $this->scoreVariantB($financials, $growthPct, $sector, $industry);
    }

    private function scoreVariantA(
        array  $financials,
        float  $growthPct,
        float  $ev,
        string $sector,
        string $industry
    ): float {
        assert($this->resolver !== null);

        $fwdFcfEst = $this->resolveForwardFcfEst($financials);
        $evFcf     = ValuationMetrics::forwardEvFcf($financials, $growthPct, $fwdFcfEst);
        if ($evFcf === null) {
            return 50.0;
        }

        // Peer-group median (subsector → sector → cold-start).
        $subResolution    = $this->resolver->resolve($industry, $sector, 'ev_fcf');
        $anchorResolution = $this->resolver->resolveSector($sector, 'ev_fcf');

        if (!$subResolution->isValid()) {
            return 50.0;
        }

        $subScore = $this->sigmoid($evFcf / $subResolution->value);

        // Anchor score (sector-level — guards against overvalued whole subsector).
        $anchorScore = $anchorResolution->isValid()
            ? $this->sigmoid($evFcf / $anchorResolution->value)
            : $subScore; // no anchor → no downward pressure

        $score = $this->blend($subScore, $anchorScore);

        // A resolved override is a REAL peer comparison, not a fallback — record it
        // distinctly so the screener badge and the wallet guards can tell
        // "operator grouped these deliberately" from "nothing to compare against".
        $this->lastSource    = ($this->lastOverrideActive && $subResolution->isSubsector())
            ? 'override'
            : $subResolution->source;
        $this->lastBucketKey = $subResolution->bucketKey;
        $this->lastSteps     = [
            'variant'       => 'A',
            'ev_fcf'        => round($evFcf, 4),
            'sub_median'    => round($subResolution->value, 4),
            'anchor_median' => $anchorResolution->isValid() ? round($anchorResolution->value, 4) : null,
            'sub_score'     => round($subScore, 2),
            'anchor_score'  => round($anchorScore, 2),
            'source'        => $subResolution->source,
            'bucket'        => $subResolution->bucketKey,
            'sample_count'  => $subResolution->sampleCount,
        ];

        return $score;
    }

    private function scoreVariantB(
        array  $financials,
        float  $growthPct,
        string $sector,
        string $industry
    ): float {
        assert($this->resolver !== null);

        $evSalesAdj = ValuationMetrics::forwardEvSalesAdjusted($financials, $growthPct);
        if ($evSalesAdj === null) {
            return 50.0;
        }

        $subResolution    = $this->resolver->resolve($industry, $sector, 'ev_sales');
        $anchorResolution = $this->resolver->resolveSector($sector, 'ev_sales');

        if (!$subResolution->isValid()) {
            return 50.0;
        }

        $subScore = $this->sigmoid($evSalesAdj / $subResolution->value);
        $anchorScore = $anchorResolution->isValid()
            ? $this->sigmoid($evSalesAdj / $anchorResolution->value)
            : $subScore;

        $score = $this->blend($subScore, $anchorScore);

        // A resolved override is a REAL peer comparison, not a fallback — record it
        // distinctly so the screener badge and the wallet guards can tell
        // "operator grouped these deliberately" from "nothing to compare against".
        $this->lastSource    = ($this->lastOverrideActive && $subResolution->isSubsector())
            ? 'override'
            : $subResolution->source;
        $this->lastBucketKey = $subResolution->bucketKey;
        $this->lastSteps     = [
            'variant'       => 'B',
            'ev_sales_adj'  => round($evSalesAdj, 4),
            'sub_median'    => round($subResolution->value, 4),
            'anchor_median' => $anchorResolution->isValid() ? round($anchorResolution->value, 4) : null,
            'sub_score'     => round($subScore, 2),
            'anchor_score'  => round($anchorScore, 2),
            'source'        => $subResolution->source,
            'bucket'        => $subResolution->bucketKey,
            'sample_count'  => $subResolution->sampleCount,
        ];

        return $score;
    }

    // ------------------------------------------------------------------
    // Legacy path (unchanged behavior — tests + cold-start)
    // ------------------------------------------------------------------

    private function scoreLegacy(array $financials): float
    {
        if (empty($this->benchmarks)) {
            return 50.0;
        }

        $sector = $financials['sector'] ?? null;
        $bm     = $this->benchmarks[$sector] ?? $this->benchmarks['DEFAULT'] ?? null;
        if ($bm === null) {
            return 50.0;
        }

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

        $growthPct = $this->legacyExtractForwardGrowth($financials);
        if ($growthPct === null) {
            return 50.0;
        }
        $growthPct = min($growthPct, (float) $bm['max_growth']);

        $fcf = $financials['free_cash_flow'] ?? null;
        if ($fcf !== null && (float) $fcf > 0) {
            $fwdFcfEst = $this->resolveForwardFcfEst($financials);
            $evFcf     = ValuationMetrics::forwardEvFcf($financials, $growthPct, $fwdFcfEst);
            if ($evFcf === null) {
                return 50.0;
            }
            $ratio = $evFcf / (float) $bm['median_ev_fcf'];
            return $this->sigmoid($ratio);
        }

        $revenue     = $financials['revenue']       ?? null;
        $grossMargin = $financials['gross_margins'] ?? null;

        if ($revenue === null || (float) $revenue <= 0 || $grossMargin === null) {
            return 50.0;
        }

        $fwdSales       = (float) $revenue * ((1.0 + $growthPct / 100.0) ** 2);
        $evSales        = $ev / $fwdSales;
        $adjusted       = $evSales / max($growthPct * (float) $grossMargin, 0.001);
        $medianGmFrac   = (float) $bm['median_gm'] / 100.0;
        $target         = (float) $bm['median_ev_sales']
                        / max(((float) $bm['max_growth'] / 2.0) * $medianGmFrac, 0.001);
        $ratio          = $adjusted / max($target, 0.01);

        return $this->sigmoid($ratio);
    }

    /**
     * Forward growth extraction — mirrors ValuationMetrics::extractForwardGrowth().
     * Kept inline in legacy path for zero-change backward compatibility.
     *
     * @param array<string, mixed> $financials
     */
    private function legacyExtractForwardGrowth(array $financials): ?float
    {
        $forwardEps  = isset($financials['forward_eps'])  ? (float) $financials['forward_eps']  : null;
        $trailingEps = isset($financials['trailing_eps']) ? (float) $financials['trailing_eps'] : null;
        $revGrowth   = isset($financials['revenue_growth']) ? (float) $financials['revenue_growth'] : null;

        if ($forwardEps !== null && $trailingEps !== null && $trailingEps > 0) {
            $epsFraction = ($forwardEps / $trailingEps) - 1.0;
            $baseEffect  = $epsFraction > 2.0;
            $epsRevGap   = $revGrowth !== null && $revGrowth > 0 && ($epsFraction / $revGrowth) > 3.5;
            if (!$baseEffect && !$epsRevGap) {
                return $epsFraction * 100.0;
            }
        }

        if ($revGrowth !== null && $revGrowth > 0) {
            return $revGrowth * 100.0;
        }

        $eqg = isset($financials['earnings_quarterly_growth'])
             ? (float) $financials['earnings_quarterly_growth']
             : null;
        if ($eqg !== null && $eqg > 0 && $eqg <= 2.0) {
            return $eqg * 100.0;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // FCF normalization (FR-011)
    // ------------------------------------------------------------------

    /**
     * Resolve the forward FCF estimate for use as EV/FCF denominator (FR-011).
     *
     * Reads config['valuation'] to apply the feature flag and bounds check.
     * Returns the estimate (float) when all conditions pass; null triggers
     * fallback to trailing_fcf × (1+g)² inside ValuationMetrics::forwardEvFcf().
     *
     * Bounds: ratio = free_cash_flow / trailing_eps must be within
     * [fcf_to_eps_ratio_min, fcf_to_eps_ratio_max]. Outside → fallback.
     *
     * @param array<string, mixed> $financials
     */
    private function resolveForwardFcfEst(array $financials): ?float
    {
        if (!($this->valuationConfig['use_forward_fcf_estimate'] ?? true)) {
            return null;
        }

        $fwdFcfEst   = $financials['forward_fcf_est']    ?? null;
        $trailingEps = isset($financials['trailing_eps']) ? (float) $financials['trailing_eps'] : null;
        $fcf         = isset($financials['free_cash_flow']) ? (float) $financials['free_cash_flow'] : null;
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
        // Note: plan text said "free_cash_flow / trailing_eps" but that is not dimensionless;
        // correct formula uses per-share FCF (free_cash_flow / shares_outstanding) — same intent.
        $fcfPerShare = $fcf / $shares;
        $ratio       = $fcfPerShare / $trailingEps;
        $ratioMin    = (float) ($this->valuationConfig['fcf_to_eps_ratio_min'] ?? 0.3);
        $ratioMax    = (float) ($this->valuationConfig['fcf_to_eps_ratio_max'] ?? 3.0);

        if ($ratio < $ratioMin || $ratio > $ratioMax) {
            return null;
        }

        return (float) $fwdFcfEst;
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Apply anchor_blend rule.
     * 'min'      → conservative: kotwica can only pull score DOWN.
     * 'weighted' → blend(sub, anchor) weighted by anchor_weight.
     */
    private function blend(float $subScore, float $anchorScore): float
    {
        if ($this->anchorBlend === 'weighted') {
            $score = (1.0 - $this->anchorWeight) * $subScore + $this->anchorWeight * $anchorScore;
        } else {
            // Default: 'min' — safe start (plan decision: tune on real data in Phase 3 manual verification)
            $score = min($subScore, $anchorScore);
        }

        return round(min(100.0, max(0.0, $score)), 2);
    }

    private function sigmoid(float $ratio): float
    {
        $score = 100.0 / (1.0 + exp(3.0 * ($ratio - 1.0)));
        return round(min(100.0, max(0.0, $score)), 2);
    }
}
