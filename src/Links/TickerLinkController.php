<?php

declare(strict_types=1);

namespace CVS\Links;

use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * AJAX endpoints backing the screener's right-click "favourite links"
 * context menu (change: cvs-screener-ticker-links). Reading links is NOT
 * here — they're bulk-loaded per row by ScreenerRepository and embedded in
 * the page on load (same "no N+1, no extra endpoint" pattern as ATR
 * zones/trajectories), so this controller only ever mutates.
 *
 * Routes:
 *   POST /screener/links/add    — any authenticated user
 *   POST /screener/links/delete — the link's owner, or an admin (any link)
 *
 * Deliberately more permissive than the admin-only precedent set by
 * TickersController/SectorsController: adding a link is low-risk (capped at
 * MAX_LINKS_PER_TICKER, URL scheme-restricted) and useful to every user, not
 * just admins. Deletion still needs an authorization check — canDelete()
 * below — since without it any user could delete anyone's link.
 *
 * Admin status for the delete-any-link bypass is a fresh DB read via
 * UserRepository, never trusting $_SESSION['is_admin'] alone (that flag is
 * only used, elsewhere, as a display-only UI hint) — same care as the
 * admin-only controllers this one otherwise diverges from.
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
     * Returns 200 {ok:true, link:{id, label, url, created_by}}
     * Returns 403 {ok:false, error} on auth/CSRF failure
     * Returns 422 {ok:false, error} on validation failure or the 10-link cap
     */
    public function add(Request $req): void
    {
        AuthController::requireAuth();

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

        $link = $this->repo->create($ticker, $label, $url, $this->currentUserId());

        Response::json(['ok' => true, 'link' => $link]);
    }

    /**
     * POST /screener/links/delete
     * Body: id, _csrf
     * Returns 200 {ok:true, id}
     * Returns 403 {ok:false, error} on auth/CSRF failure, or when the
     *             requester neither owns the link nor is an admin
     * Returns 404 {ok:false, error} when the id doesn't exist
     */
    public function delete(Request $req): void
    {
        AuthController::requireAuth();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.'], 403);
        }

        $id = (int) $req->input('id', 0);
        if ($id <= 0) {
            Response::json(['ok' => false, 'error' => 'Nieprawidłowe id.'], 422);
        }

        $link = $this->repo->findById($id);
        if ($link === null) {
            Response::json(['ok' => false, 'error' => 'Link nie istnieje.'], 404);
        }

        if (!self::canDelete($link['created_by'], $this->currentUserId(), $this->isCurrentUserAdmin())) {
            Response::json(['ok' => false, 'error' => 'Możesz usuwać tylko własne linki.'], 403);
        }

        $this->repo->delete($id);
        Response::json(['ok' => true, 'id' => $id]);
    }

    // ------------------------------------------------------------------
    // Pure validators / authorization (unit-testable, no I/O)
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

    /**
     * An admin may delete any link; anyone else only their own. A null
     * $ownerId (legacy/edge-case row with no recorded creator) fails
     * closed — only an admin can remove it, never "anyone".
     */
    public static function canDelete(?int $ownerId, int $currentUserId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        return $ownerId !== null && $ownerId === $currentUserId;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function currentUserId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function isCurrentUserAdmin(): bool
    {
        $user = $this->users->findById($this->currentUserId());
        return $user !== null && (bool) $user['is_admin'];
    }
}
