<?php

declare(strict_types=1);

/**
 * Application route definitions.
 *
 * $router is injected by public/index.php before this file is required.
 * @var \CVS\Core\Router $router
 */

use CVS\Auth\AuthController;
use CVS\CVS\AnalysisController;
use CVS\Watchlist\WatchlistController;

$auth      = new AuthController();
$analysis  = new AnalysisController();
$watchlist = new WatchlistController();

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
// Styleguide (F-01)
// ------------------------------------------------------------------

$router->get('/styleguide', function ($req) {
    AuthController::requireAuth();
    \CVS\Core\Response::view('styleguide');
});
