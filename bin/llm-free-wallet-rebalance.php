<?php

declare(strict_types=1);

/**
 * LLM_Free_Wallet: daily rebalance entry point.
 *
 * Same overall shape as bin/portfolio-rebalance.php (CLI guard, .env load,
 * market-calendar gate, idempotent claim, gather inputs, call decision
 * engine, execute, log) but with no DecisionEnforcer-equivalent step — the
 * model's decisions execute exactly as returned (PRD FR-004) — and no
 * detached-worker indirection: this is already a CLI cron process with no
 * request-lifecycle timeout budget, so it simply takes as long as it takes
 * (mirrors bin/rescore.php's unbounded execution time; see plan.md's Current
 * State Analysis for why the change: cvs-ai-critical-review background-worker
 * pattern does not apply here).
 *
 * Targets execution near NYSE close (~10 minutes before, i.e. 15:50 ET) —
 * a distinct wall-clock slot from the baseline wallet's own cron, so the two
 * never contend for the same window.
 *
 * Unlike the baseline wallet (390-minute window = the whole session, so
 * timing precision doesn't matter), THIS wallet's config narrows
 * rebalance_window_minutes to 20 — [15:40, 16:00) ET — because a wide window
 * combined with only two DST-offset cron entries would let the earlier entry
 * silently claim the cycle on every normal (non-mismatch-week) day, defeating
 * the near-close intent entirely (the idempotent claim always goes to
 * whichever entry fires first). With a narrow window, THREE entries — one
 * per possible Europe/Warsaw vs America/New_York offset (5h/6h/7h, depending
 * on which side of the DST transition each timezone is on) — are needed so
 * that exactly one of them always lands inside the window regardless of
 * which offset is in effect that day; the other two fire outside the window
 * and no-op. During the rare (~1 week/year) mismatch where the offset is 7h,
 * no entry lands in-window and the wallet simply skips that day — acceptable
 * for a paper portfolio, and self-correcting the next day.
 *
 * Cron entries (CyberFolks panel -> "Sciezka" type, explicit PHP 8.2 path:
 * /usr/local/bin/php82 — confirmed via deployment/<slug>.deploy.json):
 *
 *   50 20 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-free-wallet-rebalance.php
 *   50 21 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-free-wallet-rebalance.php
 *   50 22 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-free-wallet-rebalance.php
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/llm-free-wallet-rebalance.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('llm-free-wallet-rebalance: start');

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same logic as public/index.php and other bin/ scripts).
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

// FinancialDataFetcher / ScreenerRepository read paths use $_SESSION in some
// code paths; in CLI there is no session, so initialising the array lets it
// act as a plain in-memory cache for the run's lifetime (same workaround as
// bin/rescore.php / bin/generate_critical_review.php).
$_SESSION = [];

use CVS\Ai\AiAnalysisRepository;
use CVS\Ai\AiCriticalReviewRepository;
use CVS\Core\Database;
use CVS\LlmFree\LlmFreeContextGatherer;
use CVS\LlmFree\LlmFreeCycleRepository;
use CVS\LlmFree\LlmFreeDecisionService;
use CVS\LlmFree\LlmFreeRepository;
use CVS\LlmFree\LlmFreeService;
use CVS\Portfolio\MarketCalendar;
use CVS\Screener\ScreenerRepository;

$config           = require ROOT_PATH . '/config/llm-free-wallet.php';
$portfolioConfig  = require ROOT_PATH . '/config/portfolio.php'; // holidays only — shared NYSE calendar fact, not module logic

// --- Market calendar gate ---
// Always use an explicit Warsaw timezone for the "current time" reference;
// all market-window comparisons happen in America/New_York inside MarketCalendar.
$now      = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
$calendar = new MarketCalendar([
    'market'                   => $config['market'],
    'rebalance_window_minutes' => $config['rebalance_window_minutes'],
    'holidays'                 => $portfolioConfig['holidays'],
]);
$status = $calendar->getStatus($now);

if ($status === 'outside_rebalance_window') {
    $log('outside_rebalance_window: ' . $now->format('H:i T'));
    exit(0);
}

if ($status === 'market_closed') {
    $log('market_closed: ' . $now->format('Y-m-d'));
    exit(0);
}

// --- DB idempotency gate ---
$db        = Database::connection();
$cycleRepo = new LlmFreeCycleRepository($db);

// cycle_date is always the ET date (NYSE calendar date), not Warsaw date.
$cycleDate = $now->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');

$maxAttempts = 3;
$id = $cycleRepo->claimForRun($cycleDate, $maxAttempts);

if ($id === null) {
    $log('skip: cycle ' . $cycleDate . ' already completed, in progress, or retries exhausted');
    exit(0);
}

$log('cycle ' . $cycleDate . ' started (id=' . $id . ', max_attempts=' . $maxAttempts . ')');

// --- Gather inputs ---
$aiConfig        = require ROOT_PATH . '/config/ai.php';
$mergedLlmConfig = array_merge($aiConfig, $config['llm']);

$walletRepo   = new LlmFreeRepository($db);
$screenerRepo = new ScreenerRepository($db);

$portfolioState = $walletRepo->getCurrentState();
$holdings       = $walletRepo->getCurrentHoldings();
$screenerRows   = $screenerRepo->getFiltered(); // no filters = all quality-gate-passed tickers
$legendHistory  = $walletRepo->getLegendHistory((int) $config['legend_context_count']);

$log('cycle ' . $cycleDate . ' gathered ' . count($screenerRows) . ' screener rows, ' . count($legendHistory) . ' legend entries');

// --- Context gathering + LLM call ---
// Wrapped in try/catch: unlike executeCycle() below, neither of these calls
// had a safety net until 2026-08-07, when an uncaught Throwable (root cause:
// the unbounded candidate table below) killed the cron mid-run, leaving the
// cycle row stuck in status='started' forever (claimForRun() only retries
// 'failed'/'llm_failed', never 'started' — by design, to avoid concurrent
// execution of a possibly-still-running process). Any failure here must now
// resolve to a logged, retry-eligible 'llm_failed' status instead of silence.
try {
    // Context gathering: existing analyses first, bounded fresh search for the rest.
    $candidateTickers = array_map(
        static fn (array $row): string => strtoupper((string) ($row['ticker'] ?? '')),
        $screenerRows
    ); // already ordered by CVS Swing per ScreenerRepository::getFiltered()'s default sort

    $contextGatherer = new LlmFreeContextGatherer(
        new AiAnalysisRepository($db),
        new AiCriticalReviewRepository($db),
        $aiConfig,
        (int) $config['context_search_cap']
    );
    $contextByTicker = $contextGatherer->gather($candidateTickers);

    $log('cycle ' . $cycleDate . ' gathered context for ' . count($contextByTicker) . ' tickers');

    // LlmFreeDecisionService writes the audit record (incl. legend + tokens) before returning.
    $decisionService = new LlmFreeDecisionService($cycleRepo, $mergedLlmConfig, $config);
    $result = $decisionService->generate($id, $portfolioState, $holdings, $screenerRows, $legendHistory, $contextByTicker);
} catch (Throwable $e) {
    $log('cycle ' . $cycleDate . ' DECISION ENGINE CRASHED: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $cycleRepo->updateStatus($id, 'llm_failed');
    exit(1);
}

if (!$result['ok']) {
    $log('cycle ' . $cycleDate . ' LLM FAILED after ' . $result['retryCount'] . ' retry, kind=' . ($result['failureKind'] ?? 'unknown'));
    $cycleRepo->updateStatus($id, 'llm_failed');
    exit(1);
}

$log('cycle ' . $cycleDate . ' LLM OK, ' . count($result['decisions']) . ' decisions, legend written');

// --- Inject real execution prices ---
// The model never returns price_usd (must not hallucinate prices). A BUY/SELL
// whose ticker has no known price is dropped (cannot execute without a price);
// HOLD/NO_ACTION pass through. No DecisionEnforcer step here — whatever
// quantity survives this price-injection is what executes (PRD FR-004).
$priceMap = [];
foreach ($screenerRows as $row) {
    $t = strtoupper((string) ($row['ticker'] ?? ''));
    if ($t !== '' && isset($row['price_at_snapshot'])) {
        $priceMap[$t] = (float) $row['price_at_snapshot'];
    }
}

$pricedDecisions = [];
$droppedNoPrice  = 0;
foreach ($result['decisions'] as $decision) {
    $action = strtoupper((string) ($decision['action'] ?? ''));
    $ticker = strtoupper((string) ($decision['ticker'] ?? ''));

    if (in_array($action, ['BUY', 'SELL'], true)) {
        if (!isset($priceMap[$ticker])) {
            $droppedNoPrice++;
            continue; // no price → cannot execute
        }
        $decision['price_usd'] = $priceMap[$ticker];
    }
    $pricedDecisions[] = $decision;
}

if ($droppedNoPrice > 0) {
    $log('cycle ' . $cycleDate . ' dropped ' . $droppedNoPrice . ' BUY/SELL without known price');
}

// --- Fresh connection for the write phase ---
// Mirrors bin/portfolio-rebalance.php's reasoning: the connection used during
// the LLM call may be dropped by CF, and executeCycle's transaction plus its
// cycle summary/status writes must share one connection.
Database::reconnect();
$writeDb     = Database::connection();
$cycleRepo   = new LlmFreeCycleRepository($writeDb);
$walletService = new LlmFreeService($writeDb, $cycleRepo);

try {
    $walletService->executeCycle($id, $pricedDecisions, $priceMap);
    $log('cycle ' . $cycleDate . ' completed');
} catch (Throwable $e) {
    $cycleRepo->updateStatus($id, 'failed');
    $log('cycle ' . $cycleDate . ' EXECUTION FAILED: ' . $e->getMessage());
    exit(1);
}

exit(0);
