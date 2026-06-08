<?php

declare(strict_types=1);

namespace CVS\CVS;

use CVS\CVS\Pillars\ValuationPillar;
use CVS\CVS\Pillars\MomentumPillar;
use CVS\CVS\Pillars\QualityPillar;
use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\Core\Database;

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

    /**
     * @param array<string, mixed>     $config  Full contents of config/cvs-weights.php
     * @param PeerMedianRepository|null $repo   Injected for tests; production uses DB singleton
     */
    public function __construct(private readonly array $config, ?PeerMedianRepository $repo = null)
    {
        $this->qualityGate = new QualityGate($config['quality_gate']);

        // Build ValuationPillar with peer-group resolver when enabled (FR-010).
        $peerConfig = $config['peer_group'] ?? [];
        if (!empty($peerConfig['enabled'])) {
            $repository = $repo ?? new PeerMedianRepository();
            $resolver   = new MedianResolver(
                repo:           $repository,
                benchmarks:     $config['benchmarks'] ?? [],
                minSampleCount: (int) ($peerConfig['min_sample_count'] ?? 5),
                modelVersion:   (string) ($config['model_version'] ?? '3.0'),
            );
            $this->valuation = new ValuationPillar(
                benchmarks:   $config['benchmarks'] ?? [],
                resolver:     $resolver,
                anchorBlend:  (string) ($peerConfig['anchor_blend']  ?? 'min'),
                anchorWeight: (float)  ($peerConfig['anchor_weight'] ?? 0.3),
            );
        } else {
            // Legacy / peer_group disabled — static benchmarks only.
            $this->valuation = new ValuationPillar($config['benchmarks'] ?? []);
        }

        // Momentum pillar is constructed with swing config (cap/divisor are shared).
        $this->momentum = new MomentumPillar($config['modes']['swing'] ?? []);
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

        // Step 5 — Valuation reference (source + bucket used, for FR-005 transparency).
        $valuationReference = [
            'source'       => $this->valuation->lastSource(),
            'bucket'       => $this->valuation->lastBucketKey(),
        ];

        // Step 6 — Overlay penalties (Phase 5, slice 1): SHADOW model_version (3.1).
        // Deterministic post-aggregation penalties layered on top of the base (3.0)
        // scores. Computed in parallel and carried separately — base fields above
        // are entirely unaffected (guardrail FR-016: displayed reco stays at 3.0
        // until the recalibration slice).
        $overlay = $this->computeOverlay($valScore, $swingCvs, $fundCvs, $financials);

        // Step 7 — Earnings-timing badge (Phase 5, slice 2): always-present, additive
        // (FR-006/FR-007/FR-010). Independent of overlays/earnings_guard.enabled — the
        // badge must work for every user, those flags gate only the shadow penalty
        // inside computeOverlay() above (FR-017, see Critical Implementation Details).
        $earningsTiming = $this->computeEarningsTiming($financials);

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
            config:                    $this->config,
            modelVersion:              (string) ($this->config['model_version'] ?? ''),
            industry:                  is_string($financials['industry'] ?? null)
                                         ? (string) $financials['industry'] : null,
            valuationReference:        $valuationReference,
            overlay:                   $overlay,
            earningsTiming:            $earningsTiming,
        );
    }

    // ------------------------------------------------------------------
    // Earnings-timing badge (Phase 5, slice 2 — always present, additive)
    // ------------------------------------------------------------------

    /**
     * Build the always-present earnings-timing badge block, or null when the
     * ticker has no earnings-calendar coverage at all (`days_since_earnings`
     * AND `days_to_earnings` both missing). Pure function of the pre-computed,
     * fetch-time-injected day-counts + `earnings_guard.window_sessions` —
     * deterministic, no I/O, no `date()`/`time()` (FR-015).
     *
     * Deliberately independent of `earnings_guard.enabled` / `overlays.enabled` —
     * the badge (FR-010) must render for every user; those flags gate only the
     * shadow tempering penalty in computeOverlay() (FR-017, additive guardrail).
     *
     * @param array<string, mixed> $financials
     * @return array{days_since: ?int, days_to: ?int, state: ?string, guard_active: bool}|null
     */
    private function computeEarningsTiming(array $financials): ?array
    {
        $daysSince = is_int($financials['days_since_earnings'] ?? null) ? $financials['days_since_earnings'] : null;
        $daysTo    = is_int($financials['days_to_earnings']    ?? null) ? $financials['days_to_earnings']    : null;

        if ($daysSince === null && $daysTo === null) {
            return null;
        }

        $windowSessions = (int) ($this->config['earnings_guard']['window_sessions'] ?? 0);
        $state          = EarningsGuard::state($daysTo, $daysSince, $windowSessions);

        return [
            'days_since'   => $daysSince,
            'days_to'      => $daysTo,
            'state'        => $state,
            'guard_active' => $state !== null,
        ];
    }

    // ------------------------------------------------------------------
    // Overlay penalties (Phase 5, slice 1 — shadow model_version)
    // ------------------------------------------------------------------

    /**
     * Build the shadow overlay block (model_version 3.1), or null when overlays
     * are disabled. Pure function of the base scores + normalised financials —
     * deterministic, no I/O (FR-010).
     *
     * @param array<string, mixed> $financials
     * @return array{
     *   shadow_version: string,
     *   swing: float, fund: float,
     *   swing_reco: string, fund_reco: string,
     *   penalties: array{revision: float, target: float, earnings_guard: float, total: float},
     *   coverage: array{missing_eps_trend: bool, missing_target: bool, missing_earnings_calendar: bool}
     * }|null
     */
    private function computeOverlay(float $valScore, float $swingCvs, float $fundCvs, array $financials): ?array
    {
        $cfg = $this->config['overlays'] ?? [];

        if (empty($cfg['enabled'])) {
            return null;
        }

        $rev    = is_numeric($financials['eps_revision_pct']      ?? null) ? (float) $financials['eps_revision_pct']      : null;
        $upside = is_numeric($financials['analyst_target_upside'] ?? null) ? (float) $financials['analyst_target_upside'] : null;

        // Phase 5, slice 2: earnings-proximity guard penalty (additive, third
        // shadow-penalty input alongside revision/target — see EarningsGuard).
        // Gated by earnings_guard.enabled (shadow-only switch — the always-present
        // `earnings_timing` badge above is computed independently of this flag).
        $daysSince = is_int($financials['days_since_earnings'] ?? null) ? $financials['days_since_earnings'] : null;
        $daysTo    = is_int($financials['days_to_earnings']    ?? null) ? $financials['days_to_earnings']    : null;

        $revPenalty   = OverlayPenalties::revision($valScore, $rev, $cfg);
        $targetPenalty = OverlayPenalties::targetGate($upside, $cfg);
        $guardPenalty  = EarningsGuard::penalty($daysTo, $daysSince, $this->config['earnings_guard'] ?? []);
        $totalPenalty  = round($revPenalty + $targetPenalty + $guardPenalty, 1);

        $shadowSwing = (float) min(100.0, max(0.0, $swingCvs + $totalPenalty));
        $shadowFund  = (float) min(100.0, max(0.0, $fundCvs  + $totalPenalty));

        return [
            'shadow_version' => (string) ($cfg['shadow_version'] ?? ''),
            'swing'          => $shadowSwing,
            'fund'           => $shadowFund,
            'swing_reco'     => $this->mapToLabel((int) round($shadowSwing)),
            'fund_reco'      => $this->mapToLabel((int) round($shadowFund)),
            'penalties'      => [
                'revision'       => $revPenalty,
                'target'         => $targetPenalty,
                'earnings_guard' => $guardPenalty,
                'total'          => $totalPenalty,
            ],
            'coverage'       => [
                'missing_eps_trend'         => $rev    === null,
                'missing_target'            => $upside === null,
                'missing_earnings_calendar' => ($daysTo === null && $daysSince === null),
            ],
        ];
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
