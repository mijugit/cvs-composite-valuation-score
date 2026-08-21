<?php

declare(strict_types=1);

/**
 * Background worker for fundamentals-validation — change: fundamentals-validation.
 *
 * Fired via `exec($cmd . ' &')` from FundamentalsValidationController::trigger() —
 * mirrors bin/generate_critical_review.php's exact shape (a web-search-enabled
 * Gemini call runs well past a synchronous PHP request's budget on CF). The
 * endpoint already validated admin+CSRF and called markPending() before firing
 * this process — this script only ever does the slow work and writes the
 * result back via markCompleted()/markFailed().
 *
 * Reads the requested field list from the run row itself (not recomputed here)
 * — the admin was shown the field list at trigger time; re-deriving it against
 * a possibly-changed live fetch would let the two disagree.
 *
 * Contract: php bin/validate_fundamentals.php <ticker> <userId> <mode: all|missing>
 *
 * Cron: none — invoked ad hoc by the controller, never scheduled.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/validate_fundamentals.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$ticker = trim((string) ($argv[1] ?? ''));
$userId = (int) ($argv[2] ?? 0);
$mode   = trim((string) ($argv[3] ?? ''));

if ($ticker === '' || $userId <= 0 || !in_array($mode, ['all', 'missing'], true)) {
    $log(sprintf('validate_fundamentals: ERROR invalid args — ticker=%s userId=%s mode=%s', $argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''));
    exit(1);
}

$ticker = strtoupper($ticker);

$log("validate_fundamentals: start ticker={$ticker} userId={$userId} mode={$mode}");

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

// FinancialDataFetcher uses $_SESSION for its in-process cache; no session in CLI.
$_SESSION = [];

$cvsConfig    = require ROOT_PATH . '/config/cvs-weights.php';
$geminiConfig = require ROOT_PATH . '/config/gemini.php';

use CVS\Ai\FundamentalsValidationRunRepository;
use CVS\Ai\FundamentalsValidationService;
use CVS\Api\FinancialDataFetcher;
use CVS\Api\FundamentalFieldRegistry;
use CVS\Api\MovingAverageCalculator;

$runRepo = new FundamentalsValidationRunRepository();

try {
    $run = $runRepo->findByTicker($ticker);
    if ($run === null || $run['status'] !== 'pending') {
        $log("validate_fundamentals: FAILED {$ticker} — no pending run found");
        exit(0);
    }

    /** @var list<string> $requestedFields */
    $requestedFields = json_decode((string) $run['requested_fields'], true);
    if (!is_array($requestedFields) || $requestedFields === []) {
        $runRepo->markFailed($ticker, 'Brak pól do zwalidowania.');
        $log("validate_fundamentals: FAILED {$ticker} — empty requested_fields");
        exit(0);
    }

    $fetcher    = new FinancialDataFetcher($cvsConfig['data_source']);
    $financials = $fetcher->fetch($ticker);
    if ($financials === null) {
        $runRepo->markFailed($ticker, 'Nie udało się pobrać danych rynkowych.');
        $log("validate_fundamentals: FAILED {$ticker} — fetch failed");
        exit(0);
    }

    $localFields = array_values(array_intersect($requestedFields, FundamentalFieldRegistry::LOCALLY_COMPUTED));
    $llmFields   = array_values(array_diff($requestedFields, FundamentalFieldRegistry::LOCALLY_COMPUTED));

    $diff  = [];
    $notes = [];

    // Locally-computed fields (moving_average_200 today) never touch Gemini —
    // pure math from a wider one-off daily-OHLC fetch, requested only here,
    // never on FinancialDataFetcher's default fetch() path.
    if (in_array('moving_average_200', $localFields, true)) {
        $currentPrice = isset($financials['current_price']) ? (float) $financials['current_price'] : null;
        $nativePrice  = isset($financials['native_price'])  ? (float) $financials['native_price']  : null;
        $priceFxRate  = ($currentPrice !== null && $nativePrice !== null && $nativePrice !== 0.0)
            ? $currentPrice / $nativePrice
            : 1.0;

        $wideOhlc = $fetcher->fetchDailyOhlc($ticker, '1y');
        $ma200    = MovingAverageCalculator::computeMa200($wideOhlc, $priceFxRate);

        $diff['moving_average_200'] = [
            'old'    => $financials['moving_average_200'] ?? null,
            'new'    => $ma200,
            'status' => $ma200 !== null ? 'validated' : 'checked_no_data',
        ];
    }

    $model = 'local';

    if ($llmFields !== []) {
        $sector  = isset($financials['sector']) ? (string) $financials['sector'] : '';
        $service = new FundamentalsValidationService($geminiConfig);
        $result  = $service->validate($ticker, $sector, $llmFields, $financials);

        if ($result->ok) {
            $diff  = array_merge($diff, $result->diff);
            $model = $result->model !== null && $result->model !== '' ? $result->model : 'gemini';
            if ($result->notes !== '') {
                $notes[] = $result->notes;
            }
        } elseif ($diff === []) {
            // Nothing local succeeded either — the whole run has nothing to show.
            $runRepo->markFailed($ticker, $result->failureMessage ?? 'Walidacja nie powiodła się.');
            $log(sprintf('validate_fundamentals: FAILED %s — %s', $ticker, $result->failureMessage ?? 'unknown'));
            exit(0);
        } else {
            $notes[] = 'Walidacja LLM nie powiodła się: ' . ($result->failureMessage ?? 'nieznany błąd.');
        }
    }

    $runRepo->markCompleted($ticker, $diff, implode(' ', $notes), $model);
    $log(sprintf('validate_fundamentals: done ticker=%s fields=%d model=%s', $ticker, count($diff), $model));
} catch (Throwable $e) {
    $runRepo->markFailed($ticker, 'Błąd wewnętrzny walidacji.');
    $log(sprintf('validate_fundamentals: FATAL %s — %s in %s:%d', $ticker, $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

exit(0);
