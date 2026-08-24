<?php

declare(strict_types=1);

/**
 * Background worker for the Gemini side of the stage-2 "Recenzja krytyczna"
 * feature — change: critical-review-models.
 *
 * Mirrors bin/generate_critical_review.php's structure (CLI guard, .env
 * parsing, $_SESSION reset, stage-1 + CVS re-enrichment, try/catch envelope)
 * but is wired to GeminiCriticalReviewService/GeminiClientFactory instead of
 * the Claude wiring — a deliberately separate script so the proven Claude
 * worker carries zero regression risk from this addition.
 *
 * Unlike the Claude worker, no config/gemini.php override merge is needed —
 * its defaults (180s timeout / 200s total budget) are already sized for a
 * slow, web-search-enabled call; config/ai.php's 'critical_review' override
 * exists only because the Claude *synchronous* defaults are much tighter.
 *
 * No HTTP session access here (background CLI process) — ticker and userId
 * arrive as positional CLI arguments, not from $_SESSION. Provider is
 * implicit in which script runs — not a third CLI arg — so a caller can
 * never pass a mismatched provider value.
 *
 * Contract: php bin/generate_critical_review_gemini.php <ticker> <userId>
 *
 * Cron: none — invoked ad hoc by the controller, never scheduled.
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/critical_review_gemini.log';
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
    $log(sprintf('generate_critical_review_gemini: ERROR invalid args — ticker=%s userId=%s', $argv[1] ?? '', $argv[2] ?? ''));
    exit(1);
}

$ticker = strtoupper($ticker);

$log("generate_critical_review_gemini: start ticker={$ticker} userId={$userId}");

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

$cvsConfig    = require ROOT_PATH . '/config/cvs-weights.php';
$aiConfig     = require ROOT_PATH . '/config/ai.php';
$geminiConfig = require ROOT_PATH . '/config/gemini.php';

use CVS\CVS\Valuation\MedianResolver;
use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiCriticalReviewRepository;
use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClientFactory;
use CVS\Ai\CriticalReviewProbabilityParser;
use CVS\Ai\CriticalReviewProvider;
use CVS\Ai\FairPriceCalculator;
use CVS\Ai\GeminiClientFactory;
use CVS\Ai\GeminiCriticalReviewService;
use CVS\Api\FinancialDataFetcher;
use CVS\CVS\CVSModel;
use CVS\Execution\AtrZoneCalculator;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;

$reviewRepo = new AiCriticalReviewRepository();

try {
    $stage1 = (new AiAnalysisRepository())->findByTicker($ticker);
    if ($stage1 === null || !isset($stage1['content'])) {
        $reviewRepo->markFailed($ticker, CriticalReviewProvider::GEMINI, 'Brak analizy etapu 1 dla tej spółki.');
        $log("generate_critical_review_gemini: FAILED {$ticker} — no stage-1 analysis");
        exit(0);
    }

    $fetcher    = new FinancialDataFetcher($cvsConfig['data_source']);
    $financials = $fetcher->fetch($ticker);
    if ($financials === null) {
        $reviewRepo->markFailed($ticker, CriticalReviewProvider::GEMINI, 'Nie udało się pobrać danych rynkowych.');
        $log("generate_critical_review_gemini: FAILED {$ticker} — fetch failed");
        exit(0);
    }

    $model     = new CVSModel($cvsConfig);
    $cvsResult = $model->calculate($ticker, $financials)->toArray();

    $cvsFairPrice = FairPriceCalculator::compute($financials, $cvsConfig, MedianResolver::fromConfig($cvsConfig));

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

    // AiDivergenceService::buildDataBlock() is reused unmodified regardless
    // of transport — the divergence service itself is only used for the data
    // block here, never for a network call, so it's wired to the (unused
    // for network purposes) Claude client the same way the Claude worker
    // wires its own AiDivergenceService instance.
    $service = new GeminiCriticalReviewService(
        GeminiClientFactory::fromConfig($geminiConfig),
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
        $reviewRepo->markFailed($ticker, CriticalReviewProvider::GEMINI, (string) ($result->failureMessage ?? 'Nieznany błąd generowania.'));
        $log(sprintf('generate_critical_review_gemini: FAILED %s — %s', $ticker, $result->failureMessage ?? 'unknown'));
        exit(0);
    }

    $usage     = $result->usage;
    $tokensIn  = $usage !== null ? $usage->inputTokens  : 0;
    $tokensOut = $usage !== null ? $usage->outputTokens : 0;

    // Split the trailing bull/bear probability JSON block off the narrative.
    // Degrades gracefully on any parse failure — the narrative is still
    // stored, probability fields just stay null (see parser docblock).
    $parsed = CriticalReviewProbabilityParser::parse((string) $result->text);

    $reviewRepo->markCompleted(
        $ticker,
        CriticalReviewProvider::GEMINI,
        $parsed['narrative'],
        $result->citations,
        (string) ($result->model ?? ''),
        $tokensIn,
        $tokensOut,
        $parsed['bull_probability'],
        $parsed['bear_probability'],
        $parsed['rationale']
    );

    $log(sprintf(
        'generate_critical_review_gemini: done ticker=%s tokens_in=%d tokens_out=%d search_degraded=%s probability_parsed=%s',
        $ticker,
        $tokensIn,
        $tokensOut,
        $result->searchDegraded ? 'true' : 'false',
        $parsed['bull_probability'] !== null ? 'true' : 'false'
    ));
} catch (Throwable $e) {
    $reviewRepo->markFailed($ticker, CriticalReviewProvider::GEMINI, 'Błąd wewnętrzny generowania recenzji.');
    $log(sprintf('generate_critical_review_gemini: FATAL %s — %s in %s:%d', $ticker, $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

exit(0);
