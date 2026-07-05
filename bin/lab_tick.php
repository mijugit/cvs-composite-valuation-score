<?php

declare(strict_types=1);

/**
 * Lab — daily tick for the experimental portfolios (change: cvs-experimental-portfolios).
 *
 * Runs all 7 (P0-P6) deterministic paper portfolios one calendar day forward:
 * seed on first run, fill pending open-execution (P2) trades, apply stop-losses,
 * rebalance on the first NYSE session of the month, persist NAV. Must run AFTER
 * the evening rescore (22:00 UTC) so today's cvs_snapshots row carries a
 * close-ish price. Idempotent — safe to re-run the same day (see LabTickService).
 *
 * Cron entry (Cyber_Folks, "Ścieżka" type) — single fixed hour; the CF scheduler
 * does not support hour RANGES (lessons.md), and 30 minutes after rescore's
 * 22:00 UTC run gives it a buffer to finish:
 *   30 22 * * 1-5  /usr/local/bin/php84 /home/.../bin/lab_tick.php
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/lab_tick.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('lab_tick: start');

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same logic as bin/rescore.php / public/index.php).
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

// FinancialDataFetcher uses $_SESSION for its in-process cache; in CLI there is
// no session, so initialising the array lets it act as a plain in-memory cache
// for the run's lifetime (same workaround as bin/rescore.php).
$_SESSION = [];

$cvsConfig       = require ROOT_PATH . '/config/cvs-weights.php';
$portfolioConfig = require ROOT_PATH . '/config/portfolio.php'; // reused for MarketCalendar only (market hours + NYSE holidays)
$labConfig       = require ROOT_PATH . '/config/lab-portfolios.php';

use CVS\Alerts\PriceAlertRepository;
use CVS\Api\FinancialDataFetcher;
use CVS\Lab\LabRepository;
use CVS\Lab\LabTickService;
use CVS\Portfolio\MarketCalendar;

try {
    $fetcher   = new FinancialDataFetcher($cvsConfig['data_source']);
    $calendar  = new MarketCalendar($portfolioConfig);
    $service   = new LabTickService(
        new LabRepository(),
        $fetcher,
        new PriceAlertRepository(),
        $calendar,
        $labConfig,
        (string) $cvsConfig['model_version'],
        (string) $portfolioConfig['market']['timezone']
    );

    $summary = $service->run(new DateTimeImmutable());

    $log(sprintf(
        'lab_tick: done — seeded=%d rebalanced=%d stops=%d navs=%d errors=%d',
        $summary['seeded'],
        $summary['rebalanced'],
        $summary['stops'],
        $summary['navs'],
        count($summary['errors'])
    ));
    foreach ($summary['errors'] as $error) {
        $log('lab_tick: ERROR — ' . $error);
    }
} catch (Throwable $e) {
    $log(sprintf('lab_tick: FATAL — %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

exit(0);
