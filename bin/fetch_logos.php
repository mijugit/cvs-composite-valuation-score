<?php

declare(strict_types=1);

/**
 * change: ticker-logo-cache
 *
 * One-shot server-side cache builder for company logos. For every ticker in
 * the universe (public/data/tickers.json) that does NOT yet have a row in
 * `ticker_logos`, resolves a domain — Yahoo's `website` field first (already
 * fetched for scoring, so this is free), logo.dev's Search API only as a
 * fallback when Yahoo has none — then downloads the logo bytes ONCE into
 * public/images/logos/ and records the outcome. Already-processed tickers
 * (found OR not_found) are skipped, so a normal run only touches newly added
 * tickers; the very first run naturally backfills the whole universe.
 *
 * Safe to run multiple times a day — fully idempotent per ticker.
 *
 * Cron entry (Cyber_Folks, "Ścieżka" type), daily:
 *   0 6 * * *  /usr/local/bin/php84 /home/.../bin/fetch_logos.php
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/fetch_logos.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('fetch_logos: start');

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same logic as public/index.php / bin/rescore.php).
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

// FinancialDataFetcher uses $_SESSION for its in-process cache. In CLI there
// is no session; initialising the array here lets the fetcher work normally
// while acting as a plain in-memory array for the run lifetime.
$_SESSION = [];

$weightsConfig = require ROOT_PATH . '/config/cvs-weights.php';
$logoConfig    = require ROOT_PATH . '/config/logo-dev.php';

use CVS\Api\FinancialDataFetcher;
use CVS\Logo\CurlLogoDevTransport;
use CVS\Logo\LogoDevClient;
use CVS\Logo\TickerLogoRepository;
use CVS\TrackRecord\CvsSnapshotRepository;

$fetcher   = new FinancialDataFetcher($weightsConfig['data_source']);
$logoDev   = new LogoDevClient($logoConfig, new CurlLogoDevTransport());
$logoRepo  = new TickerLogoRepository();

$logosDir = ROOT_PATH . '/public/images/logos';
if (!is_dir($logosDir)) {
    mkdir($logosDir, 0755, true);
}

// Universe of tickers to consider (admin-maintained, same file rescore.php
// reads for its own drift check).
/** @var list<string> $allTickers */
$allTickers = [];
$universeFile = ROOT_PATH . '/public/data/tickers.json';
if (is_readable($universeFile)) {
    $parsed = json_decode((string) file_get_contents($universeFile), true);
    if (is_array($parsed)) {
        foreach ($parsed as $row) {
            if (is_array($row) && isset($row['symbol'])) {
                $allTickers[] = strtoupper(trim((string) $row['symbol']));
            }
        }
    }
}

if ($allTickers === []) {
    $log('fetch_logos: ticker universe is empty — nothing to do');
    exit(0);
}

// Fallback-only source for Search API queries (Yahoo `website` is tried first
// per ticker below); a ticker with no scored snapshot yet simply has no
// fallback name and, absent a website, ends up not_found.
$companyNames = (new CvsSnapshotRepository())->latestCompanyNames();

// The skip-list: any ticker already resolved (found or not_found) in a
// previous run is never re-queried — this IS the mechanism that keeps this
// script from burning the logo.dev free-tier limit.
$existing = array_flip($logoRepo->existingTickers());

$pending = array_values(array_filter($allTickers, static fn($t) => !isset($existing[$t])));
$skipped = count($allTickers) - count($pending);

$found       = 0;
$notFound    = 0;
$errors      = 0;
/** @var list<string> $notFoundTickers */
$notFoundTickers = [];

foreach ($pending as $ticker) {
    if (!preg_match('/^[A-Z0-9.]{1,20}$/', $ticker)) {
        $log("fetch_logos: skipping malformed ticker symbol '{$ticker}'");
        $errors++;
        continue;
    }

    try {
        $domain = resolveDomain($ticker, $fetcher, $logoDev, $companyNames[$ticker] ?? null);

        if ($domain === null) {
            $logoRepo->upsert($ticker, null, null, 'not_found');
            $notFound++;
            $notFoundTickers[] = $ticker;
            continue;
        }

        $bytes = $logoDev->fetchImageBytes($domain);
        if ($bytes === null) {
            // Domain resolved but logo.dev has no image for it — keep the
            // domain on record (useful for a later manual look) but no path.
            $logoRepo->upsert($ticker, $domain, null, 'not_found');
            $notFound++;
            $notFoundTickers[] = $ticker;
            continue;
        }

        $logoPath = "/images/logos/{$ticker}.webp";
        file_put_contents($logosDir . "/{$ticker}.webp", $bytes);
        $logoRepo->upsert($ticker, $domain, $logoPath, 'found');
        $found++;
    } catch (Throwable $e) {
        $log(sprintf('fetch_logos: ERROR for %s — %s in %s:%d', $ticker, $e->getMessage(), $e->getFile(), $e->getLine()));
        $errors++;
    }
}

$log(sprintf(
    'fetch_logos: done — found=%d not_found=%d skipped=%d errors=%d total=%d',
    $found,
    $notFound,
    $skipped,
    $errors,
    count($allTickers)
));

if ($notFoundTickers !== []) {
    $log('fetch_logos: not_found this run — ' . implode(', ', $notFoundTickers));
}

exit(0);

/**
 * Domain resolution, in priority order: Yahoo's `website` field (already
 * fetched for scoring — free, accurate) first; logo.dev's Search API only
 * when Yahoo has none. Reversing this order defeats the entire point of
 * caching — it would burn the Search API quota on every ticker Yahoo
 * already told us the domain for.
 *
 * Yahoo's `website` is a full URL (e.g. "https://www.apple.com"), not a bare
 * domain — normalised via parse_url() + stripping a leading "www." before
 * it's usable as a logo.dev image-endpoint path segment.
 */
function resolveDomain(string $ticker, FinancialDataFetcher $fetcher, LogoDevClient $logoDev, ?string $companyName): ?string
{
    $financials = $fetcher->fetch($ticker);
    $website    = is_array($financials) && is_string($financials['website'] ?? null) ? $financials['website'] : null;

    if ($website !== null && $website !== '') {
        $host = parse_url($website, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            // No scheme in the stored value (e.g. "www.apple.com" with no
            // "https://") — parse_url() only populates HOST when a scheme
            // is present, so retry once with one prepended.
            $host = parse_url('https://' . $website, PHP_URL_HOST);
        }
        if (is_string($host) && $host !== '') {
            return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        }
    }

    if ($companyName === null || trim($companyName) === '') {
        return null;
    }

    return $logoDev->searchDomain($companyName);
}
