<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

/**
 * Value object returned by MedianResolver::resolve().
 *
 * Carries the median value that ValuationPillar will use as its benchmark,
 * along with provenance information for transparency (FR-005).
 */
class MedianResolution
{
    /**
     * @param float|null $value       Resolved median; null when no usable value found
     * @param string     $source      'subsector' | 'sector_fallback' | 'cold_start' | 'missing'
     * @param int        $sampleCount Tickers that contributed to this median (0 for cold-start/missing)
     * @param string     $bucketKey   The bucket whose median was used (industry or sector name)
     */
    public function __construct(
        public readonly ?float  $value,
        public readonly string  $source,
        public readonly int     $sampleCount,
        public readonly string  $bucketKey,
    ) {}

    public function isValid(): bool
    {
        return $this->value !== null && $this->value > 0;
    }

    public function isSubsector(): bool
    {
        return $this->source === 'subsector';
    }
}
