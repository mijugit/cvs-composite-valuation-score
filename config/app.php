<?php

declare(strict_types=1);

return [
    'name'     => 'CVS — Composite Valuation Score',
    'env'      => $_ENV['APP_ENV'] ?? 'production',
    'debug'    => ($_ENV['APP_ENV'] ?? 'production') === 'development',
    'base_url' => $_ENV['APP_URL'] ?? 'http://localhost',

    'db' => [
        'driver'   => 'mysql',
        'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port'     => $_ENV['DB_PORT'] ?? '3306',
        'name'     => $_ENV['DB_NAME'] ?? 'cvs_db',
        'user'     => $_ENV['DB_USER'] ?? 'root',
        'password' => $_ENV['DB_PASS'] ?? '',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'     => 'cvs_session',
        // Sliding: every request pushes expiry forward, so only this much
        // INACTIVITY logs a user out. Long enough for a monitoring bot.
        'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 604800), // seconds (7 days)
        // Dedicated directory: the shared default save_path is swept by the
        // host's own GC (php.ini gc_maxlifetime, often 24 min), which would
        // delete our files long before the cookie expires.
        'save_path' => dirname(__DIR__) . '/var/sessions',
    ],
];
