<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Value object returned by CVSModel::calculate().
 *
 * S-05 dual-mode: carries Swing CVS and Fundamental CVS simultaneously,
 * plus a golden signal indicating the best setup type.
 *
 * Backward compatibility: cvs() method returns swingCvs.
 */
class CVSResult
{
    public readonly bool    $qualityGatePassed;
    public readonly string  $ticker;
    /** @var string[]  Non-empty when gate failed */
    public readonly array   $gateFailures;

    // Dual scores (null when quality gate failed)
    public readonly ?float  $swingCvs;
    public readonly ?float  $fundamentalCvs;
    public readonly ?string $swingRecommendation;
    public readonly ?string $fundamentalRecommendation;
    /** 'strong' | 'watchlist' | 'momentum' | null */
    public readonly ?string $goldenSignal;
    /** @var array<string, float>  valuation, momentum_swing, momentum_fund, quality */
    public readonly array   $pillarScores;

    // Phase 3: model versioning + valuation transparency
    public readonly string  $modelVersion;
    public readonly ?string $industry;
    /** @var array{source: string, bucket: string} */
    public readonly array   $valuationReference;

    // Phase 5 (slice 1): shadow overlay block (model_version shadow_version).
    // null when overlays disabled or quality gate failed. Additive — base fields above are unchanged.
    /**
     * @var array{
     *   shadow_version: string,
     *   swing: float, fund: float,
     *   swing_reco: string, fund_reco: string,
     *   penalties: array{revision: float, target: float, earnings_guard: float, total: float},
     *   coverage: array{missing_eps_trend: bool, missing_target: bool, missing_earnings_calendar: bool}
     * }|null
     */
    public readonly ?array  $overlay;

    // Phase 7 (slice 2): list of all shadow blocks (3.1 + 3.2 today, future
    // challengers without another contract change). `overlay` above remains
    // an alias for backward compatibility (UI chip, SnapshotWriter, tests) —
    // it resolves to the block whose shadow_version matches
    // config['overlays']['shadow_version'] (3.1), or the first block.
    /** @var array<int, array<string, mixed>> */
    public readonly array $shadows;

    // Phase 5 (slice 2): always-present earnings-timing badge (FR-006/FR-007/FR-010).
    // Separate, sibling field to `overlay` — NOT nested inside it — so the badge
    // works for every user regardless of `overlays.enabled`/`earnings_guard.enabled`
    // (those flags gate only the shadow-mode tempering penalty). null when the
    // ticker has no earnings-calendar coverage at all, or quality gate failed.
    /**
     * @var array{days_since: ?int, days_to: ?int, state: ?string, guard_active: bool}|null
     */
    public readonly ?array  $earningsTiming;

    /**
     * @param bool                 $qualityGatePassed
     * @param string               $ticker
     * @param string[]             $gateFailures
     * @param float|null           $swingCvs
     * @param float|null           $fundamentalCvs
     * @param string|null          $swingRecommendation
     * @param string|null          $fundamentalRecommendation
     * @param string|null          $goldenSignal
     * @param array<string, float> $pillarScores
     */
    private function __construct(
        bool    $qualityGatePassed,
        string  $ticker,
        array   $gateFailures,
        ?float  $swingCvs,
        ?float  $fundamentalCvs,
        ?string $swingRecommendation,
        ?string $fundamentalRecommendation,
        ?string $goldenSignal,
        array   $pillarScores,
        string  $modelVersion        = '',
        ?string $industry            = null,
        array   $valuationReference  = ['source' => 'cold_start', 'bucket' => ''],
        ?array  $overlay             = null,
        array   $shadows             = [],
        ?array  $earningsTiming      = null,
    ) {
        $this->qualityGatePassed         = $qualityGatePassed;
        $this->ticker                    = $ticker;
        $this->gateFailures              = $gateFailures;
        $this->swingCvs                  = $swingCvs;
        $this->fundamentalCvs            = $fundamentalCvs;
        $this->swingRecommendation       = $swingRecommendation;
        $this->fundamentalRecommendation = $fundamentalRecommendation;
        $this->goldenSignal              = $goldenSignal;
        $this->pillarScores              = $pillarScores;
        $this->modelVersion              = $modelVersion;
        $this->industry                  = $industry;
        $this->valuationReference        = $valuationReference;
        $this->overlay                   = $overlay;
        $this->shadows                   = $shadows;
        $this->earningsTiming            = $earningsTiming;
    }

    // ------------------------------------------------------------------
    // Named constructors
    // ------------------------------------------------------------------

    /**
     * Company passed the Quality Gate — dual CVS was calculated.
     *
     * @param array<string, float> $pillarScores  valuation, momentum_swing, momentum_fund, quality
     * @param array<string, mixed> $config         Full cvs-weights.php config (for threshold reading)
     */
    public static function passed(
        string  $ticker,
        float   $swingCvs,
        float   $fundamentalCvs,
        array   $pillarScores,
        string  $swingRecommendation,
        string  $fundamentalRecommendation,
        array   $config              = [],
        string  $modelVersion        = '',
        ?string $industry            = null,
        array   $valuationReference  = ['source' => 'cold_start', 'bucket' => ''],
        ?array  $overlay             = null,
        array   $shadows             = [],
        ?array  $earningsTiming      = null,
    ): self {
        $goldenSignal = self::computeGoldenSignal($swingCvs, $fundamentalCvs, $config);

        // Backward compatibility (F5): callers passing a single legacy `overlay`
        // block without `shadows` get it wrapped into a one-element list.
        if ($shadows === [] && $overlay !== null) {
            $shadows = [$overlay];
        }

        $resolvedOverlay = $overlay ?? self::deriveOverlay($shadows, $config);

        return new self(
            qualityGatePassed:         true,
            ticker:                    $ticker,
            gateFailures:              [],
            swingCvs:                  $swingCvs,
            fundamentalCvs:            $fundamentalCvs,
            swingRecommendation:       $swingRecommendation,
            fundamentalRecommendation: $fundamentalRecommendation,
            goldenSignal:              $goldenSignal,
            pillarScores:              $pillarScores,
            modelVersion:              $modelVersion,
            industry:                  $industry,
            valuationReference:        $valuationReference,
            overlay:                   $resolvedOverlay,
            shadows:                   $shadows,
            earningsTiming:            $earningsTiming,
        );
    }

    /**
     * Resolve the legacy `overlay` alias from the `shadows` list: prefer the
     * block whose `shadow_version` matches `config['overlays']['shadow_version']`
     * (3.1), falling back to the first block. null when `$shadows` is empty.
     *
     * @param array<int, array<string, mixed>> $shadows
     * @param array<string, mixed>             $config
     */
    private static function deriveOverlay(array $shadows, array $config): ?array
    {
        $targetVersion = (string) ($config['overlays']['shadow_version'] ?? '');

        if ($targetVersion !== '') {
            foreach ($shadows as $shadow) {
                if (is_array($shadow) && ($shadow['shadow_version'] ?? null) === $targetVersion) {
                    return $shadow;
                }
            }
        }

        return $shadows[0] ?? null;
    }

    /**
     * Company failed the Quality Gate — no CVS score.
     *
     * @param string[] $failures
     */
    /**
     * @param string $modelVersion MUST be passed by scoring callers. A gate
     *        rejection is still a versioned observation about a ticker-day:
     *        SnapshotWriter maps an empty version to a NULL `model_version`
     *        column, and in MySQL a NULL never equals another NULL in a UNIQUE
     *        index — so version-less rows both bypass uq_ticker_day_version
     *        (accumulating a fresh duplicate on every run) and poison the
     *        version-agnostic `MAX(score_date)` sub-query in
     *        ScreenerRepository::findAllLatest(), hiding the ticker's last
     *        good snapshot entirely. Observed live on MU, 2026-08-13/14.
     */
    public static function failed(string $ticker, array $failures, string $modelVersion = ''): self
    {
        return new self(
            qualityGatePassed:         false,
            ticker:                    $ticker,
            gateFailures:              $failures,
            swingCvs:                  null,
            fundamentalCvs:            null,
            swingRecommendation:       null,
            fundamentalRecommendation: null,
            goldenSignal:              null,
            pillarScores:              [],
            modelVersion:              $modelVersion
        );
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    /**
     * Backward-compatible CVS accessor — returns swing CVS.
     * Use swingCvs / fundamentalCvs for new code.
     */
    public function cvs(): ?float
    {
        return $this->swingCvs;
    }

    /**
     * Backward-compatible recommendation accessor — returns swing recommendation.
     */
    public function recommendation(): ?string
    {
        return $this->swingRecommendation;
    }

    // ------------------------------------------------------------------
    // Serialisation
    // ------------------------------------------------------------------

    /**
     * Serialise to an array for JSON responses or template rendering.
     *
     * New structure (S-05):
     *   swing.cvs, swing.recommendation
     *   fundamental.cvs, fundamental.recommendation
     *   golden_signal, pillar_scores, disclaimer
     */
    public function toArray(): array
    {
        return [
            'ticker'       => $this->ticker,
            'quality_gate' => $this->qualityGatePassed,
            'gate_failures'=> $this->gateFailures,
            'swing'        => [
                'cvs'            => $this->swingCvs,
                'recommendation' => $this->swingRecommendation,
            ],
            'fundamental'  => [
                'cvs'            => $this->fundamentalCvs,
                'recommendation' => $this->fundamentalRecommendation,
            ],
            'golden_signal'       => $this->goldenSignal,
            'pillar_scores'       => $this->pillarScores,
            'model_version'       => $this->modelVersion,
            'industry'            => $this->industry,
            'valuation_reference' => $this->valuationReference,
            'overlay'             => $this->overlay,
            'shadows'             => $this->shadows,
            'earnings_timing'     => $this->earningsTiming,
            // Legal disclaimer — must accompany every CVS result (PRD FR-009).
            'disclaimer'          => 'Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.',
        ];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Determine the golden signal based on swing and fundamental CVS thresholds.
     *
     * | Swing | Fund  | Signal     | Label                        |
     * |-------|-------|------------|------------------------------|
     * | ≥ thr | ≥ thr | strong     | ⭐⭐ Silny sygnał            |
     * | < thr | ≥ thr | watchlist  | ⭐  Setup — czekaj na momentum |
     * | ≥ thr | < thr | momentum   | Momentum — nie value         |
     * | < thr | < thr | null       | brak                         |
     *
     * Threshold read from config['thresholds']['accumulate'] (default 58).
     *
     * @param array<string, mixed> $config
     */
    private static function computeGoldenSignal(float $swing, float $fund, array $config): ?string
    {
        $thr = (float) ($config['thresholds']['accumulate'] ?? 58.0);

        if ($swing >= $thr && $fund >= $thr) {
            return 'strong';
        }
        if ($fund >= $thr && $swing < $thr) {
            return 'watchlist';
        }
        if ($swing >= $thr && $fund < $thr) {
            return 'momentum';
        }

        return null;
    }
}
