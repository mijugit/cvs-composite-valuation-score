<?php

declare(strict_types=1);

namespace CVS\Screener;

use CVS\Auth\AuthController;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Handles GET /screener — CVS ranking with filters.
 */
class ScreenerController
{
    private ScreenerRepository $repo;

    private const VALID_SORTS = ['swing', 'fund', 'date', 'ticker', 'price', 'atr'];
    private const VALID_ATR   = ['in_zone', 'above', 'below'];

    public function __construct()
    {
        // Hotfix (2026-06-08): inject the live model_version so the repository
        // can filter out cvs-overlay-penalties shadow rows (3.1) — see
        // ScreenerRepository::$liveModelVersion / findAllLatest().
        $config      = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $liveVersion = $config['model_version'] ?? null;
        $this->repo  = new ScreenerRepository(null, $liveVersion !== null ? (string) $liveVersion : null);
    }

    public function index(Request $req): void
    {
        AuthController::requireAuth();

        // Parse and sanitise filter params.
        $reco     = $req->query('reco')   !== null ? trim((string) $req->query('reco'))   : null;
        $signal   = $req->query('signal') !== null ? trim((string) $req->query('signal')) : null;
        $minSwing = max(0, min(100, (int) ($req->query('min_swing') ?? 0)));
        $sector   = $req->query('sector') !== null ? trim((string) $req->query('sector')) : null;
        $atr      = in_array($req->query('atr'), self::VALID_ATR, true)
            ? (string) $req->query('atr')
            : null;
        $sort     = in_array($req->query('sort'), self::VALID_SORTS, true)
            ? (string) $req->query('sort')
            : 'swing';

        // Treat empty string as null.
        $reco   = $reco   !== '' ? $reco   : null;
        $signal = $signal !== '' ? $signal : null;
        $sector = $sector !== '' ? $sector : null;

        $rows      = $this->repo->getFiltered($reco, $signal, $minSwing, $sector, $sort, $atr);
        $lastScored = $this->repo->getLastScoredAt();
        $sectors   = $this->repo->getDistinctSectors();

        Response::view('screener', [
            'rows'        => $rows,
            'lastScored'  => $lastScored,
            'sectors'     => $sectors,
            'filter_reco'      => $reco,
            'filter_signal'    => $signal,
            'filter_min_swing' => $minSwing,
            'filter_sector'    => $sector,
            'filter_atr'       => $atr,
            'sort'        => $sort,
        ]);
    }
}
