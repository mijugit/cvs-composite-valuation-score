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
        'lifetime' => 7200, // seconds
    ],
];
