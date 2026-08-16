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
 * OPERATOR'S CHOSEN SCHEDULE DESIGN (2026-08-10): two cron entries — 21:50
 * Warsaw (primary) and 22:50 Warsaw (backup, catches it if the primary
 * doesn't fire or fails) — rather than three entries auto-covering every
 * possible Europe/Warsaw vs America/New_York DST offset. This trades full
 * automatic DST coverage for operational simplicity: the operator watches
 * the two brief EU/US DST-mismatch windows each year (mid-March, when the US
 * has already sprung forward but the EU hasn't yet; late-Oct/early-Nov, the
 * reverse) and hand-adjusts the cron times if needed, rather than relying on
 * a third always-on entry.
 *
 * config/llm-free-wallet.php sets rebalance_window_minutes=90 with
 * market.close_time='17:00' (NOT the real NYSE close, which is always
 * 16:00 ET — this is the window's practical outer bound) → window
 * [15:30, 17:00) ET. Walking through what each entry maps to per offset:
 *   - offset 6h (nominal, most of the year): 21:50→15:50 ET (ideal target,
 *     executes here every normal day) — 22:50→16:50 ET, but the cycle is
 *     already 'completed' by then, so it's a silent no-op (dormant backup).
 *   - offset 5h (mid-March mismatch): 21:50→16:50 ET (still in-window —
 *     executes about an hour later than ideal, but same session) — 22:50→
 *     17:50 ET, outside the window, no-op.
 *   - offset 7h (late-Oct/early-Nov mismatch): 21:50→14:50 ET, BEFORE the
 *     window opens (15:30) — no-op — 22:50→15:50 ET, exactly the ideal
 *     target, so the backup entry becomes the effective primary that week.
 * Net effect: every trading day gets exactly one execution, always within
 * the practical window, without a third entry — at the cost of firing ~1h
 * later than ideal during the 5h-offset mismatch week.
 *
 * Cron entries (CyberFolks panel -> "Sciezka" type, explicit PHP 8.2 path:
 * /usr/local/bin/php82 — confirmed via deployment/<slug>.deploy.json):
 *
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
use CVS\CVS\Valuation\PeerCoverage;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\Screener\ScreenerRepository;
use CVS\Screener\SnapshotFreshness;

$config           = require ROOT_PATH . '/config/llm-free-wallet.php';
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
// The live model_version is NOT optional here. Without it ScreenerRepository
// falls back to a version-agnostic MAX(score_date), which (a) returns the 3.1
// and 3.2 shadow rows alongside the live 4.0 one — roughly tripling the row
// count and filling the capped candidate table with the same ticker repeated
// under different, unlabelled scores — and (b) lets any newer scoreless row
// mask a ticker's last good snapshot. Every web caller has passed this since
// the 2026-06-08 duplication hotfix; this cron was missed, and the divergence
// is what removed MU from the wallet's universe on 2026-08-13/14.
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
// strand the position rather than merely excluding it as a candidate. That is
// precisely the trap MU fell into on 2026-08-13/14.
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
// direction — ASB.WA (n=1) read as 58% below Technology while sitting exactly
// on its own industry median. A human gets a badge and can judge; a model
// cannot, so thin-bucket tickers stay out of the candidate list. HELD tickers
// are exempt for the same reason as stale ones: the executor prices trades from
// these rows, so dropping a held ticker strands the position.
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

    // Reconnect before the decision call: context gathering can run for several
    // minutes (up to context_search_cap sequential web-search-enabled Claude
    // calls), and the original connection sits idle the whole time. Observed
    // live on 2026-08-07: a 6.5-minute context-gathering phase was enough to
    // trip CF's MySQL wait_timeout ("SQLSTATE[HY000] ... Server has gone
    // away") right when LlmFreeDecisionService tried to write the audit
    // record. Mirrors the same reconnect() done below before executeCycle().
    Database::reconnect();
    $db        = Database::connection();
    $cycleRepo = new LlmFreeCycleRepository($db);

    // LlmFreeDecisionService writes the audit record (incl. legend + tokens) before returning.
    $decisionService = new LlmFreeDecisionService($cycleRepo, $mergedLlmConfig, $config);
    $result = $decisionService->generate($id, $portfolioState, $holdings, $screenerRows, $legendHistory, $contextByTicker);
} catch (Throwable $e) {
    $log('cycle ' . $cycleDate . ' DECISION ENGINE CRASHED: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // The crash itself may BE a dropped connection (as above) — the original
    // $cycleRepo can be just as unusable for this recovery write. Reconnect
    // before attempting it, and don't let a second failure here escape uncaught.
    try {
        Database::reconnect();
        (new LlmFreeCycleRepository(Database::connection()))->updateStatus($id, 'llm_failed');
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
// away — the wallet then diverges from its own stated legend with nothing in the
// UI to show it. Observed on MU (2026-08-14): the model wrote that it was
// trimming the position, the SELL was dropped for want of a screener price, and
// the position stayed put at 37% of the book. Surface it in the cycle notes so
// the divergence is visible on /llm-free instead of living only in a cron log.
if ($droppedNoPrice > 0) {
    $log('cycle ' . $cycleDate . ' dropped ' . $droppedNoPrice . ' BUY/SELL without known price');
    $dropNote = sprintf(
        'UWAGA: %d decyzji BUY/SELL odrzucono — brak ceny w screenerze dla tych spółek. '
        . 'Portfel NIE odzwierciedla w pełni decyzji modelu z tego cyklu.',
        $droppedNoPrice
    );
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
    $walletService->executeCycle($id, $pricedDecisions, $priceMap, $dropNote);
    $log('cycle ' . $cycleDate . ' completed');
} catch (Throwable $e) {
    $cycleRepo->updateStatus($id, 'failed');
    $log('cycle ' . $cycleDate . ' EXECUTION FAILED: ' . $e->getMessage());
    exit(1);
}

exit(0);
