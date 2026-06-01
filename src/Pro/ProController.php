<?php

declare(strict_types=1);

namespace CVS\Pro;

use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Handles PRO code management (admin-only) and code activation (any user).
 *
 * Admin routes (/admin/pro) require is_admin = 1 — checked via DB on every
 * request to avoid stale session values.
 */
class ProController
{
    private ProRepository     $pro;
    private AiUsageRepository $usage;
    private UserRepository    $users;

    public function __construct()
    {
        $this->pro   = new ProRepository();
        $this->usage = new AiUsageRepository();
        $this->users = new UserRepository();
    }

    // ------------------------------------------------------------------
    // Admin: GET /admin/pro
    // ------------------------------------------------------------------

    public function index(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        $codes = $this->pro->findAll();
        $users = $this->users->findAll();
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        Response::view('pro/admin', [
            'codes' => $codes,
            'users' => $users,
            'flash' => $flash,
        ]);
    }

    // ------------------------------------------------------------------
    // Admin: POST /admin/pro  (create new code)
    // ------------------------------------------------------------------

    public function store(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();
        if (!$req->verifyCsrf()) {
            Response::redirect('/admin/pro');
            return;
        }

        $code   = trim((string) ($req->input('code') ?? ''));
        $rawUid = $req->input('user_id');
        $userId = ($rawUid !== null && $rawUid !== '') ? (int) $rawUid : null;
        $desc   = trim((string) ($req->input('description') ?? ''));

        if ($code === '') {
            $_SESSION['_flash'] = 'Kod nie może być pusty.';
            Response::redirect('/admin/pro');
            return;
        }

        $this->pro->create($code, $userId, $desc);
        $_SESSION['_flash'] = 'Kod PRO dodany.';
        Response::redirect('/admin/pro');
    }

    // ------------------------------------------------------------------
    // Admin: POST /admin/pro/revoke
    // ------------------------------------------------------------------

    public function revoke(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();
        if (!$req->verifyCsrf()) {
            Response::redirect('/admin/pro');
            return;
        }

        $id = (int) ($req->input('id') ?? 0);
        if ($id > 0) {
            $this->pro->revoke($id);
            $_SESSION['_flash'] = 'Kod PRO unieważniony.';
        }
        Response::redirect('/admin/pro');
    }

    // ------------------------------------------------------------------
    // Admin: POST /admin/pro/activate-code
    // ------------------------------------------------------------------

    public function activateCode(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();
        if (!$req->verifyCsrf()) {
            Response::redirect('/admin/pro');
            return;
        }

        $id = (int) ($req->input('id') ?? 0);
        if ($id > 0) {
            $this->pro->activate($id);
            $_SESSION['_flash'] = 'Kod PRO przywrócony.';
        }
        Response::redirect('/admin/pro');
    }

    // ------------------------------------------------------------------
    // User: POST /pro/activate  (validate code + store in session)
    // ------------------------------------------------------------------

    public function activate(Request $req): void
    {
        AuthController::requireAuth();
        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'Błąd CSRF.'], 403);
            return;
        }

        $userId = (int) $_SESSION['user_id'];
        $code   = trim((string) ($req->input('code') ?? ''));

        $aiConfig = require dirname(__DIR__, 2) . '/config/ai.php';
        $gate     = new ProGate($this->pro, $this->usage, $aiConfig);

        if ($gate->activateCode($code, $userId)) {
            Response::json(['ok' => true, 'message' => 'Kod PRO aktywowany.']);
        } else {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowy lub nieaktywny kod PRO.'], 422);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireAdmin(): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user   = $this->users->findById($userId);

        if (!$user || !(bool) $user['is_admin']) {
            Response::redirect('/dashboard');
        }
    }
}
