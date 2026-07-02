<?php

declare(strict_types=1);

/**
 * Phase 8 slice 3: light price-alert checker.
 *
 * Reads active "price in zone" alerts, fetches the current price per ticker (one
 * light chart call each — no quoteSummary), and emails users on out→in transitions.
 * Zone bounds come from ticker_zone (written by bin/rescore.php). Hourly cron in the
 * US session window; safe to run repeatedly (hysteresis state in price_alert).
 *
 * Cron entry (Cyber_Folks, "Ścieżka" type, US session window, mon–fri):
 *   0 14-22 * * 1-5  /usr/local/bin/php84 /home/.../bin/check_price_alerts.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

// Write to an explicit log file so cron output redirect stays in sync.
// error_log() goes to PHP system log (invisible in cron redirect files).
$logFile = ROOT_PATH . '/logs/price_alerts.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('check_price_alerts: start');

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same logic as bin/rescore.php).
$envFile = ROOT_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}

// FinancialDataFetcher uses $_SESSION for its in-process cache; CLI has none.
$_SESSION = [];

$config     = require ROOT_PATH . '/config/cvs-weights.php';
$mailConfig = require ROOT_PATH . '/config/mail.php';

use CVS\Alerts\PriceAlertRepository;
use CVS\Alerts\PriceAlertService;
use CVS\Api\FinancialDataFetcher;
use CVS\Auth\UserRepository;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;

try {
    $service = new PriceAlertService(
        new PriceAlertRepository(),
        new FinancialDataFetcher($config['data_source']),
        new MailService(null, $mailConfig),
        new UserRepository(),
        new CvsSnapshotRepository(),
        is_array($config['price_alert'] ?? null) ? $config['price_alert'] : [],
        (string) ($config['model_version'] ?? ''),
        is_array($config['trajectory'] ?? null) ? $config['trajectory'] : []
    );

    $sent = $service->checkAndNotify();
    $log(sprintf('check_price_alerts: done — sent=%d', $sent));
} catch (Throwable $e) {
    $log(sprintf('check_price_alerts: ERROR — %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

exit(0);
