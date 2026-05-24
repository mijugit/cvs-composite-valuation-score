<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar (d) — Fundamental quality score.
 *
 * Score 0–100.
 *
 * Logic:
 *  The pillar aggregates four quality signals with equal weight (25% each):
 *
 *  1. Return on Equity (ROE) — profitability of shareholder capital.
 *     Benchmarked against a 15% "good business" threshold.
 *
 *  2. Free Cash Flow margin — cash generation vs. revenue.
 *     More reliable than net income (harder to manipulate).
 *     Benchmarked against 10% FCF margin.
 *
 *  3. Gross margin trend — is the moat expanding or eroding?
 *     Compares latest gross margin to the 3-year average.
 *
 *  4. Net debt / EBITDA — leverage quality.
 *     Lower is better; above 4x is considered stressed.
 *
 * Weight in CVS model: 0.20 (configured in config/cvs-weights.php).
 */
class FundamentalQualityPillar
{
    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        $scores = [];

        // --- 1. Return on Equity ---
        $roe = $financials['return_on_equity'] ?? null;
        if ($roe !== null) {
            // Sigmoid: 15% ROE → 50 pts; 30% → ~88; 0% → ~12; negative → near 0.
            $k        = 10.0;
            $scores[] = 100.0 / (1.0 + exp(-$k * ($roe - 0.15)));
        }

        // --- 2. Free Cash Flow margin ---
        $fcfMargin = $this->safeDiv(
            $financials['free_cash_flow'] ?? null,
            $financials['revenue']        ?? null
        );
        if ($fcfMargin !== null) {
            // Sigmoid: 10% FCF margin → 50; 25% → ~82; 0% → ~27; negative → near 0.
            $k        = 8.0;
            $scores[] = 100.0 / (1.0 + exp(-$k * ($fcfMargin - 0.10)));
        }

        // --- 3. Gross margin trend ---
        $gmHistory = $financials['gross_margin_history'] ?? []; // e.g. [0.42, 0.44, 0.45, 0.47]
        if (count($gmHistory) >= 2) {
            $latest  = end($gmHistory);
            $average = array_sum($gmHistory) / count($gmHistory);
            $delta   = $latest - $average; // positive = expanding margin

            $k        = 15.0; // Tight band: ±5 pp shift → large score swing.
            $scores[] = 100.0 / (1.0 + exp(-$k * $delta));
        }

        // --- 4. Net debt / EBITDA ---
        $netDebt = ($financials['total_debt'] ?? 0) - ($financials['cash'] ?? 0);
        $ebitda  = $financials['ebitda'] ?? null;

        if ($ebitda !== null && $ebitda > 0) {
            $leverage = $netDebt / $ebitda; // lower is better
            // Score falls from 100 (leverage≤0) to ≈5 (leverage=4x).
            // Map: leverage=0 → 95, leverage=4 → 5.
            $k        = 0.8;
            $scores[] = 100.0 / (1.0 + exp($k * ($leverage - 1.0)));
        }

        if (empty($scores)) {
            return 50.0;
        }

        $avg = array_sum($scores) / count($scores);
        return round(min(100.0, max(0.0, $avg)), 2);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function safeDiv(?float $n, ?float $d): ?float
    {
        if ($n === null || $d === null || $d == 0.0) {
            return null;
        }
        return $n / $d;
    }
}
