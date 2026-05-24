<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar (a) — Growth rate vs the company's own historical trajectory.
 *
 * Score 0–100.
 *
 * Logic:
 *  - Uses revenue growth as the primary signal (more manipulation-resistant
 *    than EPS growth, which was a key CVS design decision over PEG).
 *  - Compares the most recent TTM growth rate against the company's own
 *    3-year CAGR to judge acceleration / deceleration.
 *  - Applies a sigmoid-style normalisation so outlier growers don't compress
 *    the rest of the distribution.
 *
 * Weight in CVS model: 0.30 (configured in config/cvs-weights.php).
 */
class GrowthPillar
{
    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        $revenueHistory = $financials['revenue_history'] ?? []; // chronological array of annual revenues

        if (count($revenueHistory) < 2) {
            // Insufficient history — return neutral 50.
            return 50.0;
        }

        // Most recent YoY growth (TTM vs prior year).
        $latest  = end($revenueHistory);
        $prior   = prev($revenueHistory);

        if (!$prior || $prior <= 0) {
            return 50.0;
        }

        $yoyGrowth = ($latest - $prior) / $prior; // e.g. 0.15 = 15%

        // 3-year CAGR (if enough data).
        $cagr = $this->cagr($revenueHistory);

        // Relative growth: how much faster/slower is the company growing
        // compared to its own baseline trajectory?
        // Positive delta → acceleration (good); negative → deceleration.
        $delta = $cagr !== null ? ($yoyGrowth - $cagr) : $yoyGrowth;

        // Sigmoid normalisation: maps delta ∈ (-∞, +∞) → (0, 100).
        // Midpoint at delta=0 (growing in line with own history → 50).
        // Scale factor k=10 means ±30% relative deviation → ≈ score 95 / 5.
        $k     = 10.0;
        $score = 100.0 / (1.0 + exp(-$k * $delta));

        return round(min(100.0, max(0.0, $score)), 2);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param float[] $revenues  Chronological (oldest first) */
    private function cagr(array $revenues): ?float
    {
        $n = count($revenues);
        if ($n < 4) {
            return null; // Need at least 4 data points (= 3 full years).
        }

        $oldest  = $revenues[array_key_first($revenues)];
        $newest  = $revenues[array_key_last($revenues)];
        $periods = $n - 1;

        if ($oldest <= 0) {
            return null;
        }

        return (($newest / $oldest) ** (1.0 / $periods)) - 1.0;
    }
}
