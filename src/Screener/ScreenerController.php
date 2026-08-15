<?php

declare(strict_types=1);

namespace CVS\Screener;

use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\CVS\Valuation\PeerCoverage;
use CVS\Auth\AuthController;
use CVS\Core\Database;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\Portfolio\PortfolioRepository;
use CVS\Watchlist\WatchlistRepository;

/**
 * Handles GET /screener — CVS ranking with filters.
 */
class ScreenerController
{
    private ScreenerRepository $repo;

    /** @var array<string, mixed> config/cvs-weights.php → snapshot_freshness */
    private array $freshness;

    /** @var array<string, mixed> full config/cvs-weights.php */
    private array $cvsConfig;

    private const VALID_SORTS = ['swing', 'fund', 'date', 'ticker', 'price', 'atr', 'fv'];
    private const VALID_ATR   = ['in_zone', 'above', 'below'];

    public function __construct()
    {
        // Hotfix (2026-06-08): inject the live model_version so the repository
        // can filter out cvs-overlay-penalties shadow rows (3.1) — see
        // ScreenerRepository::$liveModelVersion / findAllLatest().
        $config          = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $liveVersion     = $config['model_version'] ?? null;
        $this->cvsConfig = $config;
        $this->freshness = $config['snapshot_freshness'] ?? [];
        $this->repo      = new ScreenerRepository(
            null,
            $liveVersion !== null ? (string) $liveVersion : null,
            $config['trajectory'] ?? [],
            $config['thresholds'] ?? [],
            $config['markets'] ?? []
        );
    }

    public function index(Request $req): void
    {
        AuthController::requireAuth();

        // Parse and sanitise filter params.
        $reco     = $req->query('reco')   !== null ? trim((string) $req->query('reco'))   : null;
        $signal   = $req->query('signal') !== null ? trim((string) $req->query('signal')) : null;
        $minSwing = max(0, min(100, (int) ($req->query('min_swing') ?? 0)));
        $sector   = $req->query('sector') !== null ? trim((string) $req->query('sector')) : null;
        $market   = $req->query('market') !== null ? trim((string) $req->query('market')) : null;
        $atr      = in_array($req->query('atr'), self::VALID_ATR, true)
            ? (string) $req->query('atr')
            : null;
        $sort     = in_array($req->query('sort'), self::VALID_SORTS, true)
            ? (string) $req->query('sort')
            : 'swing';
        $dir      = in_array($req->query('dir'), ['asc', 'desc'], true)
            ? (string) $req->query('dir')
            : null;
        $nearBoundary = $req->query('near_boundary') === '1';
        $fvOnly       = $req->query('fv_only') === '1';

        // Treat empty string as null.
        $reco   = $reco   !== '' ? $reco   : null;
        $signal = $signal !== '' ? $signal : null;
        $sector = $sector !== '' ? $sector : null;
        $market = $market !== '' ? $market : null;

        $rows      = $this->repo->getFiltered($reco, $signal, $minSwing, $sector, $sort, $atr, $nearBoundary, $fvOnly, $market, $dir);
        $lastScored = $this->repo->getLastScoredAt();
        $sectors   = $this->repo->getDistinctSectors();
        $markets   = $this->repo->getDistinctMarkets();

        // Display-only hints for the right-click "favourite links" menu (change:
        // cvs-screener-ticker-links). Any authenticated user may add a link;
        // isAdmin/currentUserId here only decide which "✕ remove" controls the
        // JS renders (own links, plus every link for an admin) — NOT a security
        // boundary. TickerLinkController::canDelete() re-verifies ownership/admin
        // status against the DB on every delete request regardless of what the
        // page rendered.
        $isAdmin       = (bool) ($_SESSION['is_admin'] ?? false);
        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

        // Build held-ticker map for screener badge enrichment (S-04).
        $portfolioRepo  = new PortfolioRepository(Database::connection());
        $holdings       = $portfolioRepo->getCurrentHoldings();
        $heldTickersMap = array_fill_keys(array_column($holdings, 'ticker'), true);

        Response::view('screener', [
            'rows'        => $rows,
            'lastScored'  => $lastScored,
            'sectors'     => $sectors,
            'markets'     => $markets,
            'filter_reco'      => $reco,
            'filter_signal'    => $signal,
            'filter_min_swing' => $minSwing,
            'filter_sector'    => $sector,
            'filter_market'    => $market,
            'filter_atr'       => $atr,
            'filter_near_boundary' => $nearBoundary,
            'filter_fv_only'   => $fvOnly,
            'sort'             => $sort,
            'dir'              => $dir ?? ScreenerRepository::defaultDirFor($sort),
            'heldTickersMap'   => $heldTickersMap,
            'isAdmin'          => $isAdmin,
            'currentUserId'    => $currentUserId,
            // Age badge: findAllLatest() has no upper bound on snapshot age, so a
            // ticker whose rescore keeps failing presents month-old numbers that
            // look identical to today's. Nothing is hidden — the age is just made
            // visible (config: snapshot_freshness.warn_after_days).
            'warnAfterDays'    => (int) ($this->freshness['warn_after_days'] ?? 3),
            'todayDate'        => (new \DateTimeImmutable())->format('Y-m-d'),
            // The age badge needs to name the right cause. bin/rescore.php only
            // iterates the union of every user's watchlist, so a ticker nobody
            // watches is never refreshed at all — its data is not "failing", it
            // is simply orphaned, and adding it to a watchlist fixes it. Saying
            // "rescore nie przechodzi" for those would be a plain misdiagnosis.
            'watchedTickers'   => array_fill_keys(
                array_map('strtoupper', (new WatchlistRepository(Database::connection()))->findAllDistinctTickers()),
                true
            ),
            // Peer coverage: a company whose industry bucket is below
            // min_sample_count is benchmarked against its SECTOR instead, which
            // can flatter or punish it badly (ASB.WA: 10.3x industry vs 24.4x
            // sector). One bulk read, resolved per row in the view.
            'peerCoverage'     => new PeerCoverage(
                (new PeerMedianRepository(Database::connection()))
                    ->findIndustrySampleCounts((string) ($this->cvsConfig['model_version'] ?? ''), 'ev_fcf'),
                (int) ($this->cvsConfig['peer_group']['min_sample_count'] ?? 5)
            ),
        ]);
    }
}
