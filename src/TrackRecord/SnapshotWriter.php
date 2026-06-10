<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\CVS\CVSResult;

/**
 * Shared snapshot persistence for every CVS scoring entrypoint (Phase 7, slice 1 — FR-002).
 *
 * Fans a single CVSResult out into one snapshot row per model version the result
 * carries: the base row (CVSResult::$modelVersion, e.g. 3.0) plus one shadow row
 * per non-empty overlay block (overlay.shadow_version, e.g. 3.1). Used by both
 * bin/rescore.php (origin = 'rescore', watchlist union) and the peer-median crawl
 * (origin = 'corpus', full universe) so the dual-write logic lives in exactly one
 * place — the version list is derived from the result itself, never duplicated in
 * config (challenger versions arrive in a later slice by extending CVSResult).
 *
 * Logic ported 1:1 from bin/rescore.php's former overlayShadowResultArray()
 * (Phase 5 slice 1, FR-016/FR-019): the shadow row differs from the base row only
 * in swing/fund scores and recommendations — quality gate, golden signal, gate
 * failures and pillar scores carry over unchanged, because the overlay is a
 * deterministic post-aggregation penalty, never a re-run of the gate or pillars.
 */
class SnapshotWriter
{
    private CvsSnapshotRepository $repo;

    public function __construct(?CvsSnapshotRepository $repo = null)
    {
        $this->repo = $repo ?? new CvsSnapshotRepository();
    }

    /**
     * Persist every versioned row the result carries. Returns the number of rows written.
     *
     * @param CVSResult   $result   Scored result (base + optional shadow overlay block)
     * @param float|null  $price    Current price at scoring time
     * @param string|null $sector   Yahoo Finance sector
     * @param string|null $industry Yahoo Finance industry / sub-sector
     * @param string      $origin   CvsSnapshotRepository::ORIGIN_RESCORE | ORIGIN_CORPUS
     */
    public function persist(
        CVSResult $result,
        ?float    $price,
        ?string   $sector,
        ?string   $industry,
        string    $origin
    ): int {
        $resultArray  = $result->toArray();
        $modelVersion = $result->modelVersion !== '' ? $result->modelVersion : null;

        $this->repo->save($result->ticker, $resultArray, $price, $sector, $industry, $modelVersion, $origin);
        $written = 1;

        $overlay = $result->overlay;
        if ($overlay !== null && $overlay['shadow_version'] !== '') {
            $this->repo->save(
                $result->ticker,
                $this->overlayShadowResultArray($overlay, $resultArray),
                $price,
                $sector,
                $industry,
                $overlay['shadow_version'],
                $origin
            );
            $written++;
        }

        return $written;
    }

    /**
     * Build a CVSResult::toArray()-shaped payload from the shadow overlay block,
     * suitable for persistence as a parallel snapshot row (model_version = shadow_version).
     *
     * @param array<string, mixed> $overlay CVSResult->overlay block (non-null — caller checks)
     * @param array<string, mixed> $base    CVSResult::toArray() of the base result
     * @return array<string, mixed>
     */
    private function overlayShadowResultArray(array $overlay, array $base): array
    {
        return [
            'ticker'        => $base['ticker']        ?? null,
            'quality_gate'  => $base['quality_gate']  ?? false,
            'swing'         => [
                'cvs'            => $overlay['swing']      ?? null,
                'recommendation' => $overlay['swing_reco'] ?? null,
            ],
            'fundamental'   => [
                'cvs'            => $overlay['fund']      ?? null,
                'recommendation' => $overlay['fund_reco'] ?? null,
            ],
            'golden_signal' => $base['golden_signal'] ?? null,
            'gate_failures' => $base['gate_failures'] ?? [],
            'pillar_scores' => $base['pillar_scores'] ?? null,
        ];
    }
}
