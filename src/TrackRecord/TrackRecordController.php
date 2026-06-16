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

    private const VALID_HORIZONS = [30, 60, 90];
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

        $horizon = $this->parseHorizon($req);

        $evaluations = $this->repo->getEvaluations($horizon, $this->liveModelVersion !== '' ? $this->liveModelVersion : null);
        $enriched    = TrackRecordCalculator::enrichWithResult($evaluations);
        $stats       = TrackRecordCalculator::summarise($enriched);

        // Group by ticker for the per-ticker summary table.
        $byTicker = [];
        foreach ($enriched as $row) {
            $ticker = (string) $row['ticker'];
            if (!isset($byTicker[$ticker])) {
                $byTicker[$ticker] = [];
            }
            $byTicker[$ticker][] = $row;
        }

        Response::view('track-record', [
            'evaluations' => $enriched,
            'byTicker'    => $byTicker,
            'stats'       => $stats,
            'horizon'     => $horizon,
            'horizons'    => self::VALID_HORIZONS,
        ]);
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
