<?php

declare(strict_types=1);

/**
 * Manual production test: sends a real preview of the CVS alert email(s) for a
 * ticker to an arbitrary address, using the latest real snapshot/zone data.
 * Does NOT go through the rescore/price-alert cron and touches no dedup state
 * (alert_sent, price_alert) — safe to run any time, as many times as needed.
 *
 * Usage:
 *   php82 bin/send_test_mail.php <TICKER> <email> [signal|price|both]
 *
 * "signal"  — state-change alert template (AlertService), from the latest
 *             cvs_snapshots row for the live model version.
 * "price"   — price-zone alert template (PriceAlertService), from the latest
 *             ticker_zone row + a live price fetch.
 * "both"    — default; sends whichever of the two has data available.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/vendor/autoload.php';

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

$_SESSION = [];

$ticker = isset($argv[1]) ? strtoupper(trim($argv[1])) : null;
$email  = $argv[2] ?? null;
$type   = $argv[3] ?? 'both';

if ($ticker === null || $email === null) {
    fwrite(STDERR, "Usage: php bin/send_test_mail.php <TICKER> <email> [signal|price|both]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address: {$email}\n");
    exit(1);
}
if (!in_array($type, ['signal', 'price', 'both'], true)) {
    fwrite(STDERR, "Invalid type '{$type}' — must be signal, price, or both.\n");
    exit(1);
}

use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Alerts\PriceAlertRepository;
use CVS\Alerts\PriceAlertService;
use CVS\Api\FinancialDataFetcher;
use CVS\Auth\UserRepository;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;

$config        = require ROOT_PATH . '/config/cvs-weights.php';
$mailConfig    = require ROOT_PATH . '/config/mail.php';
$trajectoryCfg = is_array($config['trajectory'] ?? null) ? $config['trajectory'] : [];
$liveVersion   = (string) ($config['model_version'] ?? '');

$mail      = new MailService(null, $mailConfig);
$snapshots = new CvsSnapshotRepository();
$users     = new UserRepository();
$zoneRepo  = new PriceAlertRepository();

$exitOk = true;

if ($type === 'signal' || $type === 'both') {
    $zone     = $zoneRepo->findZone($ticker);
    $alertSvc = new AlertService(new AlertRepository(), $mail, $users, $snapshots, $trajectoryCfg);
    $sent     = $alertSvc->sendPreviewMail($ticker, $email, $liveVersion, null, null, $zone);
    echo 'signal-change preview: ' . ($sent ? "OK -> {$email}" : "FAILED (no cvs_snapshots row for {$ticker}/{$liveVersion}?)") . "\n";
    $exitOk = $exitOk && $sent;
}

if ($type === 'price' || $type === 'both') {
    $priceSvc = new PriceAlertService(
        $zoneRepo,
        new FinancialDataFetcher($config['data_source']),
        $mail,
        $users,
        $snapshots,
        [],
        $liveVersion,
        $trajectoryCfg
    );
    $sent = $priceSvc->sendPreviewMail($ticker, $email);
    echo 'price-zone preview:    ' . ($sent ? "OK -> {$email}" : "FAILED (no ticker_zone row or live price fetch failed for {$ticker}?)") . "\n";
    $exitOk = $exitOk && $sent;
}

exit($exitOk ? 0 : 1);
