<?php

declare(strict_types=1);

/**
 * Background worker for the stage-2 "Recenzja krytyczna" feature — change:
 * cvs-ai-critical-review.
 *
 * Fired via `exec($cmd . ' &')` from AiAnalysisController::criticalReview() —
 * a real Claude call with web search enabled measured at 90-140s+, far beyond
 * a synchronous PHP request on CF (see plan.md's Phase 1 spike). The endpoint
 * already validated the PRO gate, logged usage, and called markPending()
 * before firing this process — this script only ever does the slow work and
 * writes the result back via markCompleted()/markFailed().
 *
 * No HTTP session access here (background CLI process) — ticker and userId
 * arrive as positional CLI arguments, not from $_SESSION.
 *
 * Contract: php bin/generate_critical_review.php <ticker> <userId>
 *
 * Cron: none — invoked ad hoc by the controller, never scheduled.
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/critical_review.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$ticker = trim((string) ($argv[1] ?? ''));
$userId = (int) ($argv[2] ?? 0);

if ($ticker === '' || $userId <= 0) {
    $log(sprintf('generate_critical_review: ERROR invalid args — ticker=%s userId=%s', $argv[1] ?? '', $argv[2] ?? ''));
    exit(1);
}

$ticker = strtoupper($ticker);

$log("generate_critical_review: start ticker={$ticker} userId={$userId}");

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

$cvsConfig = require ROOT_PATH . '/config/cvs-weights.php';
$aiConfig  = require ROOT_PATH . '/config/ai.php';

use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiCriticalReviewRepository;
use CVS\Ai\AiCriticalReviewService;
use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClientFactory;
use CVS\Ai\FairPriceCalculator;
use CVS\Api\FinancialDataFetcher;
use CVS\CVS\CVSModel;
use CVS\Execution\AtrZoneCalculator;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;

$reviewRepo = new AiCriticalReviewRepository();

try {
    $stage1 = (new AiAnalysisRepository())->findByTicker($ticker);
    if ($stage1 === null || !isset($stage1['content'])) {
        $reviewRepo->markFailed($ticker, 'Brak analizy etapu 1 dla tej spółki.');
        $log("generate_critical_review: FAILED {$ticker} — no stage-1 analysis");
        exit(0);
    }

    $fetcher    = new FinancialDataFetcher($cvsConfig['data_source']);
    $financials = $fetcher->fetch($ticker);
    if ($financials === null) {
        $reviewRepo->markFailed($ticker, 'Nie udało się pobrać danych rynkowych.');
        $log("generate_critical_review: FAILED {$ticker} — fetch failed");
        exit(0);
    }

    $model     = new CVSModel($cvsConfig);
    $cvsResult = $model->calculate($ticker, $financials)->toArray();

    $cvsFairPrice = FairPriceCalculator::compute($financials, $cvsConfig);

    $modelVersion = (string) ($cvsConfig['model_version'] ?? '');
    $trajWindow   = (int) ($cvsConfig['trajectory']['window_days'] ?? 90);
    $trajMin      = (int) ($cvsConfig['trajectory']['min_points']  ?? 2);
    $since        = (new DateTimeImmutable())->modify('-' . $trajWindow . ' days');
    $trajRows     = (new CvsSnapshotRepository())->findTrajectory($ticker, $since, $modelVersion);
    $trajectory   = TrajectoryCalculator::summarise($trajRows, $trajMin);

    $atrCfg   = is_array($cvsConfig['atr_zones'] ?? null) ? $cvsConfig['atr_zones'] : [];
    $execPlan = (!empty($financials['daily_ohlc']) && isset($financials['current_price']))
        ? AtrZoneCalculator::compute($financials['daily_ohlc'], (float) $financials['current_price'], $atrCfg)
        : null;

    // The web-search-enabled call runs detached from any HTTP request lifecycle —
    // override the client's timeout with the much larger budget config/ai.php
    // reserves for it (measured 138.8s live; the synchronous defaults are ~20-25s).
    $reviewAiConfig = array_merge($aiConfig, is_array($aiConfig['critical_review'] ?? null) ? $aiConfig['critical_review'] : []);

    $service = new AiCriticalReviewService(
        ClaudeClientFactory::fromConfig($reviewAiConfig),
        new AiDivergenceService(ClaudeClientFactory::fromConfig($aiConfig))
    );

    $result = $service->generate(
        $ticker,
        $cvsResult,
        $financials,
        (string) $stage1['content'],
        $cvsFairPrice,
        $trajectory,
        $execPlan
    );

    if (!$result->ok) {
        $reviewRepo->markFailed($ticker, (string) ($result->failureMessage ?? 'Nieznany błąd generowania.'));
        $log(sprintf('generate_critical_review: FAILED %s — %s', $ticker, $result->failureMessage ?? 'unknown'));
        exit(0);
    }

    $usage     = $result->usage;
    $tokensIn  = $usage !== null ? $usage->inputTokens  : 0;
    $tokensOut = $usage !== null ? $usage->outputTokens : 0;

    $reviewRepo->markCompleted(
        $ticker,
        (string) $result->text,
        $result->citations,
        (string) ($result->model ?? ''),
        $tokensIn,
        $tokensOut
    );

    $log(sprintf(
        'generate_critical_review: done ticker=%s tokens_in=%d tokens_out=%d search_degraded=%s',
        $ticker,
        $tokensIn,
        $tokensOut,
        $result->searchDegraded ? 'true' : 'false'
    ));
} catch (Throwable $e) {
    $reviewRepo->markFailed($ticker, 'Błąd wewnętrzny generowania recenzji.');
    $log(sprintf('generate_critical_review: FATAL %s — %s in %s:%d', $ticker, $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

exit(0);
