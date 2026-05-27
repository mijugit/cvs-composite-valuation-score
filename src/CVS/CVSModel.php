<?php

declare(strict_types=1);

namespace CVS\CVS;

use CVS\CVS\Pillars\GrowthPillar;
use CVS\CVS\Pillars\SectorBenchmarkPillar;
use CVS\CVS\Pillars\MomentumPillar;
use CVS\CVS\Pillars\FundamentalQualityPillar;

/**
 * CVS model orchestrator.
 *
 * Applies the Quality Gate, then aggregates the four pillar scores
 * into a single CVS score (0–100) and maps it to a recommendation label.
 *
 * Determinism guarantee (from PRD):
 *   Given the same financials input, the same CVS score and recommendation
 *   will always be produced — no randomness, no date-sensitive branching.
 *
 * Configuration is injected via config/cvs-weights.php so weights and
 * thresholds can be changed without touching this file (FR-010).
 */
class CVSModel
{
    private QualityGate              $qualityGate;
    private GrowthPillar             $growth;
    private SectorBenchmarkPillar    $sector;
    private MomentumPillar           $momentum;
    private FundamentalQualityPillar $quality;

    /** @param array<string, mixed> $config  Full contents of config/cvs-weights.php */
    public function __construct(private readonly array $config)
    {
        $this->qualityGate = new QualityGate($config['quality_gate']);
        $this->growth      = new GrowthPillar();
        $this->sector      = new SectorBenchmarkPillar($config['benchmarks'] ?? []);
        $this->momentum    = new MomentumPillar($config['momentum']   ?? []);
        $this->quality     = new FundamentalQualityPillar();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Calculate CVS for a single ticker.
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

        // Step 2 — Pillar scores (each 0–100).
        $w = $this->config['weights'];

        $pillarScores = [
            'growth'   => $this->growth->score($financials),
            'sector'   => $this->sector->score($financials),
            'momentum' => $this->momentum->score($financials),
            'quality'  => $this->quality->score($financials),
        ];

        // Step 3 — Weighted aggregate.
        $cvs = (
            $pillarScores['growth']   * $w['growth']   +
            $pillarScores['sector']   * $w['sector']   +
            $pillarScores['momentum'] * $w['momentum'] +
            $pillarScores['quality']  * $w['quality']
        );

        $cvs = round(min(100.0, max(0.0, $cvs)), 2);

        // Step 4 — Map to recommendation label.
        $recommendation = $this->mapToLabel((int) round($cvs));

        return CVSResult::passed(
            ticker:         $ticker,
            cvs:            $cvs,
            pillarScores:   $pillarScores,
            recommendation: $recommendation
        );
    }

    // ------------------------------------------------------------------
    // Label mapping
    // ------------------------------------------------------------------

    private function mapToLabel(int $cvs): string
    {
        $t = $this->config['thresholds'];

        return match(true) {
            $cvs >= $t['strong_buy'] => '⬆⬆ SILNE KUPUJ',
            $cvs >= $t['accumulate'] => '⬆ AKUMULUJ',
            $cvs >= $t['neutral']    => '→ NEUTRALNIE',
            $cvs >= $t['reduce']     => '⬇ REDUKUJ',
            default                  => '⬇⬇ UNIKAJ',
        };
    }
}
