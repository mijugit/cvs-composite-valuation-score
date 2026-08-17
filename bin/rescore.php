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

use CVS\Ai\FairPriceCalculator;
use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Alerts\PriceAlertRepository;
use CVS\Api\FinancialDataFetcher;
use CVS\Api\PayloadCompleteness;
use CVS\Auth\UserRepository;
use CVS\Execution\AtrZoneCalculator;
use CVS\CVS\CVSModel;
use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerBucketOverrideRepository;
use CVS\Mail\MailService;
use CVS\Screener\TickerIdentity;
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

// Built once, outside the loop: fair value must resolve its EV/FCF benchmark
// through the same peer-group ladder ValuationPillar uses, or the two disagree
// in adjacent screener columns.
$medianResolver = MedianResolver::fromConfig($config);

// Admin-defined peer groups (migration 037), read once for the whole run.
// Injected per ticker below; the pillar swaps the benchmark bucket only and
// leaves Yahoo's industry on the snapshot untouched.
$peerOverrides = (new PeerBucketOverrideRepository())->findBucketMap();

// Names we believe each symbol stands for, read from the universe file the
// admin screen maintains. Used only to notice when a symbol stops meaning what
// it used to: `GOLD` was reassigned from Barrick (which moved to `B`) to
// Gold.com, Inc., and the model happily scored the new company under the old
// name for as long as the row sat there. Every layer trusted the symbol, so the
// returned company name is the only witness. Missing file = check disabled, not
// a run failure.
/** @var array<string, string> $universeNames ticker => name we expect */
$universeNames = [];
$universeFile  = ROOT_PATH . '/public/data/tickers.json';
if (is_readable($universeFile)) {
    $parsed = json_decode((string) file_get_contents($universeFile), true);
    if (is_array($parsed)) {
        foreach ($parsed as $row) {
            if (is_array($row) && isset($row['symbol'], $row['name'])) {
                $universeNames[strtoupper((string) $row['symbol'])] = (string) $row['name'];
            }
        }
    }
}

/** @var list<string> $driftWarnings */
$driftWarnings = [];

// Three outcomes, counted separately. The old pair (success/failed) incremented
// `failed` only when fetch() returned null, so a Quality Gate REJECTION counted
// as a success — MU was rejected five times a day for four days while every run
// logged "success=103 failed=0". A ticker silently dropping out of the scored
// universe has to be visible in the first line of the log, not three weeks later.
$scored   = 0; // gate passed, snapshot written
$rejected = 0; // fetched and scored, but the Quality Gate said no
$skipped  = 0; // no usable payload — nothing written, last good snapshot kept

/** @var list<string> $rejectedTickers */
$rejectedTickers = [];
/** @var list<string> $skippedTickers */
$skippedTickers  = [];

foreach ($tickers as $ticker) {
    $financials = $fetcher->fetch($ticker);

    if ($financials === null) {
        $log(sprintf('rescore: fetch failed for %s — skipping', $ticker));
        $skipped++;
        $skippedTickers[] = $ticker;
        continue;
    }

    // Structurally-fine-but-empty payload (Yahoo 200 with an empty income
    // statement). Treated exactly like a failed fetch: log and skip WITHOUT
    // persisting, so the ticker keeps its last good snapshot instead of having
    // it masked by a scoreless newer row. See PayloadCompleteness for why a
    // written-but-empty snapshot is worse than no snapshot at all.
    $missing = PayloadCompleteness::missingEssentialFields($financials);
    if ($missing !== []) {
        $log(sprintf(
            'rescore: incomplete payload for %s (missing: %s) — skipping, last good snapshot preserved',
            $ticker,
            implode(', ', $missing)
        ));
        $skipped++;
        $skippedTickers[] = $ticker;
        continue;
    }

    // Identity check. Deliberately does NOT skip the ticker: a name mismatch is
    // a question for the operator (repoint or drop?), not a fact the job may act
    // on, and guessing wrong would silently remove a position.
    $expectedName = $universeNames[strtoupper($ticker)] ?? null;
    if ($expectedName !== null) {
        $warning = TickerIdentity::driftWarning(
            $ticker,
            $expectedName,
            isset($financials['long_name']) ? (string) $financials['long_name'] : null
        );
        if ($warning !== null) {
            $log('rescore: UWAGA — ' . $warning);
            $driftWarnings[] = $ticker;
        }
    }

    $ovr = $peerOverrides[strtoupper($ticker)] ?? null;
    if ($ovr !== null && $ovr !== '') {
        $financials['peer_bucket_override'] = $ovr;
    }

    $result         = $model->calculate($ticker, $financials);
    $price          = isset($financials['current_price'])  ? (float)  $financials['current_price']  : null;
    $sector         = isset($financials['sector'])         ? (string) $financials['sector']         : null;
    $industry       = isset($financials['industry'])       ? (string) $financials['industry']       : null;
    $companyName    = isset($financials['long_name'])      ? (string) $financials['long_name']      : null;
    $fxRateToUsd    = isset($financials['fx_rate_to_usd']) ? (float)  $financials['fx_rate_to_usd'] : null;
    $nativeCurrency = isset($financials['native_currency']) ? (string) $financials['native_currency'] : null;
    $nativePrice    = isset($financials['native_price'])   ? (float)  $financials['native_price']   : null;

    // Screener FV column: same $financials already fetched above for scoring —
    // zero extra Yahoo calls. Returns null when inputs are missing/out of the
    // 0.05x-10x sanity band (FairPriceCalculator's own guard).
    $fairValue = FairPriceCalculator::compute($financials, $config, $medianResolver);

    // Base (4.0) + shadow (3.1/3.2) rows in one call — shadow mode (FR-016/FR-019).
    // FX fields propagate to every version row (same stock, same point in time).
    $writer->persist($result, $price, $sector, $industry, CvsSnapshotRepository::ORIGIN_RESCORE, $companyName, $fxRateToUsd, $nativeCurrency, $nativePrice, $fairValue);

    // Phase 8 (slice 3): cache the ATR entry zone per ticker (from already-fetched
    // daily OHLC — zero extra Yahoo calls) so the light price-alert cron can read it.
    $zone = null;
    if ($price !== null && !empty($financials['daily_ohlc'])) {
        $zone = AtrZoneCalculator::compute($financials['daily_ohlc'], $price, $atrZonesCfg);
        if ($zone['has_zone']) {
            $priceAlertRepo->upsertZone($ticker, $zone, $fxRateToUsd);
        }
    }

    // S-04: check for state change and queue a digest row for watching users.
    // companyName/price/zone are already computed above (zero extra fetches) —
    // enrich the alert mail beyond the bare reco/signal change. Nothing is
    // sent yet — flushDigests() below sends one email per user after the
    // whole ticker loop finishes, batching every change from this run.
    $queued = $alertSvc->checkAndNotify($ticker, $result->toArray(), $companyName, $price, $zone);
    if ($queued > 0) {
        $log(sprintf('rescore: alert queued for %s for %d user(s)', $ticker, $queued));
    }

    if ($result->qualityGatePassed) {
        $scored++;
    } else {
        $rejected++;
        $rejectedTickers[] = $ticker;
    }
}

// S-04: send the batched per-user digests now that every ticker in this run
// has been checked — one email per affected user instead of one per ticker.
$digestsSent = $alertSvc->flushDigests();
if ($digestsSent > 0) {
    $log(sprintf('rescore: sent %d digest email(s)', $digestsSent));
}

$log(sprintf(
    'rescore: done — scored=%d rejected=%d skipped=%d total=%d',
    $scored,
    $rejected,
    $skipped,
    count($tickers)
));

// Name them. A count alone still leaves you grepping to find out WHICH ticker
// left the scored universe — the question that took three weeks to ask last time.
if ($rejectedTickers !== []) {
    $log('rescore: rejected by quality gate — ' . implode(', ', $rejectedTickers));
}
if ($skippedTickers !== []) {
    $log('rescore: skipped, no usable payload — ' . implode(', ', $skippedTickers));
}
if ($driftWarnings !== []) {
    $log(
        'rescore: tickery do sprawdzenia (nazwa nie zgadza się z giełdą) — '
        . implode(', ', $driftWarnings)
    );
}

exit(0);
