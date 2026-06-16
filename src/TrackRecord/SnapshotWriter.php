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
     * @param CVSResult   $result      Scored result (base + optional shadow overlay block)
     * @param float|null  $price       Current price at scoring time
     * @param string|null $sector      Yahoo Finance sector
     * @param string|null $industry    Yahoo Finance industry / sub-sector
     * @param string      $origin      CvsSnapshotRepository::ORIGIN_RESCORE | ORIGIN_CORPUS
     * @param string|null $companyName Yahoo Finance long name (FinancialDataFetcher 'long_name'),
     *                                  for watchlist tooltip (migration 018)
     */
    public function persist(
        CVSResult $result,
        ?float    $price,
        ?string   $sector,
        ?string   $industry,
        string    $origin,
        ?string   $companyName    = null,
        ?float    $fxRateToUsd   = null,
        ?string   $nativeCurrency = null,
        ?float    $nativePrice    = null
    ): int {
        $resultArray  = $result->toArray();
        $modelVersion = $result->modelVersion !== '' ? $result->modelVersion : null;

        // Phase 7 (slice 2): raw predictive-signal inputs (FR-022) — shared across
        // base/3.1/3.2 rows for the same ticker-day, sourced from the 3.2 shadow's
        // `signals` block (minus its `adjustments`, which are 3.2-specific).
        $rawSignals = $this->rawSignals($result->shadows);
        if ($rawSignals !== null) {
            $resultArray['signals'] = $rawSignals;
        }

        $this->repo->save($result->ticker, $resultArray, $price, $sector, $industry, $modelVersion, $origin, $companyName, $fxRateToUsd, $nativeCurrency, $nativePrice);
        $written = 1;

        // Fan out over the full shadows[] list (3.1 + 3.2 today) instead of the
        // single legacy `overlay` alias.
        foreach ($result->shadows as $shadow) {
            if (($shadow['shadow_version'] ?? '') === '') {
                continue;
            }

            $this->repo->save(
                $result->ticker,
                $this->shadowResultArray($shadow, $resultArray),
                $price,
                $sector,
                $industry,
                (string) $shadow['shadow_version'],
                $origin,
                $companyName,
                $fxRateToUsd,
                $nativeCurrency,
                $nativePrice
            );
            $written++;
        }

        return $written;
    }

    /**
     * Extract the raw predictive-signal inputs from the 3.2 shadow block (if
     * present), dropping its `adjustments` sub-block (3.2-specific corrections).
     * Returns null when no shadow carries a `signals` block (3.2 disabled).
     *
     * @param array<int, array<string, mixed>> $shadows CVSResult->shadows[]
     * @return array<string, mixed>|null
     */
    private function rawSignals(array $shadows): ?array
    {
        foreach ($shadows as $shadow) {
            if (isset($shadow['signals']) && is_array($shadow['signals'])) {
                $signals = $shadow['signals'];
                unset($signals['adjustments']);
                return $signals;
            }
        }

        return null;
    }

    /**
     * Build a CVSResult::toArray()-shaped payload from a shadow block,
     * suitable for persistence as a parallel snapshot row (model_version = shadow_version).
     *
     * @param array<string, mixed> $shadow CVSResult->shadows[] block
     * @param array<string, mixed> $base   CVSResult::toArray() of the base result
     * @return array<string, mixed>
     */
    private function shadowResultArray(array $shadow, array $base): array
    {
        return [
            'ticker'        => $base['ticker']        ?? null,
            'quality_gate'  => $base['quality_gate']  ?? false,
            'swing'         => [
                'cvs'            => $shadow['swing']      ?? null,
                'recommendation' => $shadow['swing_reco'] ?? null,
            ],
            'fundamental'   => [
                'cvs'            => $shadow['fund']      ?? null,
                'recommendation' => $shadow['fund_reco'] ?? null,
            ],
            'golden_signal' => $base['golden_signal'] ?? null,
            'gate_failures' => $base['gate_failures'] ?? [],
            'pillar_scores' => $base['pillar_scores'] ?? null,
            'signals'       => $base['signals'] ?? null,
        ];
    }
}
