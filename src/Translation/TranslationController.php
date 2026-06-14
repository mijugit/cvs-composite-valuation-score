<?php

declare(strict_types=1);

namespace CVS\Translation;

use CVS\Auth\AuthController;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * AJAX endpoint backing the on-device Translator API (Chrome Built-in AI).
 *
 * When a user's browser successfully translates a field client-side, the
 * result is POSTed here and cached so other users (without Translator API
 * support) can be served the cached translation instead.
 */
class TranslationController
{
    private const ALLOWED_LANGS  = ['pl', 'en'];
    private const ALLOWED_FIELDS = ['long_description'];

    private TranslationRepository $repo;

    public function __construct()
    {
        $this->repo = new TranslationRepository();
    }

    // ------------------------------------------------------------------
    // POST /api/translation/save
    // ------------------------------------------------------------------

    public function save(Request $req): void
    {
        AuthController::requireAuth();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'CSRF error'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->input('ticker', '')));
        $lang   = strtolower(trim((string) $req->input('lang', '')));
        $field  = trim((string) $req->input('field', ''));
        $text   = trim((string) $req->input('text', ''));

        if ($ticker === '' || $text === ''
            || !in_array($lang, self::ALLOWED_LANGS, true)
            || !in_array($field, self::ALLOWED_FIELDS, true)
        ) {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowe dane.'], 422);
            return;
        }

        $this->repo->save($ticker, $lang, $field, $text);
        Response::json(['ok' => true]);
    }
}
