<?php

declare(strict_types=1);

namespace CVS\Auth;

use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Handles login, registration, logout, and root redirect.
 *
 * Security model:
 *  - Passwords hashed with PASSWORD_BCRYPT (cost 12).
 *  - CSRF token validated on every POST.
 *  - Session ID regenerated on login to prevent session fixation.
 *  - $_SESSION stores only user id; email fetched from DB on each request.
 */
class AuthController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
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

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
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
        Response::view('register', ['error' => null]);
    }

    public function register(Request $req): void
    {
        if (!$req->verifyCsrf()) {
            Response::view('register', ['error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
            return;
        }

        $email    = trim((string) $req->input('email', ''));
        $password = (string) $req->input('password', '');
        $confirm  = (string) $req->input('password_confirm', '');

        // --- Validation ---
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::view('register', ['error' => 'Podaj prawidłowy adres e-mail.']);
            return;
        }
        if (strlen($password) < 8) {
            Response::view('register', ['error' => 'Hasło musi mieć co najmniej 8 znaków.']);
            return;
        }
        if ($password !== $confirm) {
            Response::view('register', ['error' => 'Hasła nie są identyczne.']);
            return;
        }
        if ($this->users->emailExists($email)) {
            Response::view('register', ['error' => 'Ten adres e-mail jest już zajęty.']);
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id   = $this->users->create($email, $hash);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;

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
    }
}
