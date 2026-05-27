<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar (c) — Price momentum vs. market benchmark (SPY).
 *
 * Score 0–100.
 *
 * Logic mirrors Python cvs_analyze.py calc_momentum() (v1.6):
 *  - Composite ROC = 0.6 × ROC_6M + 0.4 × ROC_3M  (price return, %)
 *  - SPY calibration = same composite on SPY monthly closes  (default 15% if unavailable)
 *  - Excess return  = composite − spyCalib
 *  - normRatio      = 1 − (excess / normalizationDivisor)
 *  - score          = sigmoid(normRatio), capped to [score_min, score_max]
 *
 * Interpretation:
 *  - Excess > 0  (outperforming SPY) → normRatio < 1 → score > 50 (bullish)
 *  - Excess < 0  (underperforming SPY) → normRatio > 1 → score < 50 (bearish)
 *  - Insufficient price history (< 7 months) → neutral 50
 *
 * Weight in CVS model: 0.25 (configured in config/cvs-weights.php).
 */
class MomentumPillar
{
    private const SPY_FALLBACK_CALIB = 15.0; // % — used when SPY data unavailable

    /**
     * @param array<string, float> $config  The 'momentum' section from cvs-weights.php:
     *                                      normalization_divisor, score_min, score_max
     */
    public function __construct(private readonly array $config = []) {}

    /**
     * @param array<string, mixed> $financials  Normalised financials from FinancialDataFetcher
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials): float
    {
        /** @var float[] $closes */
        $closes = $financials['monthly_closes'] ?? [];
        $n      = count($closes);

        if ($n < 7) {
            return 50.0; // Insufficient price history — neutral.
        }

        // --- Composite ROC for the ticker ---
        $now  = $closes[$n - 1];
        $m6   = $closes[max(0, $n - 7)];  // price 6 months ago
        $m3   = $closes[max(0, $n - 4)];  // price 3 months ago

        if ($m6 <= 0 || $m3 <= 0) {
            return 50.0;
        }

        $roc6m     = ($now / $m6 - 1.0) * 100.0;
        $roc3m     = ($now / $m3 - 1.0) * 100.0;
        $composite = 0.6 * $roc6m + 0.4 * $roc3m;

        // --- SPY calibration ---
        /** @var float[] $spyCloses */
        $spyCloses = $financials['spy_closes'] ?? [];
        $sn        = count($spyCloses);
        $spyCalib  = self::SPY_FALLBACK_CALIB;

        if ($sn >= 7) {
            $sNow = $spyCloses[$sn - 1];
            $s6m  = $spyCloses[max(0, $sn - 7)];
            $s3m  = $spyCloses[max(0, $sn - 4)];

            if ($s6m > 0 && $s3m > 0) {
                $spyCalib = 0.6 * ($sNow / $s6m - 1.0) * 100.0
                          + 0.4 * ($sNow / $s3m - 1.0) * 100.0;
            }
        }

        // --- Excess return → normalised ratio → sigmoid score ---
        $divisor   = (float) ($this->config['normalization_divisor'] ?? 40.0);
        $excess    = $composite - $spyCalib;
        $normRatio = 1.0 - ($excess / $divisor);

        $raw    = 100.0 / (1.0 + exp(3.0 * ($normRatio - 1.0)));
        $minS   = (float) ($this->config['score_min'] ?? 5.0);
        $maxS   = (float) ($this->config['score_max'] ?? 95.0);
        $score  = max($minS, min($maxS, $raw));

        return round($score, 2);
    }
}
