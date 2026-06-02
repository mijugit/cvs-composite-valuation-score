<?php

declare(strict_types=1);

/**
 * F-04: Daily CVS re-scorer.
 *
 * Re-scores the union of all users' watchlists and stores snapshots in
 * `cvs_snapshots`. Intended to be run by cron twice a day (after NYSE open
 * and after NYSE close).  Safe to run multiple times — idempotent per
 * (ticker, score_date).
 *
 * Cron entry (Cyber_Folks, "Ścieżka" type):
 *   0 15 * * 1-5  /usr/local/bin/php84 /home/.../bin/rescore.php
 *   0 22 * * 1-5  /usr/local/bin/php84 /home/.../bin/rescore.php
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same logic as public/index.php).
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

// FinancialDataFetcher uses $_SESSION for its in-process cache.
// In CLI there is no session; initialising the array here lets the fetcher
// work normally while acting as a plain in-memory array for the run lifetime.
$_SESSION = [];

$config = require ROOT_PATH . '/config/cvs-weights.php';

use CVS\Api\FinancialDataFetcher;
use CVS\CVS\CVSModel;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\Watchlist\WatchlistRepository;

$watchlist   = new WatchlistRepository();
$fetcher     = new FinancialDataFetcher($config);
$model       = new CVSModel($config);
$snapshots   = new CvsSnapshotRepository();

$tickers = $watchlist->findAllDistinctTickers();

if (count($tickers) === 0) {
    error_log('rescore: watchlist union is empty — nothing to score');
    exit(0);
}

$success = 0;
$failed  = 0;

foreach ($tickers as $ticker) {
    $financials = $fetcher->fetch($ticker);

    if ($financials === null) {
        error_log(sprintf('rescore: fetch failed for %s — skipping', $ticker));
        $failed++;
        continue;
    }

    $result = $model->calculate($ticker, $financials);
    $price  = isset($financials['current_price']) ? (float) $financials['current_price'] : null;
    $sector = isset($financials['sector'])        ? (string) $financials['sector']        : null;
    $snapshots->save($ticker, $result->toArray(), $price, $sector);
    $success++;
}

error_log(sprintf(
    'rescore: done — success=%d failed=%d total=%d date=%s',
    $success,
    $failed,
    count($tickers),
    (new DateTimeImmutable())->format('Y-m-d H:i:s')
));

exit(0);
