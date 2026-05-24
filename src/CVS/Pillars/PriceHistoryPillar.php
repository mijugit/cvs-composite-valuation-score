<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar (c) — Price history percentile.
 *
 * Score 0–100.
 *
 * Logic:
 *  - Computes where today's price sits within its own 52-week range as a
 *    simple percentile: 0% = 52-week low, 100% = 52-week high.
 *  - Inverts the percentile so that buying near the 52-week low scores
 *    HIGH (potential mean-reversion opportunity) and buying near the
 *    52-week high scores LOW.
 *  - Also incorporates the distance from the 200-day moving average
 *    (deviation from long-term trend) with a 30% weight.
 *
 * Weight in CVS model: 0.25 (configured in config/cvs-weights.php).
 */
class PriceHistoryPillar
{
    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        $scores = [];

        // --- Component 1: 52-week range percentile (inverted, weight 0.70) ---
        $price   = $financials['current_price']   ?? null;
        $low52   = $financials['fifty_two_week_low']  ?? null;
        $high52  = $financials['fifty_two_week_high'] ?? null;

        if ($price !== null && $low52 !== null && $high52 !== null && $high52 > $low52) {
            $percentile = ($price - $low52) / ($high52 - $low52); // 0 = low, 1 = high
            $scores[]   = ['value' => (1.0 - $percentile) * 100.0, 'weight' => 0.70];
        }

        // --- Component 2: Distance from 200-day MA (inverted, weight 0.30) ---
        $ma200 = $financials['moving_average_200'] ?? null;

        if ($price !== null && $ma200 !== null && $ma200 > 0) {
            $deviation = ($price - $ma200) / $ma200; // positive = above MA (expensive)
            // Map deviation ∈ (-∞, +∞) → [0, 100] via sigmoid (inverted).
            $k        = 5.0;
            $maScore  = 100.0 / (1.0 + exp($k * $deviation)); // invert: above MA → low score
            $scores[] = ['value' => $maScore, 'weight' => 0.30];
        }

        if (empty($scores)) {
            return 50.0;
        }

        // Weighted average.
        $totalWeight = array_sum(array_column($scores, 'weight'));
        $weighted    = 0.0;
        foreach ($scores as $s) {
            $weighted += $s['value'] * ($s['weight'] / $totalWeight);
        }

        return round(min(100.0, max(0.0, $weighted)), 2);
    }
}
