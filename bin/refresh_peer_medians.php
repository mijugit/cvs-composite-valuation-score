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

// ------------------------------------------------------------------
// Resolve which sectors to process today
// ------------------------------------------------------------------

$dayOfWeek   = (int) date('N'); // 1=Mon … 7=Sun
$schedule    = $config['batch_schedule'] ?? [];
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
// Fetch & accumulate metrics per ticker
// ------------------------------------------------------------------

$fetcher      = new FinancialDataFetcher($config['data_source']);
$medianRepo   = new PeerMedianRepository();
$modelVersion = $config['model_version'] ?? '3.0';

/**
 * Accumulator: buckets[level][bucket_key] = ['ev_fcf' => float[], 'ev_sales' => float[], 'gm' => float[]]
 * @var array<string, array<string, array<string, float[]>>> $buckets
 */
$buckets = [];

$success = 0;
$failed  = 0;
$skipped = 0;

foreach ($allTickers as $entry) {
    $ticker = strtoupper(trim($entry['symbol'] ?? ''));
    if ($ticker === '') {
        continue;
    }

    $financials = $fetcher->fetch($ticker);

    if ($financials === null) {
        error_log(sprintf('refresh_peer_medians: fetch failed for %s — skipping', $ticker));
        $failed++;
        continue;
    }

    $sector   = $financials['sector']   ?? null;
    $industry = $financials['industry'] ?? null;

    // Only process tickers whose sector is in today's schedule.
    if ($sector === null || !in_array($sector, $todaysSectors, true)) {
        $skipped++;
        continue;
    }

    // Forward growth (capped to sector max_growth).
    $benchmarks = $config['benchmarks'] ?? [];
    $bm         = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? null;
    if ($bm === null) {
        $skipped++;
        continue;
    }

    $growthPct = ValuationMetrics::extractForwardGrowth($financials);
    if ($growthPct === null) {
        $skipped++;
        continue;
    }
    $growthPct = min($growthPct, (float) $bm['max_growth']);

    // EV/FCF (Variant A).
    $evFcf = ValuationMetrics::forwardEvFcf($financials, $growthPct);

    // Growth-adjusted EV/Sales (Variant B).
    $evSalesAdj = ValuationMetrics::forwardEvSalesAdjusted($financials, $growthPct);

    // Gross margin (fraction → %).
    $gmFrac = isset($financials['gross_margins']) ? (float) $financials['gross_margins'] : null;
    $gmPct  = $gmFrac !== null ? $gmFrac * 100.0 : null;

    // Accumulate at sector level (anchor/fallback).
    if ($evFcf !== null) {
        $buckets['sector'][$sector]['ev_fcf'][] = $evFcf;
    }
    if ($evSalesAdj !== null) {
        $buckets['sector'][$sector]['ev_sales'][] = $evSalesAdj;
    }
    if ($gmPct !== null) {
        $buckets['sector'][$sector]['gm'][] = $gmPct;
    }

    // Accumulate at industry level (subsector peer-group).
    if ($industry !== null) {
        if ($evFcf !== null) {
            $buckets['industry'][$industry]['ev_fcf'][]   = $evFcf;
            $buckets['industry'][$industry]['_sector']    = $sector; // track parent
        }
        if ($evSalesAdj !== null) {
            $buckets['industry'][$industry]['ev_sales'][] = $evSalesAdj;
            $buckets['industry'][$industry]['_sector']    = $sector;
        }
        if ($gmPct !== null) {
            $buckets['industry'][$industry]['gm'][]       = $gmPct;
            $buckets['industry'][$industry]['_sector']    = $sector;
        }
    }

    $success++;
}

// ------------------------------------------------------------------
// Compute medians and persist
// ------------------------------------------------------------------

$saved = 0;

foreach ($buckets as $level => $levelBuckets) {
    foreach ($levelBuckets as $bucketKey => $data) {
        if (str_starts_with($bucketKey, '_')) {
            continue; // skip internal metadata keys like '_sector'
        }

        $parentSector = $level === 'industry' ? ($data['_sector'] ?? null) : null;

        foreach (['ev_fcf', 'ev_sales', 'gm'] as $metric) {
            $values = $data[$metric] ?? [];
            if (empty($values)) {
                continue;
            }

            $median      = self_median($values);
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
            $saved++;
        }
    }
}

error_log(sprintf(
    'refresh_peer_medians: done — fetched=%d failed=%d skipped=%d median_rows_saved=%d version=%s',
    $success,
    $failed,
    $skipped,
    $saved,
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
function self_median(array $values): float
{
    sort($values);
    $n   = count($values);
    $mid = (int) floor($n / 2);

    if ($n % 2 === 0) {
        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    return $values[$mid];
}
