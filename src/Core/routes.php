<?php

declare(strict_types=1);

/**
 * Application route definitions.
 *
 * $router is injected by public/index.php before this file is required.
 * @var \CVS\Core\Router $router
 */

use CVS\Ai\AiAnalysisController;
use CVS\Alerts\AlertController;
use CVS\Auth\AuthController;
use CVS\CVS\AnalysisController;
use CVS\Screener\ScreenerController;
use CVS\TrackRecord\TrackRecordController;
use CVS\Watchlist\WatchlistController;
use CVS\Admin\SectorsController;
use CVS\Admin\TickersController;
use CVS\Pro\ProController;
use CVS\Translation\TranslationController;
use CVS\Portfolio\PortfolioController;
use CVS\Lab\LabController;
use CVS\Links\TickerLinkController;

$auth        = new AuthController();
$analysis    = new AnalysisController();
$watchlist   = new WatchlistController();
$pro         = new ProController();
$sectors     = new SectorsController();
$tickersAdmin = new TickersController();
$trackRecord = new TrackRecordController();
$screener    = new ScreenerController();
$alertCtrl   = new AlertController();
$translation = new TranslationController();
$aiConfig   = require dirname(__DIR__, 2) . '/config/ai.php';
$aiAnalysis = new AiAnalysisController($aiConfig);

// ------------------------------------------------------------------
// Public routes
// ------------------------------------------------------------------

$router->get('/',                    fn($req) => $auth->index($req));
$router->get('/login',               fn($req) => $auth->loginForm($req));
$router->post('/login',              fn($req) => $auth->login($req));
$router->get('/register',            fn($req) => $auth->registerForm($req));
$router->post('/register',           fn($req) => $auth->register($req));
$router->get('/logout',              fn($req) => $auth->logout($req));
$router->get('/auth/check-email',          fn($req) => $auth->showCheckEmail($req));
$router->post('/auth/resend-verification', fn($req) => $auth->resendVerification($req));
$router->get('/auth/verify',               fn($req) => $auth->verify($req));

// Password reset
$router->get('/auth/forgot-password',  fn($req) => $auth->forgotPasswordForm($req));
$router->post('/auth/forgot-password', fn($req) => $auth->forgotPassword($req));
$router->get('/auth/reset-link-sent',  fn($req) => $auth->showResetLinkSent($req));
$router->post('/auth/resend-reset',    fn($req) => $auth->resendPasswordReset($req));
$router->get('/auth/reset-password',   fn($req) => $auth->resetPasswordForm($req));
$router->post('/auth/reset-password',  fn($req) => $auth->resetPassword($req));
$router->get('/terms-of-service',    fn($req) => \CVS\Core\Response::view('terms-of-service'));
$router->get('/privacy-policy',      fn($req) => \CVS\Core\Response::view('privacy-policy'));
$router->get('/alerts/unsubscribe',  fn($req) => $alertCtrl->unsubscribe($req));

// ------------------------------------------------------------------
// Protected routes (auth middleware applied inside controllers)
// ------------------------------------------------------------------

$router->get('/dashboard',              fn($req) => $analysis->dashboard($req));
$router->post('/analysis',             fn($req) => $analysis->analyse($req));
$router->get('/analysis/{ticker}',     fn($req) => $analysis->show($req));

// ------------------------------------------------------------------
// Watchlist (S-06)
// ------------------------------------------------------------------

$router->post('/watchlist/toggle',     fn($req) => $watchlist->toggle($req));

// ------------------------------------------------------------------
// Model documentation (public)
// ------------------------------------------------------------------

$router->get('/model', function ($req) {
    $translationRepo  = new \CVS\Translation\TranslationRepository();
    $cachedModelPageEn = $translationRepo->find('_MODEL_PAGE', 'en', 'model_page');

    \CVS\Core\Response::view('model', [
        'cachedModelPageEn' => $cachedModelPageEn, // on-device translation cache (JSON array)
    ]);
});

// ------------------------------------------------------------------
// Styleguide (F-01)
// ------------------------------------------------------------------

$router->get('/styleguide', function ($req) {
    AuthController::requireAuth();
    \CVS\Core\Response::view('styleguide');
});

// ------------------------------------------------------------------
// PRO access (F-05)
// ------------------------------------------------------------------

$router->get('/admin/pro',                fn($req) => $pro->index($req));
$router->post('/admin/pro',               fn($req) => $pro->store($req));
$router->post('/admin/pro/revoke',        fn($req) => $pro->revoke($req));
$router->post('/admin/pro/activate-code', fn($req) => $pro->activateCode($req));

// ------------------------------------------------------------------
// Admin: Sectors (admin-sector-refresh)
// ------------------------------------------------------------------

$router->get('/sectors',                fn($req) => $sectors->publicIndex($req));
$router->get('/sectors/history',        fn($req) => $sectors->publicHistory($req));
$router->get('/admin/sectors',          fn($req) => $sectors->index($req));
$router->get('/admin/sectors/history',  fn($req) => $sectors->history($req));
$router->post('/admin/sectors/refresh', fn($req) => $sectors->refresh($req));

// ------------------------------------------------------------------
// Admin: Tickers universe
// ------------------------------------------------------------------

$router->get('/admin/tickers',      fn($req) => $tickersAdmin->index($req));
$router->post('/admin/tickers/add', fn($req) => $tickersAdmin->add($req));
$router->post('/pro/activate',            fn($req) => $pro->activate($req));
$router->post('/pro/request',             fn($req) => $pro->sendRequest($req));

// ------------------------------------------------------------------
// AI Analysis (S-01)
// ------------------------------------------------------------------

$router->post('/analysis/{ticker}/generate-ai',  fn($req) => $aiAnalysis->generate($req));
$router->post('/analysis/{ticker}/share-prompt', fn($req) => $aiAnalysis->sharePrompt($req));

// Recenzja krytyczna (etap 2) — change: cvs-ai-critical-review
$router->post('/analysis/{ticker}/critical-review',        fn($req) => $aiAnalysis->criticalReview($req));
$router->get('/analysis/{ticker}/critical-review/status',  fn($req) => $aiAnalysis->criticalReviewStatus($req));

// ------------------------------------------------------------------
// Track Record (S-02)
// ------------------------------------------------------------------

$router->get('/track-record',          fn($req) => $trackRecord->index($req));
$router->get('/track-record/{ticker}', fn($req) => $trackRecord->show($req));

// ------------------------------------------------------------------
// Screener (S-03)
// ------------------------------------------------------------------

$router->get('/screener', fn($req) => $screener->index($req));

// Right-click "favourite links" context menu (change: cvs-screener-ticker-links)
$tickerLinks = new TickerLinkController();
$router->post('/screener/links/add',    fn($req) => $tickerLinks->add($req));
$router->post('/screener/links/delete', fn($req) => $tickerLinks->delete($req));

// ------------------------------------------------------------------
// Virtual Portfolio (S-01)
// ------------------------------------------------------------------

$portfolio = new PortfolioController();
$router->get('/portfolio',         fn($req) => $portfolio->index($req));
$router->get('/portfolio/history', fn($req) => $portfolio->history($req));

// ------------------------------------------------------------------
// Lab — experimental paper portfolios (change: cvs-experimental-portfolios)
// ------------------------------------------------------------------

$lab = new LabController();
$router->get('/lab', fn($req) => $lab->index($req));

// ------------------------------------------------------------------
// Watchlist Alerts (S-04)
// ------------------------------------------------------------------

$router->post('/alerts/global', fn($req) => $alertCtrl->toggleGlobal($req));
$router->post('/alerts/ticker', fn($req) => $alertCtrl->toggleTicker($req));
$router->post('/alerts/price',  fn($req) => $alertCtrl->togglePrice($req));

// ------------------------------------------------------------------
// On-device translation cache (Chrome Translator API / Built-in AI)
// ------------------------------------------------------------------

$router->post('/api/translation/save', fn($req) => $translation->save($req));
