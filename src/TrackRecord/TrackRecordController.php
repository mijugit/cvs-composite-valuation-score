<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\Auth\AuthController;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Track record views — /track-record and /track-record/{ticker}.
 */
class TrackRecordController
{
    private TrackRecordRepository $repo;
    private string $liveModelVersion;

    private const VALID_HORIZONS = [7, 15, 30, 60, 90];
    private const DEFAULT_HORIZON = 30;

    public function __construct()
    {
        $this->repo = new TrackRecordRepository();
        $config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->liveModelVersion = (string) ($config['model_version'] ?? '');
    }

    // ------------------------------------------------------------------
    // GET /track-record
    // ------------------------------------------------------------------

    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $horizon      = $this->parseHorizon($req);
        $liveVersion  = $this->liveModelVersion !== '' ? $this->liveModelVersion : null;

        $evaluations = $this->repo->getEvaluations($horizon, $liveVersion);
        $enriched    = TrackRecordCalculator::enrichWithResult($evaluations);
        $stats       = TrackRecordCalculator::summarise($enriched);

        // Comparison band for the per-ticker delta (accordion) — reuses the same
        // query at double the horizon, no new SQL. See
        // TrackRecordCalculator::deltaHitRatePct() for why this isolates a clean
        // "recently matured vs established" comparison.
        $olderEvaluations = $this->repo->getEvaluations($horizon * 2, $liveVersion);
        $olderEnriched    = TrackRecordCalculator::enrichWithResult($olderEvaluations);

        $byTicker      = $this->groupByTicker($enriched);
        $olderByTicker = $this->groupByTicker($olderEnriched);

        // One row per ticker for the accordion: summary stats + delta + the full
        // evaluation list (rendered collapsed, expanded on click).
        $tickerSummaries = [];
        foreach ($byTicker as $ticker => $rows) {
            $summary            = TrackRecordCalculator::summarise($rows);
            $summary['delta']   = TrackRecordCalculator::deltaHitRatePct($rows, $olderByTicker[$ticker] ?? []);
            $summary['rows']    = $rows;
            $tickerSummaries[$ticker] = $summary;
        }

        // Honest empty-state: tracking effectively starts when the live model_version
        // began being written (not at the first-ever snapshot — older versions/currency
        // basis differ and are excluded from evaluation).
        $trackingStart = $this->repo->getEarliestLiveSnapshotDate($liveVersion);

        Response::view('track-record', [
            'evaluations'      => $enriched,
            'tickerSummaries'  => $tickerSummaries,
            'stats'            => $stats,
            'horizon'          => $horizon,
            'horizons'         => self::VALID_HORIZONS,
            'trackingStart'    => $trackingStart,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $enriched
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByTicker(array $enriched): array
    {
        $byTicker = [];
        foreach ($enriched as $row) {
            $ticker = (string) $row['ticker'];
            if (!isset($byTicker[$ticker])) {
                $byTicker[$ticker] = [];
            }
            $byTicker[$ticker][] = $row;
        }
        return $byTicker;
    }

    // ------------------------------------------------------------------
    // GET /track-record/{ticker}
    // ------------------------------------------------------------------

    public function show(Request $req): void
    {
        AuthController::requireAuth();

        $ticker  = strtoupper(trim((string) $req->param('ticker', '')));
        $horizon = $this->parseHorizon($req);

        $evaluations = $this->repo->getForTicker($ticker, $horizon, $this->liveModelVersion !== '' ? $this->liveModelVersion : null);
        $enriched    = TrackRecordCalculator::enrichWithResult($evaluations);
        $stats       = TrackRecordCalculator::summarise($enriched);
        $all         = $this->repo->getAllForTicker($ticker); // for CVS chart

        Response::view('track-record-ticker', [
            'ticker'      => $ticker,
            'evaluations' => $enriched,
            'all'         => $all,
            'stats'       => $stats,
            'horizon'     => $horizon,
            'horizons'    => self::VALID_HORIZONS,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function parseHorizon(Request $req): int
    {
        $raw = (int) ($req->query('days') ?: self::DEFAULT_HORIZON);
        return in_array($raw, self::VALID_HORIZONS, true) ? $raw : self::DEFAULT_HORIZON;
    }
}
