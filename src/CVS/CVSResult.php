<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Value object returned by CVSModel::calculate().
 *
 * Carries both the final CVS score and the intermediate pillar scores
 * so the UI can render a breakdown chart.
 */
readonly class CVSResult
{
    /**
     * @param bool          $qualityGatePassed
     * @param string        $ticker
     * @param float|null    $cvs              null when quality gate failed
     * @param array<string, float> $pillarScores  empty when gate failed
     * @param string|null   $recommendation   null when gate failed
     * @param string[]      $gateFailures     non-empty when gate failed
     */
    private function __construct(
        public bool    $qualityGatePassed,
        public string  $ticker,
        public ?float  $cvs,
        public array   $pillarScores,
        public ?string $recommendation,
        public array   $gateFailures
    ) {}

    // ------------------------------------------------------------------
    // Named constructors
    // ------------------------------------------------------------------

    /**
     * Company passed the Quality Gate — CVS was calculated.
     *
     * @param array<string, float> $pillarScores
     */
    public static function passed(
        string $ticker,
        float  $cvs,
        array  $pillarScores,
        string $recommendation
    ): self {
        return new self(
            qualityGatePassed: true,
            ticker:            $ticker,
            cvs:               $cvs,
            pillarScores:      $pillarScores,
            recommendation:    $recommendation,
            gateFailures:      []
        );
    }

    /**
     * Company failed the Quality Gate — no CVS score.
     *
     * @param string[] $failures
     */
    public static function failed(string $ticker, array $failures): self
    {
        return new self(
            qualityGatePassed: false,
            ticker:            $ticker,
            cvs:               null,
            pillarScores:      [],
            recommendation:    null,
            gateFailures:      $failures
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Serialise to an array for JSON responses or template rendering. */
    public function toArray(): array
    {
        return [
            'ticker'             => $this->ticker,
            'quality_gate'       => $this->qualityGatePassed,
            'gate_failures'      => $this->gateFailures,
            'cvs'                => $this->cvs,
            'pillar_scores'      => $this->pillarScores,
            'recommendation'     => $this->recommendation,
            // Legal disclaimer — must accompany every CVS result (PRD FR-009).
            'disclaimer'         => 'Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.',
        ];
    }
}
