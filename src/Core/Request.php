<?php

declare(strict_types=1);

namespace CVS\Core;

/**
 * Thin wrapper around the current HTTP request.
 *
 * Provides typed accessors for GET/POST data, route params,
 * and the raw body — without pulling in a full HTTP library.
 */
class Request
{
    public function __construct(
        /** Named route parameters extracted by the Router. */
        private readonly array $routeParams = []
    ) {}

    // ------------------------------------------------------------------
    // Route params  (e.g. {ticker} from /analysis/{ticker})
    // ------------------------------------------------------------------

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    // ------------------------------------------------------------------
    // Query string ($_GET)
    // ------------------------------------------------------------------

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    // ------------------------------------------------------------------
    // POST body ($_POST  or  JSON body)
    // ------------------------------------------------------------------

    public function input(string $key, mixed $default = null): mixed
    {
        if (!empty($_POST)) {
            return $_POST[$key] ?? $default;
        }

        // Attempt to decode a JSON body (AJAX requests).
        static $json = null;
        if ($json === null) {
            $raw  = file_get_contents('php://input');
            $json = $raw ? (json_decode($raw, true) ?? []) : [];
        }

        return $json[$key] ?? $default;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    // ------------------------------------------------------------------
    // CSRF helpers
    // ------------------------------------------------------------------

    /**
     * Return the CSRF token from the POST body or the custom header.
     */
    public function csrfToken(): string
    {
        return (string) (
            $_POST['_csrf'] ??
            $_SERVER['HTTP_X_CSRF_TOKEN'] ??
            ''
        );
    }

    /**
     * Validate the submitted CSRF token against the one stored in $_SESSION.
     */
    public function verifyCsrf(): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return $expected !== '' && hash_equals($expected, $this->csrfToken());
    }
}
