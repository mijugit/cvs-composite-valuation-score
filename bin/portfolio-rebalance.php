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
use CVS\Portfolio\MarketCalendar;

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

$id = $cycleRepo->insertCycle($cycleDate);

if ($id === null) {
    $log('already_started: cycle ' . $cycleDate . ' already exists');
    exit(0);
}

$log('cycle ' . $cycleDate . ' started (id=' . $id . ')');

// --- Rebalance engine ---
try {
    // F-03: wire CVS\Portfolio\DecisionService and CVS\Portfolio\PortfolioService here.
    // Replace this stub once llm-decision-contract-and-retry (F-03) is implemented.
    $log('engine stub: no-op (F-03 not yet implemented)');
    $cycleRepo->updateStatus($id, 'completed');
    $log('cycle ' . $cycleDate . ' completed');
} catch (Throwable $e) {
    $cycleRepo->updateStatus($id, 'failed');
    $log('cycle ' . $cycleDate . ' FAILED: ' . $e->getMessage());
    exit(1);
}

exit(0);
