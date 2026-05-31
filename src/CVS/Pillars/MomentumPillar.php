<?php

declare(strict_types=1);

namespace CVS\CVS\Pillars;

/**
 * Pillar — Price momentum vs. market benchmark (SPY).
 *
 * Score 0–100.
 *
 * S-05 update: roc_weights injected per mode (swing vs fundamental).
 *   Swing:       roc_weights = ['1m'=>0.50, '3m'=>0.30, '6m'=>0.20]
 *   Fundamental: roc_weights = ['3m'=>0.30, '6m'=>0.40, '12m'=>0.30]
 *
 * Available ROC periods:
 *   1M  → closes[$n-2]   (one month ago)
 *   3M  → closes[$n-4]   (three months ago)
 *   6M  → closes[$n-7]   (six months ago)
 *   12M → closes[$n-13]  (twelve months ago; fallback: 6M when history < 13 entries)
 *
 * SPY calibration = same composite on SPY monthly closes (default 15% if unavailable).
 * Excess return  = composite − spyCalib
 * normRatio      = 1 − (excess / momentum_divisor)
 * score          = sigmoid(normRatio), capped to [momentum_cap_min, momentum_cap_max]
 *
 * Interpretation:
 *  - Excess > 0  (outperforming SPY) → normRatio < 1 → score > 50 (bullish)
 *  - Excess < 0  (underperforming SPY) → normRatio > 1 → score < 50 (bearish)
 *  - Insufficient price history (< 7 months) → neutral 50
 *
 * Weight in CVS model: from config['modes'][mode]['momentum_weight'].
 */
class MomentumPillar
{
    private const SPY_FALLBACK_CALIB = 15.0; // % — used when SPY data unavailable

    /**
     * @param array<string, float|array<string,float>> $config  A mode config entry (swing or fundamental)
     *                                                           from config/cvs-weights.php → modes.
     *                                                           Reads: momentum_divisor, momentum_cap_min,
     *                                                           momentum_cap_max, sigmoid_k.
     */
    public function __construct(private readonly array $config = []) {}

    /**
     * @param array<string, mixed>  $financials  Normalised financials from FinancialDataFetcher
     * @param array<string, float>  $rocWeights  ROC weights keyed by period: '1m','3m','6m','12m'.
     *                                           Must sum to 1.0.  Read from config → modes → roc_weights.
     * @return float  Score in range [0, 100]
     */
    public function score(array $financials, array $rocWeights = []): float
    {
        // Backwards-compat: if no rocWeights given, use old default (swing-like 0.6×6M + 0.4×3M)
        if (empty($rocWeights)) {
            $rocWeights = ['6m' => 0.60, '3m' => 0.40];
        }

        /** @var float[] $closes */
        $closes = $financials['monthly_closes'] ?? [];
        $n      = count($closes);

        if ($n < 7) {
            return 50.0; // Insufficient price history — neutral.
        }

        $now = $closes[$n - 1];

        // --- Compute individual ROC values ---
        $m1  = $closes[max(0, $n - 2)];   // ~1 month ago
        $m3  = $closes[max(0, $n - 4)];   // ~3 months ago
        $m6  = $closes[max(0, $n - 7)];   // ~6 months ago
        $m12 = ($n >= 13) ? $closes[$n - 13] : $closes[max(0, $n - 7)]; // ~12M, fallback 6M

        $roc1m  = ($m1  > 0) ? ($now / $m1  - 1.0) * 100.0 : 0.0;
        $roc3m  = ($m3  > 0) ? ($now / $m3  - 1.0) * 100.0 : 0.0;
        $roc6m  = ($m6  > 0) ? ($now / $m6  - 1.0) * 100.0 : 0.0;
        $roc12m = ($m12 > 0) ? ($now / $m12 - 1.0) * 100.0 : $roc6m;

        if ($m6 <= 0) {
            return 50.0; // Must have at least 6M data for a meaningful signal.
        }

        // --- Weighted composite ---
        $rocMap    = ['1m' => $roc1m, '3m' => $roc3m, '6m' => $roc6m, '12m' => $roc12m];
        $composite = 0.0;
        foreach ($rocWeights as $period => $weight) {
            $composite += (float) $weight * ($rocMap[$period] ?? 0.0);
        }

        // --- SPY calibration (same roc_weights applied to SPY closes) ---
        /** @var float[] $spyCloses */
        $spyCloses = $financials['spy_closes'] ?? [];
        $sn        = count($spyCloses);
        $spyCalib  = self::SPY_FALLBACK_CALIB;

        if ($sn >= 7) {
            $sNow  = $spyCloses[$sn - 1];
            $s1m   = $spyCloses[max(0, $sn - 2)];
            $s3m   = $spyCloses[max(0, $sn - 4)];
            $s6m   = $spyCloses[max(0, $sn - 7)];
            $s12m  = ($sn >= 13) ? $spyCloses[$sn - 13] : $spyCloses[max(0, $sn - 7)];

            $spyRoc = [
                '1m'  => ($s1m  > 0) ? ($sNow / $s1m  - 1.0) * 100.0 : 0.0,
                '3m'  => ($s3m  > 0) ? ($sNow / $s3m  - 1.0) * 100.0 : 0.0,
                '6m'  => ($s6m  > 0) ? ($sNow / $s6m  - 1.0) * 100.0 : 0.0,
                '12m' => ($s12m > 0) ? ($sNow / $s12m - 1.0) * 100.0 : 0.0,
            ];

            if ($s6m > 0) {
                $spyCalib = 0.0;
                foreach ($rocWeights as $period => $weight) {
                    $spyCalib += (float) $weight * ($spyRoc[$period] ?? 0.0);
                }
            }
        }

        // --- Excess return → normalised ratio → sigmoid score ---
        $divisor   = (float) ($this->config['momentum_divisor'] ?? $this->config['normalization_divisor'] ?? 40.0);
        $minS      = (float) ($this->config['momentum_cap_min'] ?? $this->config['score_min']             ??  5.0);
        $maxS      = (float) ($this->config['momentum_cap_max'] ?? $this->config['score_max']             ?? 95.0);
        $kSigmoid  = (float) ($this->config['sigmoid_k']        ?? 3.0); // FR-010: never hardcode

        $excess    = $composite - $spyCalib;
        $normRatio = 1.0 - ($excess / $divisor);

        $raw   = 100.0 / (1.0 + exp($kSigmoid * ($normRatio - 1.0)));
        $score = max($minS, min($maxS, $raw));

        return round($score, 2);
    }
}
