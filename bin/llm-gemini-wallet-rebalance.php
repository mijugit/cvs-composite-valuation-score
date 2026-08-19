<?php

declare(strict_types=1);

/**
 * LLM_Gemini_Wallet: daily rebalance entry point.
 *
 * Structural clone of bin/llm-free-wallet-rebalance.php (change:
 * llm-gemini-wallet): same overall shape (CLI guard, .env load, market-calendar
 * gate, idempotent claim, gather inputs, call decision engine, execute, log),
 * no DecisionEnforcer-equivalent, no detached-worker indirection (already a CLI
 * cron process with no request-lifecycle timeout budget). The only structural
 * difference: LlmGeminiContextGatherer needs no AiAnalysisRepository/
 * AiCriticalReviewRepository — it never checks the Claude-generated analysis
 * cache, always running its own fresh googleSearch-grounded call (change:
 * llm-gemini-wallet's explicit provider-isolation decision).
 *
 * OPERATOR'S CHOSEN SCHEDULE DESIGN (2026-08-19): 21:40 Warsaw (primary) and
 * 22:40 Warsaw (backup) — a pure 10-minute-earlier time-translation of the
 * sibling LLM_Free_Wallet's own proven schedule (21:50/22:50), chosen so it
 * inherits the exact same DST-safety margin without recomputing it, while
 * staying clear of both other wallets' cron windows (Base: 20:30/21:30,
 * Free: 21:50/22:50) by at least 10 minutes in both directions.
 *
 * config/llm-gemini-wallet.php sets rebalance_window_minutes=90 with
 * market.close_time='17:00' (mirrors the sibling's window exactly) → effective
 * window [15:30, 17:00) ET. Walking through what each entry maps to per offset
 * (identical reasoning to the sibling's docblock, shifted 10 minutes earlier):
 *   - offset 6h (nominal, most of the year): 21:40→15:40 ET (in-window, ~10min
 *     earlier than the sibling's ideal 15:50 ET target) — 22:40→16:40 ET, cycle
 *     already 'completed' by then, silent no-op (dormant backup).
 *   - offset 5h (mid-March mismatch): 21:40→16:40 ET (still in-window) —
 *     22:40→17:40 ET, outside the window, no-op.
 *   - offset 7h (late-Oct/early-Nov mismatch): 21:40→14:40 ET, BEFORE the
 *     window opens (15:30) — no-op — 22:40→15:40 ET, in-window, so the backup
 *     entry becomes the effective primary that week.
 * Net effect: every trading day gets exactly one execution, always within the
 * practical window, without a third entry.
 *
 * Cron entries (CyberFolks panel -> "Sciezka" type, explicit PHP 8.2 path:
 * /usr/local/bin/php82 — same binary as the sibling wallet, confirmed via
 * deployment/<slug>.deploy.json):
 *
 *   40 21 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-gemini-wallet-rebalance.php
 *   40 22 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-gemini-wallet-rebalance.php
 */

// Guard: only run from CLI, never via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

$logFile = ROOT_PATH . '/logs/llm-gemini-wallet-rebalance.log';
if (!is_dir(ROOT_PATH . '/logs')) {
    mkdir(ROOT_PATH . '/logs', 0755, true);
}

$log = static function (string $msg) use ($logFile): void {
    $line = '[' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

$log('llm-gemini-wallet-rebalance: start');

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
// bin/rescore.php / bin/llm-free-wallet-rebalance.php).
$_SESSION = [];

use CVS\Core\Database;
use CVS\LlmGemini\LlmGeminiContextGatherer;
use CVS\LlmGemini\LlmGeminiCycleRepository;
use CVS\LlmGemini\LlmGeminiDecisionService;
use CVS\LlmGemini\LlmGeminiRepository;
use CVS\LlmGemini\LlmGeminiService;
use CVS\Portfolio\MarketCalendar;
use CVS\CVS\Valuation\PeerCoverage;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\Screener\ScreenerRepository;
use CVS\Screener\SnapshotFreshness;

$config           = require ROOT_PATH . '/config/llm-gemini-wallet.php';
$portfolioConfig  = require ROOT_PATH . '/config/portfolio.php'; // holidays only — shared NYSE calendar fact, not module logic
$cvsConfig        = require ROOT_PATH . '/config/cvs-weights.php'; // live model_version + screener knobs (see ScreenerRepository below)

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
$cycleRepo = new LlmGeminiCycleRepository($db);

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
$geminiConfig     = require ROOT_PATH . '/config/gemini.php';
$mergedLlmConfig  = array_merge($geminiConfig, $config['llm']);

$walletRepo   = new LlmGeminiRepository($db);
// The live model_version is NOT optional here — same reasoning as the sibling
// wallet's cron (see bin/llm-free-wallet-rebalance.php's comment on the
// 2026-08-13/14 MU incident this filter prevents).
$screenerRepo = new ScreenerRepository(
    $db,
    (string) ($cvsConfig['model_version'] ?? ''),
    $cvsConfig['trajectory'] ?? [],
    $cvsConfig['thresholds'] ?? [],
    $cvsConfig['markets'] ?? []
);

$portfolioState = $walletRepo->getCurrentState();
$holdings       = $walletRepo->getCurrentHoldings();
$screenerRows   = $screenerRepo->getFiltered(); // no filters = all quality-gate-passed tickers
$legendHistory  = $walletRepo->getLegendHistory((int) $config['legend_context_count']);

// Withhold snapshots the model cannot date. Held tickers stay regardless — the
// price map further down is built from these rows, so dropping a held one would
// strand the position rather than merely excluding it as a candidate.
$freshness   = $cvsConfig['snapshot_freshness'] ?? [];
$heldTickers = array_map(static fn (array $h): string => strtoupper((string) $h['ticker']), $holdings);
$partitioned = SnapshotFreshness::partition(
    $screenerRows,
    $heldTickers,
    $cycleDate,
    (int) ($freshness['llm_max_age_days'] ?? 7)
);
$screenerRows = $partitioned['kept'];
if ($partitioned['dropped'] !== []) {
    $log(sprintf(
        'cycle %s withheld %d stale candidate(s) from the LLM: %s',
        $cycleDate,
        count($partitioned['dropped']),
        implode(', ', $partitioned['dropped'])
    ));
}

// Peer coverage: a company whose industry bucket is below min_sample_count is
// benchmarked against its SECTOR, which can misprice it badly in either
// direction. HELD tickers are exempt for the same reason as stale ones: the
// executor prices trades from these rows, so dropping a held ticker strands
// the position.
$peerCoverage = PeerCoverage::fromConfig($cvsConfig, new PeerMedianRepository($db));
$thinDropped = [];
$screenerRows = array_values(array_filter($screenerRows, static function (array $r) use ($peerCoverage, $heldTickers, &$thinDropped): bool {
    $t = strtoupper((string) ($r['ticker'] ?? ''));
    if (in_array($t, $heldTickers, true)) {
        return true;
    }
    if ($peerCoverage->isThin(
        isset($r['industry']) ? (string) $r['industry'] : null,
        isset($r['valuation_source']) ? (string) $r['valuation_source'] : null
    )) {
        $thinDropped[] = $t;
        return false;
    }
    return true;
}));
if ($thinDropped !== []) {
    $log(sprintf(
        'cycle %s withheld %d candidate(s) with no industry peers: %s',
        $cycleDate,
        count($thinDropped),
        implode(', ', $thinDropped)
    ));
}

$log('cycle ' . $cycleDate . ' gathered ' . count($screenerRows) . ' screener rows, ' . count($legendHistory) . ' legend entries');

// --- Context gathering + LLM call ---
// Wrapped in try/catch: any failure here must resolve to a logged,
// retry-eligible 'llm_failed' status instead of silence (same guardrail as the
// sibling wallet's cron, added after the 2026-08-07 unbounded-candidate-table
// crash).
try {
    // Context gathering: always a fresh googleSearch call per candidate, up to
    // context_search_cap — no cache check (change: llm-gemini-wallet's explicit
    // provider-isolation decision — see LlmGeminiContextGatherer's docblock).
    $candidateTickers = array_map(
        static fn (array $row): string => strtoupper((string) ($row['ticker'] ?? '')),
        $screenerRows
    ); // already ordered by CVS Swing per ScreenerRepository::getFiltered()'s default sort

    $contextGatherer = new LlmGeminiContextGatherer(
        $geminiConfig,
        (int) $config['context_search_cap']
    );
    $contextByTicker = $contextGatherer->gather($candidateTickers);

    $log('cycle ' . $cycleDate . ' gathered context for ' . count($contextByTicker) . ' tickers');

    // Reconnect before the decision call: context gathering can run for several
    // minutes (up to context_search_cap sequential googleSearch-enabled Gemini
    // calls), and the original connection sits idle the whole time. Mirrors the
    // sibling wallet's reconnect() before its own decision call (CF MySQL
    // wait_timeout observed live on 2026-08-07).
    Database::reconnect();
    $db        = Database::connection();
    $cycleRepo = new LlmGeminiCycleRepository($db);

    // LlmGeminiDecisionService writes the audit record (incl. legend + tokens) before returning.
    $decisionService = new LlmGeminiDecisionService($cycleRepo, $mergedLlmConfig, $config);
    $result = $decisionService->generate($id, $portfolioState, $holdings, $screenerRows, $legendHistory, $contextByTicker);
} catch (Throwable $e) {
    $log('cycle ' . $cycleDate . ' DECISION ENGINE CRASHED: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // The crash itself may BE a dropped connection (as above) — the original
    // $cycleRepo can be just as unusable for this recovery write. Reconnect
    // before attempting it, and don't let a second failure here escape uncaught.
    try {
        Database::reconnect();
        (new LlmGeminiCycleRepository(Database::connection()))->updateStatus($id, 'llm_failed');
    } catch (Throwable $e2) {
        $log('cycle ' . $cycleDate . ' ALSO failed to record llm_failed status: ' . $e2->getMessage());
    }
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
// quantity survives this price-injection is what executes.
$priceMap = [];
foreach ($screenerRows as $row) {
    $t = strtoupper((string) ($row['ticker'] ?? ''));
    if ($t !== '' && isset($row['price_at_snapshot'])) {
        $priceMap[$t] = (float) $row['price_at_snapshot'];
    }
}

$pricedDecisions = [];
$droppedNoPrice  = 0;
$dropNote        = null;
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

// A dropped BUY/SELL is a decision the model made and the system silently threw
// away — surface it in the cycle notes so the divergence is visible on
// /llm-gemini instead of living only in a cron log (same lesson as the sibling
// wallet's MU incident, 2026-08-14).
if ($droppedNoPrice > 0) {
    $log('cycle ' . $cycleDate . ' dropped ' . $droppedNoPrice . ' BUY/SELL without known price');
    $dropNote = sprintf(
        'UWAGA: %d decyzji BUY/SELL odrzucono — brak ceny w screenerze dla tych spółek. '
        . 'Portfel NIE odzwierciedla w pełni decyzji modelu z tego cyklu.',
        $droppedNoPrice
    );
}

// --- Fresh connection for the write phase ---
// Mirrors the sibling wallet's reasoning: the connection used during the LLM
// call may be dropped by CF, and executeCycle's transaction plus its cycle
// summary/status writes must share one connection.
Database::reconnect();
$writeDb       = Database::connection();
$cycleRepo     = new LlmGeminiCycleRepository($writeDb);
$walletService = new LlmGeminiService($writeDb, $cycleRepo);

try {
    $walletService->executeCycle($id, $pricedDecisions, $priceMap, $dropNote);
    $log('cycle ' . $cycleDate . ' completed');
} catch (Throwable $e) {
    $cycleRepo->updateStatus($id, 'failed');
    $log('cycle ' . $cycleDate . ' EXECUTION FAILED: ' . $e->getMessage());
    exit(1);
}

exit(0);
