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

$logFile = ROOT_PATH . '/logs/rescore.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('rescore: start');

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

use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Alerts\PriceAlertRepository;
use CVS\Api\FinancialDataFetcher;
use CVS\Auth\UserRepository;
use CVS\Execution\AtrZoneCalculator;
use CVS\CVS\CVSModel;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\SnapshotWriter;
use CVS\Watchlist\WatchlistRepository;

$watchlist   = new WatchlistRepository();
// FinancialDataFetcher expects the data_source sub-array, not the full config
// (constructor reads max_tickers/cache_ttl/timeout_seconds at top level — passing
// the full config silently fell back to hardcoded defaults; plan-review F2).
$fetcher     = new FinancialDataFetcher($config['data_source']);
$model       = new CVSModel($config);
// Phase 7 (slice 1): base + shadow dual-write extracted into the shared
// SnapshotWriter (FR-002) — same logic, now reused by the peer-median crawl.
$writer      = new SnapshotWriter();
// Phase 8 (slice 3): per-ticker ATR zone cache for the price-alert cron.
$priceAlertRepo = new PriceAlertRepository();
$atrZonesCfg    = is_array($config['atr_zones'] ?? null) ? $config['atr_zones'] : [];

$mailConfig    = require ROOT_PATH . '/config/mail.php';
$trajectoryCfg = is_array($config['trajectory'] ?? null) ? $config['trajectory'] : [];
$alertSvc      = new AlertService(
    new AlertRepository(),
    new MailService(null, $mailConfig),
    new UserRepository(),
    new CvsSnapshotRepository(),
    $trajectoryCfg
);

try {
    $tickers = $watchlist->findAllDistinctTickers();
} catch (Throwable $e) {
    $log(sprintf('rescore: ERROR fetching watchlist — %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

if (count($tickers) === 0) {
    $log('rescore: watchlist union is empty — nothing to score');
    exit(0);
}

$success = 0;
$failed  = 0;

foreach ($tickers as $ticker) {
    $financials = $fetcher->fetch($ticker);

    if ($financials === null) {
        $log(sprintf('rescore: fetch failed for %s — skipping', $ticker));
        $failed++;
        continue;
    }

    $result         = $model->calculate($ticker, $financials);
    $price          = isset($financials['current_price'])  ? (float)  $financials['current_price']  : null;
    $sector         = isset($financials['sector'])         ? (string) $financials['sector']         : null;
    $industry       = isset($financials['industry'])       ? (string) $financials['industry']       : null;
    $companyName    = isset($financials['long_name'])      ? (string) $financials['long_name']      : null;
    $fxRateToUsd    = isset($financials['fx_rate_to_usd']) ? (float)  $financials['fx_rate_to_usd'] : null;
    $nativeCurrency = isset($financials['native_currency']) ? (string) $financials['native_currency'] : null;
    $nativePrice    = isset($financials['native_price'])   ? (float)  $financials['native_price']   : null;

    // Base (4.0) + shadow (3.1/3.2) rows in one call — shadow mode (FR-016/FR-019).
    // FX fields propagate to every version row (same stock, same point in time).
    $writer->persist($result, $price, $sector, $industry, CvsSnapshotRepository::ORIGIN_RESCORE, $companyName, $fxRateToUsd, $nativeCurrency, $nativePrice);

    // Phase 8 (slice 3): cache the ATR entry zone per ticker (from already-fetched
    // daily OHLC — zero extra Yahoo calls) so the light price-alert cron can read it.
    $zone = null;
    if ($price !== null && !empty($financials['daily_ohlc'])) {
        $zone = AtrZoneCalculator::compute($financials['daily_ohlc'], $price, $atrZonesCfg);
        if ($zone['has_zone']) {
            $priceAlertRepo->upsertZone($ticker, $zone, $fxRateToUsd);
        }
    }

    // S-04: check for state change and notify watching users.
    // companyName/price/zone are already computed above (zero extra fetches) —
    // enrich the alert mail beyond the bare reco/signal change.
    $alerted = $alertSvc->checkAndNotify($ticker, $result->toArray(), $companyName, $price, $zone);
    if ($alerted > 0) {
        $log(sprintf('rescore: alert sent for %s to %d user(s)', $ticker, $alerted));
    }

    $success++;
}

$log(sprintf(
    'rescore: done — success=%d failed=%d total=%d',
    $success,
    $failed,
    count($tickers)
));

exit(0);
