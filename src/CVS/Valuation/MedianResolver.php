<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

/**
 * Resolves the appropriate peer-group median for a given company.
 *
 * Fallback chain (three tiers):
 *   1. Subsector (industry level, empirical) — when sample_count ≥ N
 *   2. Sector    (sector  level, empirical)  — fallback when subsector too thin
 *   3. Cold-start (static config benchmark)  — fallback when DB has no data yet
 *
 * This class is the ONLY place that decides which median the pillar uses.
 * ValuationPillar stays deterministic because the DB contains frozen values
 * computed by the offline batch — this class never mutates state.
 */
class MedianResolver
{
    /**
     * Every metric the Valuation pillar can land on, one per variant: A resolves
     * `ev_fcf`, B `ev_sales`, C `pb`. Callers asking "does this bucket have real
     * peers?" must weigh all three — a bucket is only empty when it is empty on
     * every metric its members could be scored against.
     *
     * @var list<string>
     */
    public const VALUATION_METRICS = ['ev_fcf', 'ev_sales', 'pb'];

    /**
     * @param PeerMedianRepository         $repo          Peer median store
     * @param array<string, array<string, float|int>> $benchmarks Static benchmarks (config['benchmarks'])
     * @param int                          $minSampleCount Threshold N below which subsector is too thin
     * @param string                       $modelVersion  e.g. '3.0'
     */
    public function __construct(
        private readonly PeerMedianRepository $repo,
        private readonly array $benchmarks,
        private readonly int   $minSampleCount,
        private readonly string $modelVersion,
    ) {}

    /**
     * Build a resolver straight from config/cvs-weights.php.
     *
     * The four constructor arguments have to agree everywhere or two callers
     * silently benchmark against different medians — which is exactly how fair
     * value ended up contradicting the Valuation pillar. Mirrors
     * ClaudeClientFactory::fromConfig()'s role for the AI client.
     *
     * @param array<string, mixed> $cvsConfig Full config/cvs-weights.php
     */
    public static function fromConfig(array $cvsConfig, ?PeerMedianRepository $repo = null): self
    {
        /** @var array<string, array<string, float|int>> $benchmarks */
        $benchmarks = $cvsConfig['benchmarks'] ?? [];
        $peerGroup  = is_array($cvsConfig['peer_group'] ?? null) ? $cvsConfig['peer_group'] : [];

        return new self(
            $repo ?? new PeerMedianRepository(),
            $benchmarks,
            (int) ($peerGroup['min_sample_count'] ?? 5),
            (string) ($cvsConfig['model_version'] ?? ''),
        );
    }

    // ------------------------------------------------------------------
    // Primary resolution (subsector → sector → cold-start)
    // ------------------------------------------------------------------

    /**
     * Resolve the best available median for a company.
     *
     * @param string $industry Yahoo Finance industry string (e.g. 'Electronic Gaming & Multimedia')
     * @param string $sector   Yahoo Finance sector string  (e.g. 'Communication Services')
     * @param string $metric   'ev_fcf' | 'ev_sales' | 'gm'
     */
    public function resolve(string $industry, string $sector, string $metric): MedianResolution
    {
        // Tier 1: subsector (empirical, if sample large enough)
        if ($industry !== '') {
            $row = $this->repo->findByBucket('industry', $industry, $this->modelVersion, $metric);
            if ($row !== null && $row['sample_count'] >= $this->minSampleCount && $row['median'] !== null) {
                return new MedianResolution(
                    (float) $row['median'],
                    'subsector',
                    $row['sample_count'],
                    $industry
                );
            }
        }

        // Tier 2: sector (empirical)
        $sectorRow = $this->repo->findByBucket('sector', $sector, $this->modelVersion, $metric);
        if ($sectorRow !== null && $sectorRow['median'] !== null) {
            return new MedianResolution(
                (float) $sectorRow['median'],
                'sector_fallback',
                $sectorRow['sample_count'],
                $sector
            );
        }

        // Tier 3: cold-start (static config benchmark)
        return $this->coldStart($sector, $metric);
    }

    // ------------------------------------------------------------------
    // Sector-level resolution only (used for anchor score)
    // ------------------------------------------------------------------

    /**
     * Resolve the sector-level median specifically (for kotwica absolutna).
     *
     * Always returns a sector or cold-start value — never the subsector.
     * This is used as the anchor to guard against an overvalued whole subsector.
     */
    public function resolveSector(string $sector, string $metric): MedianResolution
    {
        $row = $this->repo->findByBucket('sector', $sector, $this->modelVersion, $metric);
        if ($row !== null && $row['median'] !== null) {
            return new MedianResolution(
                (float) $row['median'],
                'sector_fallback',
                $row['sample_count'],
                $sector
            );
        }

        return $this->coldStart($sector, $metric);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function coldStart(string $sector, string $metric): MedianResolution
    {
        $bm = $this->benchmarks[$sector] ?? $this->benchmarks['DEFAULT'] ?? null;

        if ($bm !== null) {
            $value = match ($metric) {
                'ev_fcf'   => isset($bm['median_ev_fcf'])   ? (float) $bm['median_ev_fcf']   : null,
                'ev_sales' => isset($bm['median_ev_sales'])  ? (float) $bm['median_ev_sales']  : null,
                'gm'       => isset($bm['median_gm'])        ? (float) $bm['median_gm']        : null,
                // Price/book — the valuation metric for financials, where
                // EV/FCF is meaningless (a bank's "free cash flow" is not a
                // measure of anything an investor prices).
                'pb'       => isset($bm['median_pb'])        ? (float) $bm['median_pb']        : null,
                default    => null,
            };

            if ($value !== null && $value > 0) {
                return new MedianResolution($value, 'cold_start', 0, $sector);
            }
        }

        return new MedianResolution(null, 'missing', 0, $sector);
    }
}
