<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\CVS\CVSModel;

/**
 * Corpus snapshot policy for the peer-median crawl (Phase 7, slice 1 — FR-001).
 *
 * Scores an already-fetched $financials payload and persists the result as a
 * calibration-corpus snapshot (origin = 'corpus', base + shadow versions via
 * SnapshotWriter). Pure given its dependencies — fully testable offline.
 *
 * Gate-failed results are SKIPPED, not persisted (plan-review F1): a row with
 * no score carries zero calibration value (it can never enter a CVS bucket),
 * and CVSResult::failed() does not stamp modelVersion — a NULL model_version
 * inside the 4-column UNIQUE would break same-day idempotency (MySQL treats
 * NULLs as distinct, so re-runs would INSERT duplicates instead of updating).
 * Gate-failure coverage stays in the crawl log, not in the database.
 */
class CorpusScorer
{
    public function __construct(
        private readonly CVSModel $model,
        private readonly SnapshotWriter $writer,
    ) {
    }

    /**
     * Score one ticker from its fetched financials and persist corpus row(s).
     *
     * @param array<string, mixed> $financials Normalised FinancialDataFetcher payload
     * @return int Number of snapshot rows written (0 when the quality gate failed)
     */
    public function scoreAndPersist(string $ticker, array $financials): int
    {
        $result = $this->model->calculate($ticker, $financials);

        if (!$result->qualityGatePassed) {
            return 0;
        }

        $price    = isset($financials['current_price']) ? (float)  $financials['current_price'] : null;
        $sector   = isset($financials['sector'])        ? (string) $financials['sector']        : null;
        $industry = isset($financials['industry'])      ? (string) $financials['industry']      : null;

        return $this->writer->persist(
            $result,
            $price,
            $sector,
            $industry,
            CvsSnapshotRepository::ORIGIN_CORPUS
        );
    }
}
