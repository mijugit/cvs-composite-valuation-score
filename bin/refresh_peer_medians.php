<?php

declare(strict_types=1);

/**
 * Phase 3: Peer-group median refresher (rolling weekly batch).
 *
 * Crawls the ticker population (public/data/tickers.json) for the sectors
 * assigned to today's day-of-week (config → batch_schedule), fetches
 * financials from Yahoo Finance, computes EV/FCF and EV/Sales per ticker,
 * then derives and stores empirical medians per (industry, sector) bucket.
 *
 * Design goals:
 *   - Idempotent: safe to re-run; upserts overwrite stale rows.
 *   - Rate-limit friendly: only one sector-group per day instead of all 477
 *     tickers at once.
 *   - Deterministic medians: stored in DB before scoring; ValuationPillar
 *     reads frozen values, never calls this script at request time.
 *   - Resilient DB connection: medianas are flushed per-sector so that a
 *     long Yahoo fetch loop does not trigger MySQL wait_timeout (CF shared
 *     hosting drops idle connections after ~60-120 s).
 *
 * Cron entry (Cyber_Folks, "Ścieżka" type, runs daily Mon–Sun):
 *   0 14 * * *  /usr/local/bin/php84 /home/.../bin/refresh_peer_medians.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same pattern as bin/rescore.php).
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
$_SESSION = [];

$config = require ROOT_PATH . '/config/cvs-weights.php';

use CVS\Api\FinancialDataFetcher;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\CVS\Valuation\ValuationMetrics;
use CVS\Core\Database;

// ------------------------------------------------------------------
// Resolve which sectors to process today (or --sector=X override)
// ------------------------------------------------------------------

$forceSector = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--sector=')) {
        $forceSector = trim(substr($arg, 9));
        break;
    }
}

$schedule = $config['batch_schedule'] ?? [];

if ($forceSector !== null) {
    /** @var list<string> $allScheduledSectors */
    $allScheduledSectors = array_values(array_unique(array_merge(...array_values($schedule))));
    if (!in_array($forceSector, $allScheduledSectors, true)) {
        error_log(sprintf(
            'refresh_peer_medians: unknown sector "%s" — valid sectors: %s',
            $forceSector,
            implode(', ', $allScheduledSectors)
        ));
        exit(1);
    }
    $todaysSectors = [$forceSector];
    error_log(sprintf('refresh_peer_medians: manual override — processing sector: %s', $forceSector));
} else {
    $dayOfWeek     = (int) date('N'); // 1=Mon … 7=Sun
    $todaysSectors = $schedule[$dayOfWeek] ?? [];

    if (empty($todaysSectors)) {
        error_log(sprintf('refresh_peer_medians: day %d has no sectors scheduled — nothing to do.', $dayOfWeek));
        exit(0);
    }

    error_log(sprintf(
        'refresh_peer_medians: day %d — processing sectors: %s',
        $dayOfWeek,
        implode(', ', $todaysSectors)
    ));
}

// ------------------------------------------------------------------
// Load ticker population
// ------------------------------------------------------------------

$tickersFile = ROOT_PATH . '/public/data/tickers.json';
if (!file_exists($tickersFile)) {
    error_log('refresh_peer_medians: tickers.json not found — aborting.');
    exit(1);
}

/** @var array<int, array{symbol: string, name: string}> $allTickers */
$allTickers = json_decode((string) file_get_contents($tickersFile), true) ?? [];

// ------------------------------------------------------------------
// Process one sector at a time and flush medians after each sector.
// This keeps the MySQL connection alive on CF shared hosting where
// wait_timeout can be as low as 60-120 s — flushing per sector ensures
// the connection is used every ~20-40 s at most.
// ------------------------------------------------------------------

$fetcher      = new FinancialDataFetcher($config['data_source']);
$modelVersion = $config['model_version'] ?? '3.0';
$benchmarks   = $config['benchmarks'] ?? [];

$totalSuccess = 0;
$totalFailed  = 0;
$totalSkipped = 0;
$totalSaved   = 0;

foreach ($todaysSectors as $targetSector) {

    /**
     * Accumulator for this sector's tickers.
     * buckets[level][bucket_key][metric] = float[]
     * @var array<string, array<string, array<string, float[]|string>>> $buckets
     */
    $buckets = [];

    // --- Fetch all tickers for this sector ---
    foreach ($allTickers as $entry) {
        $ticker = strtoupper(trim($entry['symbol'] ?? ''));
        if ($ticker === '') {
            continue;
        }

        $financials = $fetcher->fetch($ticker);

        if ($financials === null) {
            error_log(sprintf('refresh_peer_medians: fetch failed for %s — skipping', $ticker));
            $totalFailed++;
            continue;
        }

        $sector   = $financials['sector']   ?? null;
        $industry = $financials['industry'] ?? null;

        if ($sector !== $targetSector) {
            $totalSkipped++;
            continue;
        }

        $bm = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? null;
        if ($bm === null) {
            $totalSkipped++;
            continue;
        }

        $growthPct = ValuationMetrics::extractForwardGrowth($financials);
        if ($growthPct === null) {
            $totalSkipped++;
            continue;
        }
        $growthPct = min($growthPct, (float) $bm['max_growth']);

        $evFcf      = ValuationMetrics::forwardEvFcf($financials, $growthPct);
        $evSalesAdj = ValuationMetrics::forwardEvSalesAdjusted($financials, $growthPct);
        $gmFrac     = isset($financials['gross_margins']) ? (float) $financials['gross_margins'] : null;
        $gmPct      = $gmFrac !== null ? $gmFrac * 100.0 : null;

        // Sector-level bucket (anchor/fallback).
        if ($evFcf !== null)      { $buckets['sector'][$sector]['ev_fcf'][]  = $evFcf; }
        if ($evSalesAdj !== null) { $buckets['sector'][$sector]['ev_sales'][] = $evSalesAdj; }
        if ($gmPct !== null)      { $buckets['sector'][$sector]['gm'][]       = $gmPct; }

        // Industry-level bucket (peer-group).
        if ($industry !== null) {
            if ($evFcf !== null)      { $buckets['industry'][$industry]['ev_fcf'][]  = $evFcf; }
            if ($evSalesAdj !== null) { $buckets['industry'][$industry]['ev_sales'][] = $evSalesAdj; }
            if ($gmPct !== null)      { $buckets['industry'][$industry]['gm'][]       = $gmPct; }
            // Track parent sector for industry rows.
            $buckets['industry'][$industry]['_sector'] = $sector;
        }

        $totalSuccess++;
    }

    // --- Flush medians for this sector (reconnect if needed) ---
    // Re-instantiate repository here so we get a fresh PDO connection after
    // the potentially long Yahoo fetch loop.
    Database::reconnect();
    $medianRepo = new PeerMedianRepository();

    foreach ($buckets as $level => $levelBuckets) {
        foreach ($levelBuckets as $bucketKey => $data) {
            if (str_starts_with((string) $bucketKey, '_')) {
                continue;
            }

            $parentSector = ($level === 'industry') ? (string) ($data['_sector'] ?? '') : null;
            if ($parentSector === '') {
                $parentSector = null;
            }

            foreach (['ev_fcf', 'ev_sales', 'gm'] as $metric) {
                /** @var float[] $values */
                $values = array_filter(
                    (array) ($data[$metric] ?? []),
                    static fn($v): bool => is_float($v) || is_int($v)
                );
                if (empty($values)) {
                    continue;
                }

                $values      = array_values($values);
                $median      = batch_median($values);
                $sampleCount = count($values);

                $medianRepo->upsertMedian(
                    $level,
                    $bucketKey,
                    $parentSector,
                    $modelVersion,
                    $metric,
                    $median,
                    $sampleCount
                );
                $totalSaved++;
            }
        }
    }

    error_log(sprintf(
        'refresh_peer_medians: sector "%s" flushed — buckets: %d industry, %d sector',
        $targetSector,
        isset($buckets['industry']) ? count($buckets['industry']) : 0,
        isset($buckets['sector'])   ? count($buckets['sector'])   : 0
    ));
}

error_log(sprintf(
    'refresh_peer_medians: done — fetched=%d failed=%d skipped=%d median_rows_saved=%d version=%s',
    $totalSuccess,
    $totalFailed,
    $totalSkipped,
    $totalSaved,
    $modelVersion
));

exit(0);

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Compute the median of a non-empty float array.
 *
 * @param float[] $values
 */
function batch_median(array $values): float
{
    sort($values);
    $n   = count($values);
    $mid = (int) floor($n / 2);

    return ($n % 2 === 0)
        ? ($values[$mid - 1] + $values[$mid]) / 2.0
        : $values[$mid];
}
