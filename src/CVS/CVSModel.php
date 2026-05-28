<?php

declare(strict_types=1);

namespace CVS\CVS;

use CVS\CVS\Pillars\ValuationPillar;
use CVS\CVS\Pillars\MomentumPillar;
use CVS\CVS\Pillars\QualityPillar;

/**
 * CVS model orchestrator — S-05 dual-mode rebuild.
 *
 * Three pillars, two weight profiles (Swing + Fundamental), computed simultaneously.
 *
 * Architecture:
 *   Valuation  — SectorBenchmarkPillar (EV/FCF vs sector medians)
 *   Momentum   — MomentumPillar with mode-specific roc_weights
 *   Quality    — QualityPillar (GM, leverage, growth)
 *
 * The raw pillar scores are identical for both modes.
 * Only the MomentumPillar composite differs (different roc_weights per mode).
 * The final CVS differs by weighting.
 *
 * Determinism guarantee (PRD):
 *   Same $financials input → identical CVS scores and recommendations.
 *   No randomness, no date/time calls inside scoring logic.
 *
 * FR-010: all weights and thresholds are injected from config/cvs-weights.php.
 */
class CVSModel
{
    private QualityGate    $qualityGate;
    private ValuationPillar $valuation;
    private MomentumPillar  $momentum;

    /** @param array<string, mixed> $config  Full contents of config/cvs-weights.php */
    public function __construct(private readonly array $config)
    {
        $this->qualityGate = new QualityGate($config['quality_gate']);
        // Valuation pillar needs all benchmarks (resolves sector internally).
        $this->valuation   = new ValuationPillar($config['benchmarks'] ?? []);
        // Momentum pillar is constructed with swing config (cap/divisor are shared).
        $this->momentum    = new MomentumPillar($config['modes']['swing'] ?? []);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Calculate dual-mode CVS for a single ticker.
     *
     * @param string               $ticker      NYSE/NASDAQ symbol
     * @param array<string, mixed> $financials  Normalised data from FinancialDataFetcher
     * @return CVSResult
     */
    public function calculate(string $ticker, array $financials): CVSResult
    {
        // Step 1 — Quality Gate (binary filter).
        $gateResult = $this->qualityGate->evaluate($financials);

        if (!$gateResult->passed) {
            return CVSResult::failed($ticker, $gateResult->failures);
        }

        $sw = $this->config['modes']['swing'];
        $fn = $this->config['modes']['fundamental'];

        // Step 2 — Pillar raw scores (identical for both modes).
        $sector    = $financials['sector'] ?? 'DEFAULT';
        $bm        = $this->config['benchmarks'][$sector]
                  ?? $this->config['benchmarks']['DEFAULT']
                  ?? [];

        $valScore  = $this->valuation->score($financials);

        // Momentum is computed separately per mode using mode-specific roc_weights.
        $momSwing = $this->momentum->score($financials, $sw['roc_weights']);
        $momFund  = $this->momentum->score($financials, $fn['roc_weights']);

        $qualScore = (new QualityPillar($bm))->score($financials);

        // Step 3 — Weighted aggregate per mode.
        $swingCvs = round(
            $sw['valuation_weight'] * $valScore +
            $sw['momentum_weight']  * $momSwing +
            $sw['quality_weight']   * $qualScore,
            1
        );

        $fundCvs = round(
            $fn['valuation_weight'] * $valScore +
            $fn['momentum_weight']  * $momFund  +
            $fn['quality_weight']   * $qualScore,
            1
        );

        // Clamp to [0, 100].
        $swingCvs = (float) min(100.0, max(0.0, $swingCvs));
        $fundCvs  = (float) min(100.0, max(0.0, $fundCvs));

        // Step 4 — Map to recommendation labels.
        $swingReco = $this->mapToLabel((int) round($swingCvs));
        $fundReco  = $this->mapToLabel((int) round($fundCvs));

        return CVSResult::passed(
            ticker:                    $ticker,
            swingCvs:                  $swingCvs,
            fundamentalCvs:            $fundCvs,
            pillarScores:              [
                'valuation'      => $valScore,
                'momentum_swing' => $momSwing,
                'momentum_fund'  => $momFund,
                'quality'        => $qualScore,
            ],
            swingRecommendation:       $swingReco,
            fundamentalRecommendation: $fundReco,
            config:                    $this->config
        );
    }

    // ------------------------------------------------------------------
    // Label mapping
    // ------------------------------------------------------------------

    private function mapToLabel(int $cvs): string
    {
        $t = $this->config['thresholds'];

        return match (true) {
            $cvs >= $t['strong_buy'] => '⬆⬆ SILNE KUPUJ',
            $cvs >= $t['accumulate'] => '⬆ AKUMULUJ',
            $cvs >= $t['neutral']    => '→ NEUTRALNIE',
            $cvs >= $t['reduce']     => '⬇ REDUKUJ',
            default                  => '⬇⬇ UNIKAJ',
        };
    }
}
