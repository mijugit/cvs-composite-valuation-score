<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/vendor/autoload.php';

// Load env from .env if present (manual implementation — no library dependency)
$envFile = ROOT_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$config = require ROOT_PATH . '/config/app.php';

// Start session
$sessionLifetime = $config['session']['lifetime'];
$sessionPath     = $config['session']['save_path'];
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0700, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
    // Hosts often ship gc_probability=0 and rely on a cron that only knows
    // the default path — without this our directory would never be swept.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
}
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
$isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$cookieParams = [
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
];
session_set_cookie_params($cookieParams);
session_name($config['session']['name']);
session_start();
// PHP sends the cookie only when it issues a new id, so its expiry would be
// fixed at login time. Re-send it to make the lifetime slide with activity.
if (!headers_sent()) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $sessionLifetime,
    ] + array_diff_key($cookieParams, ['lifetime' => true]));
}

// Boot router
$router = new CVS\Core\Router();
require ROOT_PATH . '/src/Core/routes.php';
$router->dispatch();
