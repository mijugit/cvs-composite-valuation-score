<?php

declare(strict_types=1);

/**
 * Virtual Portfolio: daily rebalance entry point.
 *
 * Checks the NYSE market calendar and the rebalance window, then hands off
 * to the decision engine. Safe to run multiple times — idempotent per cycle_date
 * (DB UNIQUE constraint prevents duplicate cycle rows).
 *
 * Cron entries (CyberFolks panel -> "Sciezka" type, explicit PHP 8.2 path):
 *
 *   30 20 * * 1-5  /usr/local/bin/php84 /home/.../bin/portfolio-rebalance.php
 *   30 21 * * 1-5  /usr/local/bin/php84 /home/.../bin/portfolio-rebalance.php
 *
 * Two entries handle the DST offset shift between Europe/Warsaw and America/New_York.
 * The script's window check ensures only the correct one runs per day.
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/portfolio-rebalance.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('portfolio-rebalance: start');

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

use CVS\Core\Database;
use CVS\Portfolio\CycleRepository;
use CVS\Portfolio\DecisionEnforcer;
use CVS\Portfolio\DecisionService;
use CVS\Portfolio\MarketCalendar;
use CVS\Portfolio\PortfolioRepository;
use CVS\Portfolio\PortfolioService;
use CVS\Screener\ScreenerRepository;

$config = require ROOT_PATH . '/config/portfolio.php';

// --- Market calendar gate ---
// Always use an explicit Warsaw timezone for the "current time" reference;
// all market-window comparisons happen in America/New_York inside MarketCalendar.
$now      = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
$calendar = new MarketCalendar($config);
$status   = $calendar->getStatus($now);

if ($status === 'outside_rebalance_window') {
    $log('outside_rebalance_window: ' . $now->format('H:i T'));
    exit(0);
}

if ($status === 'market_closed') {
    $log('market_closed: ' . $now->format('Y-m-d'));
    exit(0);
}

// --- DB idempotency gate ---
$db             = Database::connection();
$cycleRepo      = new CycleRepository($db);

// cycle_date is always the ET date (NYSE calendar date), not Warsaw date.
$cycleDate = $now->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');

$maxAttempts = (int) ($config['strategy']['max_daily_attempts'] ?? 3);
$id = $cycleRepo->claimForRun($cycleDate, $maxAttempts);

if ($id === null) {
    $log('skip: cycle ' . $cycleDate . ' already completed, in progress, or retries exhausted');
    exit(0);
}

$log('cycle ' . $cycleDate . ' started (id=' . $id . ', max_attempts=' . $maxAttempts . ')');

// --- Rebalance engine: gather inputs + LLM call on the initial connection ---
$aiConfig        = require ROOT_PATH . '/config/ai.php';
$mergedLlmConfig = array_merge($aiConfig, $config['llm']);

$portfolioRepo   = new PortfolioRepository($db);
$decisionService = new DecisionService($cycleRepo, $mergedLlmConfig, $config);
$screenerRepo    = new ScreenerRepository($db);
// PortfolioService is built AFTER the LLM call on a fresh connection (see below),
// so the write transaction and the cycle summary/status share one connection.

// Gather inputs.
$portfolioState = $portfolioRepo->getCurrentState();
$holdings       = $portfolioRepo->getCurrentHoldings();
$screenerRows   = $screenerRepo->getFiltered(); // no filters = all quality-gate-passed tickers

$log('cycle ' . $cycleDate . ' calling LLM (' . count($screenerRows) . ' screener rows)');

// Call LLM — DecisionService writes audit record before returning.
$result = $decisionService->generate($id, $portfolioState, $holdings, $screenerRows);

if (!$result['ok']) {
    $log('cycle ' . $cycleDate . ' LLM FAILED after ' . $result['retryCount'] . ' retry, kind=' . ($result['failureKind'] ?? 'unknown'));
    $cycleRepo->updateStatus($id, 'llm_failed');
    exit(1);
}

$log('cycle ' . $cycleDate . ' LLM OK, ' . count($result['decisions']) . ' decisions');

// --- Inject real execution prices + sector map ---
// The LLM never returns price_usd (it must not hallucinate prices). The executor
// needs the actual snapshot price per ticker, so we attach it here from the same
// screener rows the model reasoned over. A BUY/SELL whose ticker has no known
// price is dropped (cannot execute without a price); HOLD/NO_ACTION pass through.
// Also passed into executeCycle() below to mark this cycle's portfolio_value_usd
// snapshot to today's price (not cost basis) — see PortfolioService::computeHoldingsValue().
$priceMap  = [];
$sectorMap = [];
foreach ($screenerRows as $row) {
    $t = strtoupper((string) ($row['ticker'] ?? ''));
    if ($t === '') {
        continue;
    }
    if (isset($row['price_at_snapshot'])) {
        $priceMap[$t] = (float) $row['price_at_snapshot'];
    }
    if (isset($row['sector'])) {
        $sectorMap[$t] = (string) $row['sector'];
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

// --- Hard cap enforcement (server-side) ---
// The LLM cannot reliably keep its structured quantity fields in sync with the
// per-stock / per-sector caps. Trim every BUY to what actually fits, regardless
// of what the model asked. This is the authoritative guard, not the prompt.
$enforced = (new DecisionEnforcer($config['strategy'] ?? []))->apply(
    $pricedDecisions,
    $holdings,
    $priceMap,
    $sectorMap,
    (float) ($portfolioState['cash'] ?? 0)
);
$pricedDecisions = $enforced['decisions'];

foreach ($enforced['notes'] as $note) {
    $log('cycle ' . $cycleDate . ' enforce: ' . $note);
}

// --- Fresh connection for the write phase ---
// The connection used during the ~30s LLM call may be dropped by CF. More importantly,
// executeCycle's transaction and its cycle summary/status writes MUST share one
// connection — otherwise the cross-connection writes deadlock on the rebalance_cycle
// row (SQLSTATE 1205 lock wait timeout). Rebuild cycleRepo on the fresh connection.
Database::reconnect();
$writeDb          = Database::connection();
$cycleRepo        = new CycleRepository($writeDb);
$portfolioService = new PortfolioService($writeDb, $cycleRepo);

// Execute portfolio — atomic transaction inside PortfolioService.
try {
    $portfolioService->executeCycle($id, $pricedDecisions, $priceMap);
    $log('cycle ' . $cycleDate . ' completed');
} catch (Throwable $e) {
    $cycleRepo->updateStatus($id, 'failed');
    $log('cycle ' . $cycleDate . ' EXECUTION FAILED: ' . $e->getMessage());
    exit(1);
}

exit(0);
