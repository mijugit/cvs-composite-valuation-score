<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

/**
 * Answers "does this company actually have comparable peers?" for a whole batch
 * of screener rows at once.
 *
 * MedianResolver falls back to the sector median when an industry bucket holds
 * fewer than min_sample_count tickers. That fallback is correct as a scoring
 * decision — some benchmark beats none — but it is invisible, and the resulting
 * score can be badly misleading: ASB.WA, the only electronics distributor among
 * 110 tickers (n=1), was measured against Technology's 24.4x EV/FCF, read as
 * "58% below sector", and ranked second overall with SILNE KUPUJ. Its real
 * industry median turned out to be 10.3x — the company was fairly valued all
 * along. Nothing in the product said the comparison was hollow; a person had to
 * notice it by eye.
 *
 * Pure and I/O-free: callers pass the already-fetched sample counts, so this is
 * unit-testable offline and costs one bulk query per page/run rather than one
 * per ticker.
 */
final class PeerCoverage
{
    /**
     * @param array<string, int> $industrySampleCounts industry => sample_count
     * @param int                $minSampleCount       config: peer_group.min_sample_count
     */
    public function __construct(
        private readonly array $industrySampleCounts,
        private readonly int   $minSampleCount,
    ) {}

    /**
     * True when this company's industry bucket is too thin to benchmark against,
     * so its valuation rests on a sector fallback rather than on real peers.
     *
     * An unknown or empty industry counts as thin: absence of a bucket is the
     * same situation as an under-populated one, and both end at the sector.
     */
    public function isThin(?string $industry): bool
    {
        if ($industry === null || $industry === '') {
            return true;
        }
        return ($this->industrySampleCounts[$industry] ?? 0) < $this->minSampleCount;
    }

    /** Peers behind this industry, for display. */
    public function sampleCount(?string $industry): int
    {
        if ($industry === null || $industry === '') {
            return 0;
        }
        return $this->industrySampleCounts[$industry] ?? 0;
    }

    public function minSampleCount(): int
    {
        return $this->minSampleCount;
    }
}
