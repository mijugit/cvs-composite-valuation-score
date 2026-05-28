<?php

declare(strict_types=1);

namespace CVS\Watchlist;

use CVS\Auth\AuthController;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Handles AJAX watchlist toggle.
 *
 * Single action:  POST /watchlist/toggle
 * Response shape: {ok, action, ticker, count}  or  {ok:false, error}
 *
 * CSRF is validated on every POST via Request::verifyCsrf().
 * Limit is read from config/cvs-weights.php → data_source.max_watchlist.
 */
class WatchlistController
{
    private WatchlistRepository $repo;
    private int $maxWatchlist;

    public function __construct()
    {
        $config            = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->repo        = new WatchlistRepository();
        $this->maxWatchlist = (int) ($config['data_source']['max_watchlist'] ?? 20);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * POST /watchlist/toggle
     *
     * Body:    ticker=AAPL&_csrf=<token>
     * Headers: X-CSRF-Token: <token>
     *
     * Returns 200 {ok:true, action:'added'|'removed', ticker, count}
     * Returns 403 {ok:false, error: 'Nieprawidłowy token CSRF.'}
     * Returns 422 {ok:false, error: '…'}
     */
    public function toggle(Request $req): void
    {
        AuthController::requireAuth();

        // CSRF guard
        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.'], 403);
        }

        // Sanitise + validate ticker
        $raw    = (string) $req->input('ticker', '');
        $ticker = strtoupper(trim($raw));

        if ($ticker === '' || !preg_match('/^[A-Z0-9.]{1,10}$/', $ticker)) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowy ticker.'], 422);
        }

        $userId = (int) $_SESSION['user_id'];

        // Enforce limit only when adding
        if (!$this->repo->isWatched($userId, $ticker)) {
            $count = $this->repo->countByUser($userId);
            if ($count >= $this->maxWatchlist) {
                Response::json([
                    'ok'    => false,
                    'error' => "Osiągnięto limit {$this->maxWatchlist} obserwowanych.",
                ], 422);
            }
        }

        $action = $this->repo->toggle($userId, $ticker);
        $count  = $this->repo->countByUser($userId);

        Response::json([
            'ok'     => true,
            'action' => $action,
            'ticker' => $ticker,
            'count'  => $count,
        ]);
    }
}
