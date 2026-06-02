<?php

declare(strict_types=1);

namespace CVS\Pro;

use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\Mail\MailService;

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
    private MailService       $mail;

    public function __construct()
    {
        $this->pro   = new ProRepository();
        $this->usage = new AiUsageRepository();
        $this->users = new UserRepository();
        $mailConfig  = require dirname(__DIR__, 2) . '/config/mail.php';
        $this->mail  = new MailService(null, $mailConfig);
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

    // ------------------------------------------------------------------
    // User: POST /pro/request  (send PRO access request to admin)
    // ------------------------------------------------------------------

    public function sendRequest(Request $req): void
    {
        AuthController::requireAuth();
        if (!$req->verifyCsrf()) {
            Response::redirect('/dashboard');
            return;
        }

        // Anti-spam: one request per session.
        if (!empty($_SESSION['pro_request_sent'])) {
            $_SESSION['_flash'] = 'Prośba o kod PRO została już wysłana w tej sesji.';
            $this->redirectBack();
            return;
        }

        $name      = substr(trim((string) ($req->input('name', ''))), 0, 100);
        $message   = substr(trim((string) ($req->input('message', ''))), 0, 500);
        $userEmail = (string) ($_SESSION['user_email'] ?? '');

        $nameDisplay = $name !== '' ? $name : '(nie podano)';
        $msgDisplay  = $message !== '' ? nl2br(htmlspecialchars($message)) : '<em>(brak wiadomości)</em>';

        $html = '
            <h2 style="color:#1e3a5f;">Prośba o kod PRO — CVS</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Email:</td>
                    <td style="padding:8px;">' . htmlspecialchars($userEmail) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Imię:</td>
                    <td style="padding:8px;">' . htmlspecialchars($nameDisplay) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;vertical-align:top;">Wiadomość:</td>
                    <td style="padding:8px;">' . $msgDisplay . '</td></tr>
            </table>
            <p style="margin-top:16px;">
                <a href="https://cvs.timeflow.fun/admin/pro"
                   style="background:#facc15;color:#0e1b2f;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;display:inline-block;">
                    &#8594; Nadaj kod PRO temu użytkownikowi
                </a>
            </p>
            <p style="color:#555;font-size:13px;margin-top:8px;">
                Link: <a href="https://cvs.timeflow.fun/admin/pro" style="color:#1e3a5f;">https://cvs.timeflow.fun/admin/pro</a>
            </p>
            <p style="color:#888;font-size:12px;margin-top:12px;">
                Wiadomość wygenerowana automatycznie przez CVS Composite Valuation Score.
            </p>
        ';

        $sent = $this->mail->sendToAdmin(
            'Prośba o kod PRO od ' . ($userEmail ?: 'nieznanego użytkownika'),
            $html
        );

        $_SESSION['pro_request_sent'] = true;
        $_SESSION['_flash'] = $sent
            ? 'Prośba wysłana — admin skontaktuje się wkrótce.'
            : 'Prośba zarejestrowana (mail niedostępny — admin zostanie powiadomiony inaczej).';

        $this->redirectBack();
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

    private function redirectBack(): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '/dashboard';
        // Safety: only redirect to same-origin paths.
        $path = parse_url($ref, PHP_URL_PATH) ?? '/dashboard';
        Response::redirect($path);
    }
}
