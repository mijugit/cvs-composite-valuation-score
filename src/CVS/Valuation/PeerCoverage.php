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
     * True when this company's valuation rests on a sector fallback rather than
     * on real industry peers.
     *
     * Prefers the resolution the Valuation pillar actually recorded at scoring
     * time (snapshot column `valuation_source`, migration 036). That is
     * authoritative and, crucially, per-metric correct: the pillar scores a
     * company on EV/FCF (variant A) or EV/Sales (variant B) and resolves the
     * matching bucket, so reading its verdict cannot mismatch the two. The
     * sample-count path below is a fallback for pre-migration rows only — it
     * inspects ev_fcf alone and therefore mislabels every variant-B company,
     * which is exactly the bug this parameter exists to retire.
     */
    public function isThin(?string $industry, ?string $valuationSource = null): bool
    {
        if ($valuationSource !== null && $valuationSource !== '') {
            // 'override' is an admin-defined peer group that actually resolved —
            // a deliberate comparison, not an absence of one. Treated exactly
            // like 'subsector'; if the override bucket had been too thin the
            // resolver would have reported the fallback instead.
            return !in_array($valuationSource, ['subsector', 'override'], true);
        }

        // Pre-migration row: fall back to the sample-count estimate. An unknown
        // or empty industry counts as thin — absence of a bucket lands at the
        // sector just as an under-populated one does.
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
