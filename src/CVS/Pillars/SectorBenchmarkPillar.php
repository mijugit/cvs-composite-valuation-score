<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar (b) — Sector benchmark comparison.
 *
 * Score 0–100.
 *
 * Logic:
 *  - Compares the company's P/E, P/S, and EV/EBITDA against the sector median
 *    provided in the financial data payload.
 *  - Lower-than-median multiple → relatively cheap → higher score.
 *  - Each ratio is scored independently and averaged with equal weight.
 *  - Missing ratios are excluded from the average (not penalised).
 *
 * Weight in CVS model: 0.25 (configured in config/cvs-weights.php).
 *
 * Note: This pillar computes how cheap a stock is **relative to peers**,
 * not in absolute terms — consistent with the CVS philosophical assumption
 * "valuation is relative" (see shape-notes.md § Assumptions).
 */
class SectorBenchmarkPillar
{
    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        $scores = [];

        $ratios = [
            ['company' => 'pe_ratio',       'sector' => 'sector_pe_median'],
            ['company' => 'ps_ratio',       'sector' => 'sector_ps_median'],
            ['company' => 'ev_ebitda',      'sector' => 'sector_ev_ebitda_median'],
        ];

        foreach ($ratios as $pair) {
            $company = $financials[$pair['company']] ?? null;
            $median  = $financials[$pair['sector']]  ?? null;

            if ($company === null || $median === null || $median <= 0) {
                continue; // Data absent — skip ratio.
            }

            // Discount relative to sector: positive = cheaper than peers.
            $relativeDiscount = ($median - $company) / $median;

            // Map to [0, 100] via sigmoid.  k=3: ±100% discount/premium → ≈ 95/5.
            $k        = 3.0;
            $scores[] = 100.0 / (1.0 + exp(-$k * $relativeDiscount));
        }

        if (empty($scores)) {
            return 50.0; // No sector data available — neutral.
        }

        $avg = array_sum($scores) / count($scores);
        return round(min(100.0, max(0.0, $avg)), 2);
    }
}
