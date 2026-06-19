<?php

declare(strict_types=1);

namespace CVS\CVS;

use CVS\Ai\AiAnalysisRepository;
use CVS\Alerts\AlertRepository;
use CVS\Alerts\PriceAlertRepository;
use CVS\Auth\AuthController;
use CVS\Api\FinancialDataFetcher;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\History\HistoryRepository;
use CVS\Pro\AiUsageRepository;
use CVS\Pro\ProGate;
use CVS\Pro\ProRepository;
use CVS\Execution\AtrZoneCalculator;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;
use CVS\Translation\TranslationRepository;
use DateTimeImmutable;
use CVS\Watchlist\WatchlistRepository;

/**
 * Handles the dashboard and CVS analysis flow.
 *
 * All routes in this controller are protected — AuthController::requireAuth()
 * is called at the top of each action.
 */
class AnalysisController
{
    private CVSModel             $model;
    private FinancialDataFetcher $fetcher;
    private WatchlistRepository  $watchlist;
    private HistoryRepository    $history;
    private int                  $maxHistory;
    private string               $modelVersion;
    private int                  $trajectoryWindowDays;
    private int                  $trajectoryMinPoints;
    /** @var array<string, mixed> */
    private array                $atrZonesConfig;

    public function __construct()
    {
        $config             = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->model        = new CVSModel($config);
        $this->fetcher      = new FinancialDataFetcher($config['data_source']);
        $this->watchlist    = new WatchlistRepository();
        $this->history      = new HistoryRepository();
        $this->maxHistory   = $config['data_source']['max_history'] ?? 20;
        // Hotfix (2026-06-08): live model_version, used to filter out
        // cvs-overlay-penalties shadow rows (3.1) from "latest snapshot" reads —
        // see CvsSnapshotRepository::findAllLatest().
        $this->modelVersion = (string) ($config['model_version'] ?? '');
        // Phase 8 (slice 1): CVS trajectory sparkline knobs.
        $this->trajectoryWindowDays = (int) ($config['trajectory']['window_days'] ?? 90);
        $this->trajectoryMinPoints  = (int) ($config['trajectory']['min_points']  ?? 2);
        // Phase 8 (slice 2): ATR entry-zone knobs (consumed by AtrZoneCalculator).
        $this->atrZonesConfig = is_array($config['atr_zones'] ?? null) ? $config['atr_zones'] : [];
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function dashboard(Request $req): void
    {
        AuthController::requireAuth();
        $userId        = (int) $_SESSION['user_id'];
        $watchlist     = $this->watchlist->findByUser($userId);
        $history       = $this->history->findByUser($userId, $this->maxHistory);
        $alertRepo     = new AlertRepository();
        $alertsEnabled = $alertRepo->isGlobalEnabled($userId);
        $userRepo      = new \CVS\Auth\UserRepository();
        $emailVerified = $userRepo->isEmailVerified($userId);

        // Build ticker→reco_swing map (chip colours) and ticker→tooltip data
        // (company name + CVS Swing/Fund + recommendations) from latest snapshots.
        $watchlistRecos = [];
        $watchlistInfo  = [];
        if (!empty($watchlist)) {
            $snapshotRepo = new CvsSnapshotRepository();
            foreach ($snapshotRepo->findAllLatest($this->modelVersion) as $row) {
                $t = strtoupper((string) ($row['ticker'] ?? ''));
                if (!in_array($t, $watchlist, true)) {
                    continue;
                }
                if (isset($row['reco_swing'])) {
                    $watchlistRecos[$t] = (string) $row['reco_swing'];
                }
                $watchlistInfo[$t] = [
                    'companyName' => $row['company_name'] ?? null,
                    'cvsSwing'    => isset($row['cvs_swing']) ? (float) $row['cvs_swing'] : null,
                    'cvsFund'     => isset($row['cvs_fund'])  ? (float) $row['cvs_fund']  : null,
                    'recoSwing'   => $row['reco_swing'] ?? null,
                    'recoFund'    => $row['reco_fund']  ?? null,
                ];
            }
        }

        Response::view('dashboard', [
            'watchlist'      => $watchlist,
            'watchlistRecos' => $watchlistRecos, // ticker→reco_swing map for chip colours
            'watchlistInfo'  => $watchlistInfo,  // ticker→{companyName, cvsSwing, cvsFund, recoSwing, recoFund} for hover tooltip
            'history'        => $history,
            'alertsEnabled'  => $alertsEnabled,
            'emailVerified'  => $emailVerified,
        ]);
    }

    // ------------------------------------------------------------------
    // POST /analysis — batch ticker analysis
    // ------------------------------------------------------------------

    public function analyse(Request $req): void
    {
        AuthController::requireAuth();

        if (!$req->verifyCsrf()) {
            Response::json(['error' => 'Nieprawidłowy token CSRF.'], 403);
            return;
        }

        // Accept comma-separated or newline-separated tickers.
        $raw     = (string) $req->input('tickers', '');
        $tickers = $this->parseTickers($raw);

        if (empty($tickers)) {
            Response::json(['error' => 'Podaj co najmniej jeden ticker.'], 422);
            return;
        }

        // Soft cap enforced by config (data_source.max_tickers).
        $maxTickers = $this->fetcher->maxTickers();
        if (count($tickers) > $maxTickers) {
            Response::json([
                'error' => "Maksymalnie {$maxTickers} tickerów na raz.",
            ], 422);
            return;
        }

        $userId  = (int) $_SESSION['user_id'];
        $results = [];
        foreach ($tickers as $ticker) {
            $financials = $this->fetcher->fetch($ticker);

            if ($financials === null) {
                // No data fetched — nothing meaningful to persist.
                $results[] = [
                    'ticker' => $ticker,
                    'error'  => 'Nie udało się pobrać danych. Sprawdź symbol.',
                ];
                continue;
            }

            $resultArr = $this->model->calculate($ticker, $financials)->toArray();
            $this->history->save($userId, $ticker, $resultArr);
            $results[] = $resultArr;
        }

        Response::json(['results' => $results]);
    }

    // ------------------------------------------------------------------
    // GET /analysis/{ticker} — single-ticker detail view  (S-03)
    // ------------------------------------------------------------------

    public function show(Request $req): void
    {
        AuthController::requireAuth();

        $ticker     = strtoupper(trim((string) $req->param('ticker', '')));
        $financials = $this->fetcher->fetch($ticker);

        if ($financials === null) {
            $userId = (int) $_SESSION['user_id'];
            Response::view('analysis', [
                'ticker'     => $ticker,
                'result'     => null,
                'financials' => null,
                'isWatched'  => $this->watchlist->isWatched($userId, $ticker),
                'trajectory' => null,
                'execPlan'   => null,
                'error'      => 'Nie udało się pobrać danych. Sprawdź symbol.',
            ]);
            return;
        }

        $result    = $this->model->calculate($ticker, $financials);
        $userId    = (int) $_SESSION['user_id'];
        $isWatched = $this->watchlist->isWatched($userId, $ticker);
        $trajectory = $this->buildTrajectory($ticker, $isWatched);

        // Phase 8 (slice 2): ATR entry zone + stops from daily OHLC (read-only).
        $execPlan = (!empty($financials['daily_ohlc']) && isset($financials['current_price']))
            ? AtrZoneCalculator::compute($financials['daily_ohlc'], (float) $financials['current_price'], $this->atrZonesConfig)
            : null;

        $aiConfig    = require dirname(__DIR__, 2) . '/config/ai.php';
        $gate        = new ProGate(new ProRepository(), new AiUsageRepository(), $aiConfig);
        $aiRepo      = new AiAnalysisRepository();
        $freshDays   = (int) ($aiConfig['pro']['cache_fresh_days']  ?? 7);
        $minHours    = (int) ($aiConfig['pro']['refresh_min_hours'] ?? 24);
        $cachedAi    = $aiRepo->isFresh($ticker, $freshDays) ? $aiRepo->findByTicker($ticker) : null;
        $aiCanRefresh = $gate->canGenerate($userId)
            && $aiRepo->findByTicker($ticker) !== null
            && $aiRepo->needsRefresh($ticker, $minHours);

        $alertRepo          = new AlertRepository();
        $alertsEnabled      = $alertRepo->isGlobalEnabled($userId);
        $tickerAlertDisabled = $alertRepo->isTickerDisabled($userId, $ticker);
        $priceAlertEnabled  = (new PriceAlertRepository())->isEnabled($userId, $ticker);

        $translationRepo   = new TranslationRepository();
        $cachedDescriptionPl = $translationRepo->find($ticker, 'pl', 'long_description');

        Response::view('analysis', [
            'ticker'               => $ticker,
            'result'               => $result->toArray(),
            'financials'           => $financials,
            'isWatched'            => $isWatched,
            'canGenerateAi'        => $gate->canGenerate($userId),
            'aiUsage'              => $gate->getUsage($userId),
            'cachedAi'             => $cachedAi,
            'aiCanRefresh'         => $aiCanRefresh,
            'alertsEnabled'        => $alertsEnabled,        // S-04
            'tickerAlertDisabled'  => $tickerAlertDisabled,  // S-04
            'cachedDescriptionPl'  => $cachedDescriptionPl,  // on-device translation cache
            'trajectory'           => $trajectory,           // Phase 8 slice 1 — CVS sparkline
            'execPlan'             => $execPlan,             // Phase 8 slice 2 — ATR entry zone + stops
            'priceAlertEnabled'    => $priceAlertEnabled,    // Phase 8 slice 3 — "price in zone" alert
            'error'                => null,
        ]);
    }

    /**
     * Build the CVS Swing trajectory summary for the detail page (Phase 8, slice 1).
     *
     * Only watched tickers carry a trajectory — snapshots are collected for the
     * watchlist union (FR-001). Non-watched → null (template shows the empty state).
     *
     * @return array<string, mixed>|null
     */
    private function buildTrajectory(string $ticker, bool $isWatched): ?array
    {
        if (!$isWatched) {
            return null;
        }

        $since = (new DateTimeImmutable())->modify('-' . $this->trajectoryWindowDays . ' days');
        $rows  = (new CvsSnapshotRepository())->findTrajectory($ticker, $since, $this->modelVersion);

        return TrajectoryCalculator::summarise($rows, $this->trajectoryMinPoints);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return string[] */
    private function parseTickers(string $raw): array
    {
        $items = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(
            static fn(string $t) => strtoupper(trim($t)),
            $items
        );
    }
}
