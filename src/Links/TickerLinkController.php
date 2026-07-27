<?php

declare(strict_types=1);

namespace CVS\Links;

use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Admin-only AJAX endpoints backing the screener's right-click "favourite
 * links" context menu (change: cvs-screener-ticker-links). Reading links is
 * NOT here — they're bulk-loaded per row by ScreenerRepository and embedded
 * in the page on load (same "no N+1, no extra endpoint" pattern as ATR
 * zones/trajectories), so this controller only ever mutates.
 *
 * Routes:
 *   POST /screener/links/add    — admin only
 *   POST /screener/links/delete — admin only
 *
 * Admin check mirrors TickersController/SectorsController/ProController: a
 * fresh DB read via UserRepository, never trusting $_SESSION['is_admin']
 * alone (that flag is only used, elsewhere, as a display-only UI hint).
 */
class TickerLinkController
{
    private const MAX_LINKS_PER_TICKER = 10;

    private UserRepository       $users;
    private TickerLinkRepository $repo;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->repo  = new TickerLinkRepository();
    }

    /**
     * POST /screener/links/add
     * Body: ticker, label, url, _csrf
     * Returns 200 {ok:true, link:{id, label, url}}
     * Returns 403 {ok:false, error} on auth/CSRF failure
     * Returns 422 {ok:false, error} on validation failure or the 10-link cap
     */
    public function add(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.'], 403);
        }

        $ticker = strtoupper(trim((string) $req->input('ticker', '')));
        $label  = trim((string) $req->input('label', ''));
        $url    = trim((string) $req->input('url', ''));

        if (!self::isValidTicker($ticker)) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowy ticker.'], 422);
        }
        if (!self::isValidLabel($label)) {
            Response::json(['ok' => false, 'error' => 'Etykieta musi mieć 1-80 znaków.'], 422);
        }
        if (!self::isValidUrl($url)) {
            Response::json(['ok' => false, 'error' => 'Link musi być poprawnym adresem http(s)://.'], 422);
        }

        if ($this->repo->countByTicker($ticker) >= self::MAX_LINKS_PER_TICKER) {
            Response::json([
                'ok'    => false,
                'error' => 'Osiągnięto limit ' . self::MAX_LINKS_PER_TICKER . ' linków dla tej spółki.',
            ], 422);
        }

        $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $link      = $this->repo->create($ticker, $label, $url, $createdBy);

        Response::json(['ok' => true, 'link' => $link]);
    }

    /**
     * POST /screener/links/delete
     * Body: id, _csrf
     * Returns 200 {ok:true, id}
     * Returns 403 {ok:false, error} on auth/CSRF failure
     * Returns 404 {ok:false, error} when the id doesn't exist
     */
    public function delete(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.'], 403);
        }

        $id = (int) $req->input('id', 0);
        if ($id <= 0) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowe id.'], 422);
        }

        if (!$this->repo->delete($id)) {
            Response::json(['ok' => false, 'error' => 'Link nie istnieje.'], 404);
        }

        Response::json(['ok' => true, 'id' => $id]);
    }

    // ------------------------------------------------------------------
    // Pure validators (unit-testable, no I/O)
    // ------------------------------------------------------------------

    /** Same ticker shape as WatchlistController::toggle() / TickersController. */
    public static function isValidTicker(string $ticker): bool
    {
        return preg_match('/^[A-Z0-9.]{1,10}$/', $ticker) === 1;
    }

    public static function isValidLabel(string $label): bool
    {
        $len = mb_strlen($label);
        return $len >= 1 && $len <= 80;
    }

    /**
     * Requires an explicit http(s):// scheme (blocks javascript:/data: etc.)
     * and a well-formed URL, capped at the column width.
     */
    public static function isValidUrl(string $url): bool
    {
        if (strlen($url) > 500) {
            return false;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireAdmin(): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user   = $this->users->findById($userId);

        if (!$user || !(bool) $user['is_admin']) {
            Response::json(['ok' => false, 'error' => 'Brak uprawnień.'], 403);
        }
    }
}
