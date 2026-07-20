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
                benchmarks:      $config['benchmarks'] ?? [],
                resolver:        $resolver,
                anchorBlend:     (string) ($peerConfig['anchor_blend']  ?? 'min'),
                anchorWeight:    (float)  ($peerConfig['anchor_weight'] ?? 0.3),
                valuationConfig: $config['valuation'] ?? [],
            );
        } else {
            // Legacy / peer_group disabled — static benchmarks only.
            $this->valuation = new ValuationPillar(
                benchmarks:      $config['benchmarks'] ?? [],
                valuationConfig: $config['valuation'] ?? [],
            );
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
        // 'value'/'variant' added for the sector-history-modal overlay (analysis.php's
        // clickable valuation badge) — the company's own EV/FCF (variant A) or
        // EV/Sales (variant B) multiple, plotted as a dashed reference line against
        // the peer-group's historical median. Both keys come from the same
        // steps() snapshot that produced source/bucket above, so they're always
        // consistent with each other (see ValuationPillar::scoreVariantA/B).
        $valuationSteps     = $this->valuation->steps();
        $valuationReference = [
            'source'       => $this->valuation->lastSource(),
            'bucket'       => $this->valuation->lastBucketKey(),
            'value'        => $valuationSteps['ev_fcf'] ?? $valuationSteps['ev_sales_adj'] ?? null,
            'variant'      => $valuationSteps['variant'] ?? null,
        ];

        // Step 6 — Overlay penalties (Phase 5, slice 1): SHADOW model_version (3.1).
        // Deterministic post-aggregation penalties layered on top of the base (3.0)
        // scores. Computed in parallel and carried separately — base fields above
        // are entirely unaffected (guardrail FR-016: displayed reco stays at 3.0
        // until the recalibration slice).
        $overlay = $this->computeOverlay($valScore, $swingCvs, $fundCvs, $financials);

        // Step 6b — Predictive-signals shadow (Phase 7, slice 2): SHADOW model_version
        // (3.2). Reuses the 3.1 revision/target penalties (anti-drift — never
        // recomputed), replaces the symmetric earnings_guard penalty with a
        // directional PEAD guard, and layers symmetric breadth/52w/consistency
        // signals on top. Hierarchical kill-switch (plan-review F3): null when
        // signals_32 is disabled OR the 3.1 shadow itself is null.
        $shadow32 = $this->computeShadow32($valScore, $swingCvs, $fundCvs, $financials, $overlay);

        $shadows = array_values(array_filter([$overlay, $shadow32]));

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
            shadows:                   $shadows,
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
    // Predictive signals (Phase 7, slice 2 — shadow model_version 3.2)
    // ------------------------------------------------------------------

    /**
     * Build the predictive-signals shadow block (model_version 3.2), or null
     * when `signals_32.enabled` is false OR the 3.1 shadow (`$overlay31`) is
     * null (plan-review F3 — hierarchical kill-switch: 3.2 rides on the 3.1
     * revision/target penalties, so `overlays.enabled = false` disables the
     * whole shadow stack).
     *
     * Reuses `$overlay31['penalties']['revision']` and `['target']` unchanged
     * (anti-drift — never recomputed). Replaces `$overlay31['penalties']['earnings_guard']`
     * with `ShadowSignals::peadGuard()`, then layers breadth / 52w-proximity /
     * consistency on top.
     *
     * @param array<string, mixed> $financials
     * @param array{penalties: array{revision: float, target: float, earnings_guard: float, total: float}}|null $overlay31
     * @return array{
     *   shadow_version: string,
     *   swing: float, fund: float,
     *   swing_reco: string, fund_reco: string,
     *   penalties: array{revision: float, target: float, earnings_guard: float, total: float},
     *   signals: array{
     *     surprise_pct: ?float, breadth: ?float, high_52w_proximity: ?float, beat_count_4q: ?int,
     *     adjustments: array{pead_guard: float, breadth: float, high_52w: float, consistency: float, total: float}
     *   },
     *   coverage: array{missing_eps_trend: bool, missing_target: bool, missing_earnings_calendar: bool, missing_surprise: bool, missing_breadth: bool, missing_52w: bool, missing_consistency: bool}
     * }|null
     */
    private function computeShadow32(float $valScore, float $swingCvs, float $fundCvs, array $financials, ?array $overlay31): ?array
    {
        $cfg = $this->config['signals_32'] ?? [];

        if (empty($cfg['enabled']) || $overlay31 === null) {
            return null;
        }

        $surprisePct  = is_numeric($financials['eps_surprise_pct']     ?? null) ? (float) $financials['eps_surprise_pct']     : null;
        $breadth      = is_numeric($financials['eps_revision_breadth'] ?? null) ? (float) $financials['eps_revision_breadth'] : null;
        $beatCount    = is_int($financials['eps_beat_count_4q'] ?? null) ? $financials['eps_beat_count_4q'] : null;
        $price        = is_numeric($financials['current_price']        ?? null) ? (float) $financials['current_price']        : null;
        $high52w      = is_numeric($financials['fifty_two_week_high']  ?? null) ? (float) $financials['fifty_two_week_high']  : null;

        $daysSince = is_int($financials['days_since_earnings'] ?? null) ? $financials['days_since_earnings'] : null;
        $daysTo    = is_int($financials['days_to_earnings']    ?? null) ? $financials['days_to_earnings']    : null;
        $windowSessions = (int) ($this->config['earnings_guard']['window_sessions'] ?? 0);
        $earningsState  = EarningsGuard::state($daysTo, $daysSince, $windowSessions);

        $revPenalty    = $overlay31['penalties']['revision'];
        $targetPenalty = $overlay31['penalties']['target'];

        $peadCfg = ($cfg['pead'] ?? []) + ['cap' => (float) ($this->config['earnings_guard']['penalty']['cap'] ?? 0.0)];
        $peadPenalty = ShadowSignals::peadGuard($earningsState, $surprisePct, $overlay31['penalties']['earnings_guard'], $peadCfg);

        $breadthAdj    = ShadowSignals::breadth($breadth, $cfg['breadth'] ?? []);
        $high52wProximity = ($price !== null && $high52w !== null && $high52w > 0.0) ? $price / $high52w : null;
        $high52wAdj    = ShadowSignals::high52w($price, $high52w, $cfg['high_52w'] ?? []);
        $consistencyAdj = ShadowSignals::consistency($beatCount, $cfg['consistency'] ?? []);

        $totalPenalty = round($revPenalty + $targetPenalty + $peadPenalty + $breadthAdj + $high52wAdj + $consistencyAdj, 1);

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
                'earnings_guard' => $peadPenalty,
                'total'          => $totalPenalty,
            ],
            'signals' => [
                'surprise_pct'       => $surprisePct,
                'breadth'            => $breadth,
                'high_52w_proximity' => $high52wProximity,
                'beat_count_4q'      => $beatCount,
                'adjustments' => [
                    'pead_guard'  => $peadPenalty,
                    'breadth'     => $breadthAdj,
                    'high_52w'    => $high52wAdj,
                    'consistency' => $consistencyAdj,
                    'total'       => $totalPenalty,
                ],
            ],
            'coverage' => [
                'missing_eps_trend'         => $overlay31['coverage']['missing_eps_trend'],
                'missing_target'            => $overlay31['coverage']['missing_target'],
                'missing_earnings_calendar' => $overlay31['coverage']['missing_earnings_calendar'],
                'missing_surprise'          => $surprisePct === null,
                'missing_breadth'           => $breadth === null,
                'missing_52w'               => $high52wProximity === null,
                'missing_consistency'       => $beatCount === null,
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
