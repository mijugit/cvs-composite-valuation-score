<?php

declare(strict_types=1);

namespace CVS\CVS\Valuation;

/**
 * Every peer bucket an admin may assign a ticker to, with how many companies
 * back it. Shared by the /admin/tickers form and the screener's right-click
 * "Zmień sektor" modal (change: cvs-screener-sector-quickpick) — both need
 * the exact same union-of-buckets list, so it lives here once rather than
 * being rebuilt per caller.
 */
final class PeerGroupOptions
{
    /**
     * Union of the buckets that actually exist: Yahoo industries seen in the
     * snapshot population, plus any custom group already in use. Sample
     * counts come from peer_medians so the operator can see, at the moment of
     * choosing, which buckets clear min_sample_count and which will fall back
     * to the sector regardless.
     *
     * @return array<int, array{key: string, count: int, custom: bool}>
     */
    public static function build(
        string $modelVersion,
        PeerMedianRepository $medianRepo,
        PeerBucketOverrideRepository $overrideRepo
    ): array {
        $counts = $medianRepo->findIndustrySampleCounts($modelVersion, MedianResolver::VALUATION_METRICS);

        $custom = [];
        foreach ($overrideRepo->findAll() as $o) {
            $custom[(string) $o['bucket_key']] = true;
        }

        $keys = array_unique(array_merge(array_keys($counts), array_keys($custom)));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        $out = [];
        foreach ($keys as $k) {
            $out[] = [
                'key'    => (string) $k,
                'count'  => (int) ($counts[$k] ?? 0),
                'custom' => isset($custom[$k]) && !isset($counts[$k]),
            ];
        }
        return $out;
    }
}
