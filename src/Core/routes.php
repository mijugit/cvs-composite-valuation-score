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

$auth        = new AuthController();
$analysis    = new AnalysisController();
$watchlist   = new WatchlistController();
$pro         = new ProController();
$sectors     = new SectorsController();
$tickersAdmin = new TickersController();
$trackRecord = new TrackRecordController();
$screener    = new ScreenerController();
$alertCtrl   = new AlertController();
$aiConfig   = require dirname(__DIR__, 2) . '/config/ai.php';
$aiAnalysis = new AiAnalysisController($aiConfig);

// ------------------------------------------------------------------
// Public routes
// ------------------------------------------------------------------

$router->get('/',          fn($req) => $auth->index($req));
$router->get('/login',     fn($req) => $auth->loginForm($req));
$router->post('/login',    fn($req) => $auth->login($req));
$router->get('/register',  fn($req) => $auth->registerForm($req));
$router->post('/register', fn($req) => $auth->register($req));
$router->get('/logout',    fn($req) => $auth->logout($req));

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
    \CVS\Core\Response::view('model');
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

$router->get('/admin/sectors',          fn($req) => $sectors->index($req));
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

$router->post('/analysis/{ticker}/generate-ai', fn($req) => $aiAnalysis->generate($req));

// ------------------------------------------------------------------
// Track Record (S-02)
// ------------------------------------------------------------------

$router->get('/track-record',          fn($req) => $trackRecord->index($req));
$router->get('/track-record/{ticker}', fn($req) => $trackRecord->show($req));

// ------------------------------------------------------------------
// Screener (S-03)
// ------------------------------------------------------------------

$router->get('/screener', fn($req) => $screener->index($req));

// ------------------------------------------------------------------
// Watchlist Alerts (S-04)
// ------------------------------------------------------------------

$router->post('/alerts/global', fn($req) => $alertCtrl->toggleGlobal($req));
$router->post('/alerts/ticker', fn($req) => $alertCtrl->toggleTicker($req));
