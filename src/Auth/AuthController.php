<?php

declare(strict_types=1);

namespace CVS\Auth;

use CVS\Core\Request;
use CVS\Core\Response;
use CVS\Mail\MailService;

/**
 * Handles login, registration, logout, email verification, and root redirect.
 *
 * Security model:
 *  - Passwords hashed with PASSWORD_BCRYPT (cost 12).
 *  - CSRF token validated on every POST.
 *  - Session ID regenerated on login to prevent session fixation.
 *  - Email must be verified before session is established.
 */
class AuthController
{
    private UserRepository $users;
    private MailService    $mail;
    private PillarCaptcha  $captcha;
    /** @var array<string, mixed> */
    private array $authConfig;

    public function __construct()
    {
        $this->users      = new UserRepository();
        $this->mail       = new MailService();
        $this->authConfig = require dirname(__DIR__, 2) . '/config/auth.php';

        // Swing-mode weights reused verbatim from the real CVS model config
        // (FR-010 spirit — one source of truth, no duplicated magic numbers)
        // purely to theme the anti-bot arithmetic challenge; never used for
        // any actual CVS scoring.
        $cvsWeights = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $swing      = $cvsWeights['modes']['swing'];

        $this->captcha = new PillarCaptcha(
            (float) $swing['valuation_weight'],
            (float) $swing['quality_weight'],
            (int) $this->authConfig['captcha']['min_form_age_seconds'],
            (string) $this->authConfig['captcha']['honeypot_field'],
        );
    }

    // ------------------------------------------------------------------
    // Root
    // ------------------------------------------------------------------

    public function index(Request $req): void
    {
        if ($this->loggedIn()) {
            Response::redirect('/dashboard');
        }
        Response::redirect('/login');
    }

    // ------------------------------------------------------------------
    // Login
    // ------------------------------------------------------------------

    public function loginForm(Request $req): void
    {
        if ($this->loggedIn()) {
            Response::redirect('/dashboard');
        }
        $this->refreshCsrf();
        Response::view('login', ['error' => null]);
    }

    public function login(Request $req): void
    {
        if (!$req->verifyCsrf()) {
            Response::view('login', ['error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
            return;
        }

        $email    = trim((string) $req->input('email', ''));
        $password = (string) $req->input('password', '');

        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            // Generic message — do not reveal whether email exists.
            Response::view('login', ['error' => 'Nieprawidłowy e-mail lub hasło.']);
            return;
        }

        // Block unverified accounts — send fresh link (cooldown-gated, see
        // sendVerificationEmail()) and redirect to check-email.
        if (!$this->users->isEmailVerified((int) $user['id'])) {
            $this->sendVerificationEmail((int) $user['id'], (string) $user['email']);
            $_SESSION['pending_verification_email'] = $user['email'];
            Response::redirect('/auth/check-email');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_email'] = $user['email']; // S-05: used by PRO request form
        $_SESSION['is_admin']   = (bool) $user['is_admin'];
        unset($_SESSION['csrf_token']); // Force fresh token after login.

        Response::redirect('/dashboard');
    }

    // ------------------------------------------------------------------
    // Register
    // ------------------------------------------------------------------

    public function registerForm(Request $req): void
    {
        if ($this->loggedIn()) {
            Response::redirect('/dashboard');
        }
        $this->refreshCsrf();
        $this->renderRegister(null);
    }

    public function register(Request $req): void
    {
        if (!$req->verifyCsrf()) {
            $this->renderRegister('Nieprawidłowy token CSRF. Odśwież stronę.');
            return;
        }

        // Anti-bot check first — before touching the DB at all. Generic
        // failure message on purpose (see PillarCaptcha::verify() docblock):
        // does not reveal which of the three layers (honeypot / timing /
        // arithmetic) tripped, giving a probing bot nothing to iterate on.
        if (!$this->captcha->verify($req)) {
            $this->renderRegister('Nie udało się zweryfikować formularza. Spróbuj ponownie.');
            return;
        }

        $email    = trim((string) $req->input('email', ''));
        $password = (string) $req->input('password', '');
        $confirm  = (string) $req->input('password_confirm', '');

        // --- Validation ---
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderRegister('Podaj prawidłowy adres e-mail.');
            return;
        }
        if (strlen($password) < 8) {
            $this->renderRegister('Hasło musi mieć co najmniej 8 znaków.');
            return;
        }
        if ($password !== $confirm) {
            $this->renderRegister('Hasła nie są identyczne.');
            return;
        }
        if ($this->users->emailExists($email)) {
            // Do not reveal whether the address is already registered
            // (enumeration — same CLAUDE.md rule as the login flow, pointed
            // out by review while auditing this exact registration path).
            // Behave identically to a successful registration from the
            // requester's point of view: no new account, no email sent,
            // same redirect. A real owner of that address already has
            // their password and isn't affected.
            $this->captcha->clear();
            $_SESSION['pending_verification_email'] = $email;
            Response::redirect('/auth/check-email');
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id   = $this->users->create($email, $hash);

        $this->captcha->clear();
        $this->sendVerificationEmail($id, $email);
        $_SESSION['pending_verification_email'] = $email;
        Response::redirect('/auth/check-email');
    }

    /** Re-render the register form with a fresh CSRF-independent CVS Pillar Check challenge. */
    private function renderRegister(?string $error): void
    {
        Response::view('register', [
            'error'   => $error,
            'captcha' => $this->captcha->generate(),
        ]);
    }

    // ------------------------------------------------------------------
    // Email verification
    // ------------------------------------------------------------------

    public function showCheckEmail(Request $req): void
    {
        $this->refreshCsrf();
        $email = (string) ($_SESSION['pending_verification_email'] ?? '');
        Response::view('auth/check-email', ['email' => $email]);
    }

    public function resendVerification(Request $req): void
    {
        if (!$req->verifyCsrf()) {
            Response::redirect('/auth/check-email');
            return;
        }
        $email = (string) ($_SESSION['pending_verification_email'] ?? '');
        if ($email === '') {
            Response::redirect('/login');
            return;
        }
        $user = $this->users->findByEmail($email);
        if ($user === null || $this->users->isEmailVerified((int) $user['id'])) {
            Response::redirect('/login');
            return;
        }

        $_SESSION['_flash'] = $this->sendVerificationEmail((int) $user['id'], $email)
            ? 'Nowy link weryfikacyjny został wysłany.'
            : 'Link weryfikacyjny już wysłany — spróbuj ponownie za chwilę.';
        Response::redirect('/auth/check-email');
    }

    /**
     * Generate a fresh verification token and email it, IF the per-account
     * resend cooldown allows it. This is the single choke point every call
     * site (register / login-unverified / resendVerification) goes
     * through, so the email-bombing guard can't be forgotten at a future
     * call site. Returns whether it actually sent.
     */
    private function sendVerificationEmail(int $userId, string $email): bool
    {
        $cooldown = (int) $this->authConfig['verify_resend_cooldown_seconds'];
        if (!$this->users->canResendVerification($userId, $cooldown)) {
            return false;
        }

        $token   = bin2hex(random_bytes(32));
        $expires = (new \DateTime('+48 hours'))->format('Y-m-d H:i:s');
        $this->users->setVerifyToken($userId, $token, $expires);
        $verifyUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/auth/verify?token=' . $token;
        $this->mail->send($email, 'CVS — potwierdź adres e-mail', $this->buildVerificationHtml($email, $verifyUrl));
        return true;
    }

    public function verify(Request $req): void
    {
        $token = (string) ($req->query('token') ?? '');
        if ($token === '') {
            Response::view('auth/verify-error', ['email' => '']);
            return;
        }
        $user = $this->users->findByVerifyToken($token);
        if ($user === null) {
            $pendingEmail = (string) ($_SESSION['pending_verification_email'] ?? '');
            Response::view('auth/verify-error', ['email' => $pendingEmail]);
            return;
        }
        $this->users->setEmailVerified((int) $user['id']);
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $fullUser = $this->users->findById((int) $user['id']);
        $_SESSION['is_admin']   = (bool) ($fullUser['is_admin'] ?? false);
        unset($_SESSION['csrf_token'], $_SESSION['pending_verification_email']);
        $_SESSION['_flash'] = 'Adres e-mail potwierdzony! Witaj w CVS.';
        Response::redirect('/dashboard');
    }

    // ------------------------------------------------------------------
    // Password reset
    // ------------------------------------------------------------------

    public function forgotPasswordForm(Request $req): void
    {
        if ($this->loggedIn()) {
            Response::redirect('/dashboard');
        }
        $this->refreshCsrf();
        Response::view('auth/forgot-password', ['error' => null]);
    }

    public function forgotPassword(Request $req): void
    {
        if (!$req->verifyCsrf()) {
            Response::view('auth/forgot-password', ['error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
            return;
        }

        $email = trim((string) $req->input('email', ''));

        // Same anti-enumeration rule as login/register (CLAUDE.md: never
        // reveal whether an e-mail exists) — send only if it's a real,
        // registered address, but redirect to the exact same page either way.
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                $this->sendPasswordResetEmail((int) $user['id'], (string) $user['email']);
            }
        }

        $_SESSION['pending_reset_email'] = $email;
        Response::redirect('/auth/reset-link-sent');
    }

    public function showResetLinkSent(Request $req): void
    {
        $this->refreshCsrf();
        $email = (string) ($_SESSION['pending_reset_email'] ?? '');
        Response::view('auth/reset-link-sent', ['email' => $email]);
    }

    public function resendPasswordReset(Request $req): void
    {
        if (!$req->verifyCsrf()) {
            Response::redirect('/auth/reset-link-sent');
            return;
        }
        $email = (string) ($_SESSION['pending_reset_email'] ?? '');
        if ($email === '') {
            Response::redirect('/login');
            return;
        }

        // Same generic behaviour regardless of whether the address is registered.
        $user = $this->users->findByEmail($email);
        if ($user !== null) {
            $this->sendPasswordResetEmail((int) $user['id'], $email);
        }
        Response::redirect('/auth/reset-link-sent');
    }

    /**
     * Generate a fresh reset token and email it, IF the per-account resend
     * cooldown allows it — same choke-point pattern as
     * sendVerificationEmail(), for the same email-bombing reason. Returns
     * whether it actually sent.
     */
    private function sendPasswordResetEmail(int $userId, string $email): bool
    {
        $cooldown = (int) $this->authConfig['reset_resend_cooldown_seconds'];
        if (!$this->users->canResendPasswordReset($userId, $cooldown)) {
            return false;
        }

        $token   = bin2hex(random_bytes(32));
        // Shorter-lived than the 48h email-verify link — a reset token grants
        // account takeover, not just email confirmation, so it should be
        // useless well before an inbox is likely to be compromised later.
        $expires = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');
        $this->users->setPasswordResetToken($userId, $token, $expires);
        $resetUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/auth/reset-password?token=' . $token;
        $this->mail->send($email, 'CVS — reset hasła', $this->buildPasswordResetHtml($email, $resetUrl));
        return true;
    }

    public function resetPasswordForm(Request $req): void
    {
        $token = (string) ($req->query('token') ?? '');
        $user  = $token !== '' ? $this->users->findByPasswordResetToken($token) : null;
        if ($user === null) {
            Response::view('auth/reset-password-error', []);
            return;
        }
        $this->refreshCsrf();
        Response::view('auth/reset-password-form', ['token' => $token, 'error' => null]);
    }

    public function resetPassword(Request $req): void
    {
        $token = (string) $req->input('token', '');

        if (!$req->verifyCsrf()) {
            Response::view('auth/reset-password-form', ['token' => $token, 'error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
            return;
        }

        // Re-validate the token here too (not just in resetPasswordForm) —
        // it may have expired, or already been used, between the GET and this POST.
        $user = $token !== '' ? $this->users->findByPasswordResetToken($token) : null;
        if ($user === null) {
            Response::view('auth/reset-password-error', []);
            return;
        }

        $password = (string) $req->input('password', '');
        $confirm  = (string) $req->input('password_confirm', '');

        if (strlen($password) < 8) {
            Response::view('auth/reset-password-form', ['token' => $token, 'error' => 'Hasło musi mieć co najmniej 8 znaków.']);
            return;
        }
        if ($password !== $confirm) {
            Response::view('auth/reset-password-form', ['token' => $token, 'error' => 'Hasła nie są identyczne.']);
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->users->resetPassword((int) $user['id'], $hash);

        // The reset link is itself proof of e-mail ownership — same trust
        // level as clicking the verify link, so log in immediately (mirrors verify()).
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $fullUser = $this->users->findById((int) $user['id']);
        $_SESSION['is_admin']   = (bool) ($fullUser['is_admin'] ?? false);
        unset($_SESSION['csrf_token'], $_SESSION['pending_reset_email']);
        $_SESSION['_flash'] = 'Hasło zostało zmienione. Witaj z powrotem!';
        Response::redirect('/dashboard');
    }

    // ------------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------------

    public function logout(Request $req): void
    {
        $_SESSION = [];
        session_destroy();
        Response::redirect('/login');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function loggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    private function refreshCsrf(): void
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Static guard — call from any protected controller action.
     * Redirects to /login if the session has no user_id.
     */
    public static function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            Response::redirect('/login');
        }
        // Ensure CSRF token exists for all protected views.
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    private function buildVerificationHtml(string $email, string $verifyUrl): string
    {
        return '
            <h2 style="color:#1e3a5f;">Potwierdź adres e-mail w CVS</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr>
                    <td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Adres e-mail:</td>
                    <td style="padding:8px;">' . htmlspecialchars($email) . '</td>
                </tr>
            </table>
            <p style="margin-top:16px;">
                Kliknij przycisk poniżej, aby potwierdzić adres e-mail i aktywować konto CVS:
            </p>
            <p>
                <a href="' . htmlspecialchars($verifyUrl) . '"
                   style="background:#1e3a5f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-block;">
                    Potwierdź adres e-mail →
                </a>
            </p>
            <p style="color:#888;font-size:12px;margin-top:12px;">
                Link ważny przez 48 godzin. Jeśli nie rejestrowałeś/-aś się w CVS, zignoruj tę wiadomość.
            </p>
            <p style="color:#aaa;font-size:10px;margin-top:8px;">
                CVS Composite Valuation Score — <a href="https://cvs.timeflow.fun" style="color:#aaa;">cvs.timeflow.fun</a>
            </p>
        ';
    }

    private function buildPasswordResetHtml(string $email, string $resetUrl): string
    {
        return '
            <h2 style="color:#1e3a5f;">Reset hasła w CVS</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr>
                    <td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Adres e-mail:</td>
                    <td style="padding:8px;">' . htmlspecialchars($email) . '</td>
                </tr>
            </table>
            <p style="margin-top:16px;">
                Kliknij przycisk poniżej, aby ustawić nowe hasło do konta CVS:
            </p>
            <p>
                <a href="' . htmlspecialchars($resetUrl) . '"
                   style="background:#1e3a5f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-block;">
                    Ustaw nowe hasło →
                </a>
            </p>
            <p style="color:#888;font-size:12px;margin-top:12px;">
                Link ważny przez 1 godzinę. Jeśli nie prosiłeś/-aś o reset hasła, zignoruj tę wiadomość —
                Twoje obecne hasło pozostanie bez zmian.
            </p>
            <p style="color:#aaa;font-size:10px;margin-top:8px;">
                CVS Composite Valuation Score — <a href="https://cvs.timeflow.fun" style="color:#aaa;">cvs.timeflow.fun</a>
            </p>
        ';
    }
}
