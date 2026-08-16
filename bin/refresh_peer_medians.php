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

$logFile = ROOT_PATH . '/logs/refresh_peer_medians.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('refresh_peer_medians: start');

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
use CVS\CVS\CVSModel;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\CVS\Valuation\ValuationMetrics;
use CVS\Core\Database;
use CVS\CVS\Valuation\PeerBucketOverrideRepository;
use CVS\TrackRecord\CorpusScorer;
use CVS\TrackRecord\SnapshotWriter;

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
        $log(sprintf(
            'refresh_peer_medians: unknown sector "%s" — valid sectors: %s',
            $forceSector,
            implode(', ', $allScheduledSectors)
        ));
        exit(1);
    }
    $todaysSectors = [$forceSector];
    $log(sprintf('refresh_peer_medians: manual override — processing sector: %s', $forceSector));
} else {
    $dayOfWeek     = (int) date('N'); // 1=Mon … 7=Sun
    $todaysSectors = $schedule[$dayOfWeek] ?? [];

    if (empty($todaysSectors)) {
        $log(sprintf('refresh_peer_medians: day %d has no sectors scheduled — nothing to do.', $dayOfWeek));
        exit(0);
    }

    $log(sprintf(
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
    $log('refresh_peer_medians: tickers.json not found — aborting.');
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

$totalSuccess      = 0;
$totalFailed       = 0;
$totalSkipped      = 0;
$totalSaved        = 0;
$totalScored       = 0; // Phase 7 (slice 1): corpus snapshots persisted (FR-001)
$totalGateFailed   = 0; // Phase 7 (slice 1): skipped — quality gate failed (plan-review F1)
$totalCorpusFailed = 0; // Phase 7 (slice 1): scoring/persist errors (skip-per-error)

foreach ($todaysSectors as $targetSector) {

    /**
     * Accumulator for this sector's tickers.
     * buckets[level][bucket_key][metric] = float[]
     * @var array<string, array<string, array<string, float[]|string>>> $buckets
     */
    $buckets = [];
    $peerOverrides = (new PeerBucketOverrideRepository())->findBucketMap();

    // --- Fetch all tickers for this sector ---
    foreach ($allTickers as $entry) {
        $ticker = strtoupper(trim($entry['symbol'] ?? ''));
        if ($ticker === '') {
            continue;
        }

        $financials = $fetcher->fetch($ticker);

        if ($financials === null) {
            $log(sprintf('refresh_peer_medians: fetch failed for %s — skipping', $ticker));
            $totalFailed++;
            continue;
        }

        $sector   = $financials['sector']   ?? null;
        $industry = $financials['industry'] ?? null;

        // Admin-defined peer group (migration 037). The crawl MUST bucket by the
        // same key the Valuation pillar will benchmark against, or a custom group
        // would never accumulate a median and the override would resolve to
        // nothing. Yahoo's own industry is not written anywhere here — buckets
        // are derived, not stored per ticker — so this stays additive.
        $ovr = $peerOverrides[strtoupper($ticker)] ?? null;
        if ($ovr !== null && $ovr !== '') {
            $industry = $ovr;
            $financials['peer_bucket_override'] = $ovr;
        }

        if ($sector !== $targetSector) {
            $totalSkipped++;
            continue;
        }

        $bm = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? null;
        if ($bm === null) {
            $totalSkipped++;
            continue;
        }

        // Price/book needs no growth estimate, so it is collected BEFORE the
        // growth gate below. Banks frequently have no usable forward growth
        // figure, and gating their book multiple behind one would leave the
        // financial buckets permanently empty — the exact failure that left
        // "Banks - Regional" at n=0 while holding six large US banks.
        $pb = isset($financials['price_to_book']) && (float) $financials['price_to_book'] > 0
            ? (float) $financials['price_to_book']
            : null;
        if ($pb !== null) {
            $buckets['sector'][$sector]['pb'][] = $pb;
            if ($industry !== null) {
                $buckets['industry'][$industry]['pb'][]    = $pb;
                $buckets['industry'][$industry]['_sector'] = $sector;
            }

            // P/B per unit of ROE — what variant C actually compares, because a
            // bank's book multiple is a function of the return that earns it.
            // Collected here, beside pb and ahead of the growth gate, for the
            // same reason: banks often have no forward growth estimate.
            $roeRaw = isset($financials['return_on_equity']) ? (float) $financials['return_on_equity'] : null;
            if ($roeRaw !== null && $roeRaw > 0.0) {
                $pbRoe = $pb / $roeRaw;
                $buckets['sector'][$sector]['pb_roe'][] = $pbRoe;
                if ($industry !== null) {
                    $buckets['industry'][$industry]['pb_roe'][] = $pbRoe;
                }
            }
        }

        // EV/EBITDA, like price/book above, is collected BEFORE the growth gate.
        // It needs no growth estimate, and gating it behind one would leave the
        // real-estate buckets thin for the same reason the financial ones were
        // empty. Variant D reads these.
        $evForEbitda = ValuationMetrics::enterpriseValue($financials);
        $ebitdaRaw   = isset($financials['ebitda']) ? (float) $financials['ebitda'] : null;
        if ($evForEbitda !== null && $ebitdaRaw !== null && $ebitdaRaw > 0.0) {
            $evEbitda = $evForEbitda / $ebitdaRaw;
            if ($evEbitda > 0.0) {
                $buckets['sector'][$sector]['ev_ebitda'][] = $evEbitda;
                if ($industry !== null) {
                    $buckets['industry'][$industry]['ev_ebitda'][] = $evEbitda;
                    $buckets['industry'][$industry]['_sector']     = $sector;
                }
            }
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

        // Phase 7 (slice 1): calibration-corpus snapshot piggyback (FR-001) —
        // reuse the already-fetched $financials; zero extra Yahoo calls. Each
        // target-sector ticker gets one corpus snapshot on its sector's day.
        //
        // Fresh DB connection per write: target-sector hits can be minutes apart
        // (the loop fetches ALL tickers and skips non-target ones), which idles
        // past CF's ~60-120 s wait_timeout — and CvsSnapshotRepository::save()
        // swallows PDOExceptions, so a stale singleton would silently drop rows.
        // Reconnect costs ~ms against a ~2 s Yahoo fetch. CVSModel is built after
        // the reconnect so its MedianResolver also gets the fresh connection.
        // Errors never break the crawl (skip-per-error, same as fetch failures).
        try {
            Database::reconnect();
            $scorer  = new CorpusScorer(new CVSModel($config), new SnapshotWriter());
            $written = $scorer->scoreAndPersist($ticker, $financials);
            if ($written > 0) {
                $totalScored++;
            } else {
                $totalGateFailed++;
            }
        } catch (\Throwable $e) {
            $log(sprintf('refresh_peer_medians: corpus snapshot failed for %s — %s', $ticker, $e->getMessage()));
            $totalCorpusFailed++;
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

            foreach (['ev_fcf', 'ev_sales', 'gm', 'pb', 'ev_ebitda', 'pb_roe'] as $metric) {
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

    $log(sprintf(
        'refresh_peer_medians: sector "%s" flushed — buckets: %d industry, %d sector',
        $targetSector,
        isset($buckets['industry']) ? count($buckets['industry']) : 0,
        isset($buckets['sector'])   ? count($buckets['sector'])   : 0
    ));
}

$log(sprintf(
    'refresh_peer_medians: done — fetched=%d failed=%d skipped=%d median_rows_saved=%d corpus_scored=%d corpus_gate_failed=%d corpus_errors=%d version=%s',
    $totalSuccess,
    $totalFailed,
    $totalSkipped,
    $totalSaved,
    $totalScored,
    $totalGateFailed,
    $totalCorpusFailed,
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
