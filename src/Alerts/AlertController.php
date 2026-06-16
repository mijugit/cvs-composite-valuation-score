<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * AJAX endpoints for alert preference toggles.
 */
class AlertController
{
    private AlertRepository $repo;
    private UserRepository  $users;

    public function __construct()
    {
        $this->repo  = new AlertRepository();
        $this->users = new UserRepository();
    }

    // ------------------------------------------------------------------
    // POST /alerts/global
    // ------------------------------------------------------------------

    public function toggleGlobal(Request $req): void
    {
        AuthController::requireAuth();
        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'CSRF error'], 403);
            return;
        }

        $userId  = (int) $_SESSION['user_id'];
        $current = $this->repo->isGlobalEnabled($userId);
        $new     = !$current;
        $this->repo->setGlobalEnabled($userId, $new);

        Response::json(['ok' => true, 'enabled' => $new]);
    }

    // ------------------------------------------------------------------
    // GET /alerts/unsubscribe?uid=X&token=Y  (no login required)
    // ------------------------------------------------------------------

    public function unsubscribe(Request $req): void
    {
        $uid   = (int) ($req->query('uid') ?? 0);
        $token = (string) ($req->query('token') ?? '');

        $success = false;

        if ($uid > 0 && $token !== '') {
            $user = $this->users->findById($uid);
            if ($user !== null && isset($user['email'])) {
                $expected = hash_hmac(
                    'sha256',
                    'unsub:' . $uid . ':' . $user['email'],
                    $_ENV['APP_SECRET'] ?? ''
                );
                if (hash_equals($expected, $token)) {
                    $this->repo->setGlobalEnabled($uid, false);
                    $success = true;
                }
            }
        }

        Response::view('alerts/unsubscribe', ['success' => $success]);
    }

    // ------------------------------------------------------------------
    // POST /alerts/ticker
    // ------------------------------------------------------------------

    public function toggleTicker(Request $req): void
    {
        AuthController::requireAuth();
        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'CSRF error'], 403);
            return;
        }

        $userId  = (int) $_SESSION['user_id'];
        $ticker  = strtoupper(trim((string) ($req->input('ticker') ?? '')));

        if ($ticker === '') {
            Response::json(['ok' => false, 'message' => 'Ticker required'], 400);
            return;
        }

        $currentlyDisabled = $this->repo->isTickerDisabled($userId, $ticker);
        $newDisabled       = !$currentlyDisabled;
        $this->repo->setTickerDisabled($userId, $ticker, $newDisabled);

        Response::json(['ok' => true, 'disabled' => $newDisabled]);
    }
}
