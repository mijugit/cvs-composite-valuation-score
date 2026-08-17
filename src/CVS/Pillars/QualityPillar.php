<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar — Quality score (S-05 replacement for FundamentalQualityPillar).
 *
 * Score 0–100.
 *
 * Logic mirrors Python cvs_analyze.py calc_quality() exactly:
 *
 *  pts_gm (0–4):
 *    gm_delta = gross_margins * 100 − bm['median_gm']
 *    ≥ 15 → 4 | ≥ 5 → 3 | ≥ −5 → 2 | ≥ −15 → 1 | else → 0
 *
 *  pts_leverage (0–3):
 *    if ebitda > 0:
 *      ratio = max(0, total_debt − cash) / ebitda
 *      ≤ 1 → 3 | ≤ 2.5 → 2 | ≤ 4 → 1 | else → 0
 *    else (cash-burning company):
 *      cr = cash / revenue  (revenue > 0)
 *      ≥ 0.30 → 2 | ≥ 0.10 → 1 | else → 0
 *
 *  pts_growth (0–3):
 *    forward_growth from extractForwardGrowth() — same helper as ValuationPillar
 *    > 10 % → 3 | > 0 % → 1.5 | else → 0
 *
 *  score_raw = pts_gm + pts_leverage + pts_growth   (0–10)
 *  score     = (score_raw / 10.0) * 100             (0–100)
 */
class QualityPillar
{
    private float $lastRawScore = 0.0;
    /** @var array<string, mixed> */
    private array $lastSteps = [];

    /**
     * @param array<string, int|float> $benchmark  Single sector entry from benchmarks (needs median_gm)
     * @param array<string, mixed>     $config      Optional extra config (unused but kept for symmetry)
     */
    public function __construct(
        private readonly array $benchmark,
        private readonly array $config = [],
        /** @var array<string, mixed> config['real_estate'] — leverage bands for REITs */
        private readonly array $realEstateConfig = []
    ) {}

    /**
     * Net-debt/EBITDA bands, in points, for the sector at hand.
     *
     * Real estate gets its own. A REIT financing property at 5-6x net debt to
     * EBITDA is ordinary and well run; the general scale calls anything over 4x
     * distressed, so every REIT in this universe scored zero of three leverage
     * points whatever its balance sheet looked like. A term that is constant
     * across a sector carries no information about companies within it.
     *
     * @return array{good: float, fair: float, stretched: float}
     */
    private function leverageBands(string $sector): array
    {
        $sectors = is_array($this->realEstateConfig['sectors'] ?? null)
            ? $this->realEstateConfig['sectors']
            : [];

        if ($sectors !== [] && in_array($sector, $sectors, true)) {
            $bands = is_array($this->realEstateConfig['leverage'] ?? null)
                ? $this->realEstateConfig['leverage']
                : [];
            return [
                'good'      => (float) ($bands['good']      ?? 5.0),
                'fair'      => (float) ($bands['fair']      ?? 6.5),
                'stretched' => (float) ($bands['stretched'] ?? 8.0),
            ];
        }

        return ['good' => 1.0, 'fair' => 2.5, 'stretched' => 4.0];
    }

    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        $steps = [];

        // Financials take a different route entirely. Gross margin, net debt to
        // EBITDA and forward revenue growth are not what makes a bank good or
        // bad: deposits and debt are its raw material, and Yahoo reports its
        // gross profit as 0. The sector is judged on returns instead — ROE and
        // ROA — with a payout sanity check, on the same 0-10 scale so the
        // aggregate weighting upstream is untouched.
        $sectors = is_array($this->config['sectors'] ?? null) ? $this->config['sectors'] : [];
        $sector  = is_string($financials['sector'] ?? null) ? (string) $financials['sector'] : '';
        if ($sectors !== [] && in_array($sector, $sectors, true)) {
            return $this->scoreFinancial($financials);
        }

        // ------------------------------------------------------------------
        // 1. Gross margin vs sector median
        // ------------------------------------------------------------------
        $grossMargins = isset($financials['gross_margins'])
            ? (float) $financials['gross_margins']
            : null;
        $medianGm     = isset($this->benchmark['median_gm'])
            ? (float) $this->benchmark['median_gm']
            : null;

        $ptsGm = 0.0;
        if ($grossMargins !== null && $medianGm !== null) {
            $gmDelta = $grossMargins * 100.0 - $medianGm;
            if ($gmDelta >= 15.0)       { $ptsGm = 4.0; }
            elseif ($gmDelta >= 5.0)    { $ptsGm = 3.0; }
            elseif ($gmDelta >= -5.0)   { $ptsGm = 2.0; }
            elseif ($gmDelta >= -15.0)  { $ptsGm = 1.0; }
            else                        { $ptsGm = 0.0; }
            $steps['gm_delta'] = round($gmDelta, 2);
        } else {
            // Missing GM data — treat as sector-neutral (2 pts out of 4)
            $ptsGm = 2.0;
            $steps['gm_delta'] = null;
        }
        $steps['pts_gm'] = $ptsGm;

        // ------------------------------------------------------------------
        // 2. Leverage quality (net debt / EBITDA or cash runway fallback)
        // ------------------------------------------------------------------
        $totalDebt = (float) ($financials['total_debt'] ?? 0.0);
        $cash      = (float) ($financials['cash']       ?? 0.0);
        $ebitda    = isset($financials['ebitda']) ? (float) $financials['ebitda'] : null;
        $revenue   = isset($financials['revenue']) ? (float) $financials['revenue'] : null;

        $ptsLeverage = 0.0;
        if ($ebitda !== null && $ebitda > 0.0) {
            $bands = $this->leverageBands($sector);
            $ratio = max(0.0, $totalDebt - $cash) / $ebitda;
            if ($ratio <= $bands['good'])           { $ptsLeverage = 3.0; }
            elseif ($ratio <= $bands['fair'])       { $ptsLeverage = 2.0; }
            elseif ($ratio <= $bands['stretched'])  { $ptsLeverage = 1.0; }
            else                                    { $ptsLeverage = 0.0; }
            $steps['net_debt_ebitda'] = round($ratio, 2);
            $steps['leverage_bands']  = $bands;
        } elseif ($revenue !== null && $revenue > 0.0) {
            // Cash-burning company — use cash / revenue as runway proxy
            $cr = $cash / $revenue;
            if ($cr >= 0.30)     { $ptsLeverage = 2.0; }
            elseif ($cr >= 0.10) { $ptsLeverage = 1.0; }
            else                 { $ptsLeverage = 0.0; }
            $steps['cash_runway'] = round($cr, 3);
        } else {
            // No EBITDA and no revenue — conservative 0
            $ptsLeverage = 0.0;
        }
        $steps['pts_leverage'] = $ptsLeverage;

        // ------------------------------------------------------------------
        // 3. Forward growth quality
        // ------------------------------------------------------------------
        $forwardGrowth = $this->extractForwardGrowth($financials);

        $ptsGrowth = 0.0;
        if ($forwardGrowth !== null) {
            if ($forwardGrowth > 10.0)      { $ptsGrowth = 3.0; }
            elseif ($forwardGrowth > 0.0)   { $ptsGrowth = 1.5; }
            else                            { $ptsGrowth = 0.0; }
        }
        $steps['forward_growth_pct'] = $forwardGrowth !== null ? round($forwardGrowth, 2) : null;
        $steps['pts_growth']         = $ptsGrowth;

        // ------------------------------------------------------------------
        // 4. Aggregate
        // ------------------------------------------------------------------
        $raw   = $ptsGm + $ptsLeverage + $ptsGrowth;   // 0–10
        $score = ($raw / 10.0) * 100.0;                  // 0–100

        $steps['score_raw'] = round($raw, 2);

        $this->lastRawScore = $raw;
        $this->lastSteps    = $steps;

        return round(min(100.0, max(0.0, $score)), 2);
    }

    /**
     * Quality for financials: returns, not margins (0-10 scale, same as the
     * ordinary path so the pillar weighting upstream needs no special case).
     *
     *   ROE   — how hard the bank works shareholders' capital (0-4 pts)
     *   ROA   — how well it earns on the whole loan/investment book (0-4 pts)
     *   payout— a payout above the configured ceiling is a caution, not a
     *           virtue: it can mean nothing is left for growth or losses (0-2 pts)
     *
     * Missing inputs score 0 for their component rather than sinking the whole
     * pillar, matching how the ordinary path treats an absent metric.
     *
     * @param array<string, mixed> $financials
     */
    private function scoreFinancial(array $financials): float
    {
        $cfg   = is_array($this->config['quality'] ?? null) ? $this->config['quality'] : [];
        $steps = ['variant' => 'financial'];

        $roeGood   = (float) ($cfg['roe_good']   ?? 0.12);
        $roeStrong = (float) ($cfg['roe_strong'] ?? 0.18);
        $roaGood   = (float) ($cfg['roa_good']   ?? 0.010);
        $roaStrong = (float) ($cfg['roa_strong'] ?? 0.015);
        $payoutMax = (float) ($cfg['payout_max'] ?? 0.80);

        // Unknown ROE/ROA score the neutral middle (half of the 4-point max),
        // mirroring the payout convention below: absence of the field is not
        // evidence of a weak bank. A MEASURED loss or sub-zero return (roe/roa
        // <= 0) is still scored 0 — that is real information, not a gap. Yahoo
        // omits returnOnEquity for a measurable slice of Financial Services
        // tickers (e.g. XTB.WA) even when it is recoverable from P/B ÷ P/E —
        // FinancialDataFetcher fills that gap upstream (ProfitabilityMetrics),
        // so a null reaching here means neither Yahoo nor derivation had it.
        $roe = isset($financials['return_on_equity']) ? (float) $financials['return_on_equity'] : null;
        $ptsRoe = 2.0;
        if ($roe !== null) {
            if ($roe >= $roeStrong)    { $ptsRoe = 4.0; }
            elseif ($roe >= $roeGood)  { $ptsRoe = 2.5; }
            elseif ($roe > 0.0)        { $ptsRoe = 1.0; }
            else                       { $ptsRoe = 0.0; }
        }
        $steps['roe']     = $roe !== null ? round($roe, 4) : null;
        $steps['pts_roe'] = $ptsRoe;

        $roa = isset($financials['return_on_assets']) ? (float) $financials['return_on_assets'] : null;
        $ptsRoa = 2.0;
        if ($roa !== null) {
            if ($roa >= $roaStrong)    { $ptsRoa = 4.0; }
            elseif ($roa >= $roaGood)  { $ptsRoa = 2.5; }
            elseif ($roa > 0.0)        { $ptsRoa = 1.0; }
            else                       { $ptsRoa = 0.0; }
        }
        $steps['roa']     = $roa !== null ? round($roa, 4) : null;
        $steps['pts_roa'] = $ptsRoa;

        $payout = isset($financials['payout_ratio']) ? (float) $financials['payout_ratio'] : null;
        // Unknown payout scores the neutral middle rather than zero: absence of
        // the field is not evidence of an overstretched dividend.
        $ptsPayout = 1.0;
        if ($payout !== null) {
            $ptsPayout = ($payout > 0.0 && $payout <= $payoutMax) ? 2.0
                : (($payout <= 0.0) ? 1.0 : 0.0);
        }
        $steps['payout_ratio'] = $payout !== null ? round($payout, 3) : null;
        $steps['pts_payout']   = $ptsPayout;

        $raw   = $ptsRoe + $ptsRoa + $ptsPayout;  // 0-10
        $score = ($raw / 10.0) * 100.0;

        $steps['score_raw'] = round($raw, 2);

        $this->lastRawScore = $raw;
        $this->lastSteps    = $steps;

        return round(min(100.0, max(0.0, $score)), 2);
    }

    /**
     * Raw score before 0–100 normalisation (0–10 scale).
     */
    public function rawScore(): float
    {
        return $this->lastRawScore;
    }

    /**
     * Step-by-step breakdown of the last score() call.
     *
     * @return array<string, mixed>
     */
    public function steps(): array
    {
        return $this->lastSteps;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Derive forward annual growth rate (%) from available financials.
     *
     * Mirrors SectorBenchmarkPillar::extractForwardGrowth() exactly.
     *
     * Priority order (mirrors Python v1.6 calc_relative):
     *   a. EPS-based: forwardEps / trailingEps − 1
     *      — skipped if > 2.0 (base effect) or > 3.5× revenue_growth (EPS/revenue gap)
     *   b. revenue_growth × 100  (when > 0)
     *   c. earnings_quarterly_growth × 100  (when 0 < value ≤ 2.0)
     *   d. null → pts_growth = 0
     *
     * @param array<string, mixed> $financials
     * @return float|null  Growth rate in % (e.g. 16.6), or null if unavailable
     */
    private function extractForwardGrowth(array $financials): ?float
    {
        $forwardEps  = isset($financials['forward_eps'])  ? (float) $financials['forward_eps']  : null;
        $trailingEps = isset($financials['trailing_eps']) ? (float) $financials['trailing_eps'] : null;
        $revGrowth   = isset($financials['revenue_growth']) ? (float) $financials['revenue_growth'] : null;

        // a. EPS-based forward growth.
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

        // b. Revenue growth.
        if ($revGrowth !== null && $revGrowth > 0) {
            return $revGrowth * 100.0;
        }

        // c. Earnings quarterly growth.
        $eqg = isset($financials['earnings_quarterly_growth'])
             ? (float) $financials['earnings_quarterly_growth']
             : null;

        if ($eqg !== null && $eqg > 0 && $eqg <= 2.0) {
            return $eqg * 100.0;
        }

        return null;
    }
}
