<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\CVS\Valuation\MedianResolver;
use CVS\Api\FinancialDataFetcher;
use CVS\Auth\AuthController;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\CVS\CVSModel;
use CVS\Execution\AtrZoneCalculator;
use CVS\Pro\AiUsageRepository;
use CVS\Pro\ProGate;
use CVS\Pro\ProRepository;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;
use DateTimeImmutable;

/**
 * Handles POST /analysis/{ticker}/generate-ai, POST /analysis/{ticker}/share-prompt,
 * POST /analysis/{ticker}/critical-review, and GET /analysis/{ticker}/critical-review/status.
 *
 * generate(): auth → PRO gate → cache check → Claude → log → save → respond.
 * sharePrompt(): auth → cache check → rebuild data block → assemble export prompt → respond.
 * criticalReview(): auth → require fresh stage-1 → PRO gate → log → markPending →
 *   fire bin/generate_critical_review(_gemini).php in the background → 202
 *   (change: cvs-ai-critical-review). Optional `provider` param (claude|gemini,
 *   defaults to claude) selects which worker/row — change: critical-review-models.
 * criticalReviewStatus(): auth → read ai_critical_reviews row for (ticker, provider)
 *   → respond per status (completed responses include probability fields).
 * Always returns JSON; never throws.
 */
class AiAnalysisController
{
    private AiAnalysisRepository        $aiRepo;
    private ?ProGate                    $gate;
    private FinancialDataFetcher        $fetcher;
    private CVSModel                    $model;
    private AiDivergenceService         $service;
    private ?AiUsageRepository          $usageRepo;
    private ?AiCriticalReviewRepository $criticalReviewRepo;
    /** @var array<string, mixed> */
    private array $cvsConfig;

    /**
     * @param array<string, mixed>   $aiConfig Full config/ai.php
     *
     * Optional parameters allow injecting test doubles without hitting the
     * database. When all four are provided the PRO gate (generate-only) and
     * the critical-review repository are skipped; sharePrompt() never needs them.
     */
    public function __construct(
        array $aiConfig,
        ?AiAnalysisRepository $aiRepo   = null,
        ?FinancialDataFetcher  $fetcher  = null,
        ?CVSModel              $model    = null,
        ?AiDivergenceService   $service  = null
    ) {
        $this->cvsConfig = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->aiRepo    = $aiRepo   ?? new AiAnalysisRepository();
        $this->fetcher   = $fetcher  ?? new FinancialDataFetcher($this->cvsConfig['data_source']);
        $this->model     = $model    ?? new CVSModel($this->cvsConfig);
        $this->service   = $service  ?? new AiDivergenceService(ClaudeClientFactory::fromConfig($aiConfig));

        // PRO gate + critical-review repo only needed by generate()/criticalReview();
        // skip when test doubles are injected.
        if ($aiRepo === null && $fetcher === null && $model === null && $service === null) {
            $proRepo                  = new ProRepository();
            $this->usageRepo          = new AiUsageRepository();
            $this->gate               = new ProGate($proRepo, $this->usageRepo, $aiConfig);
            $this->criticalReviewRepo = new AiCriticalReviewRepository();
        } else {
            $this->gate               = null;
            $this->usageRepo          = null;
            $this->criticalReviewRepo = null;
        }
    }

    // ------------------------------------------------------------------
    // POST /analysis/{ticker}/generate-ai
    // ------------------------------------------------------------------

    public function generate(Request $req): void
    {
        // AI generation can take up to AI_TOTAL_TIMEOUT (.env, currently ~100s)
        // — extend PHP execution limit with margin under the panel's 180s cap.
        @set_time_limit(150);

        AuthController::requireAuth();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'Błąd CSRF.'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        if ($ticker === '') {
            Response::json(['ok' => false, 'message' => 'Brak symbolu spółki.'], 400);
            return;
        }

        $userId       = (int) $_SESSION['user_id'];
        $aiConfig     = require dirname(__DIR__, 2) . '/config/ai.php';
        $freshDays    = (int) ($aiConfig['pro']['cache_fresh_days']   ?? 7);
        $minHours     = (int) ($aiConfig['pro']['refresh_min_hours']  ?? 24);
        $isForceRefresh = ((string) $req->input('force', '')) === '1';

        // PRO gate check (gate is null only when test doubles are injected).
        if ($this->gate === null || $this->usageRepo === null) {
            Response::json(['ok' => false, 'message' => 'Controller not configured for AI generation.'], 500);
            return;
        }
        if (!$this->gate->canGenerate($userId)) {
            $usage = $this->gate->getUsage($userId);
            if ($usage['today'] >= $usage['daily_limit']) {
                $msg = 'Osiągnięto dzienny limit analiz AI. Spróbuj jutro.';
            } elseif ($usage['month'] >= $usage['monthly_limit']) {
                $msg = 'Osiągnięto miesięczny limit analiz AI.';
            } else {
                $msg = 'Brak aktywnego kodu PRO. Aktywuj kod aby generować analizy AI.';
            }
            Response::json(['ok' => false, 'message' => $msg], 403);
            return;
        }

        // Serve from cache unless force-refresh requested.
        if (!$isForceRefresh && $this->aiRepo->isFresh($ticker, $freshDays)) {
            $cached = $this->aiRepo->findByTicker($ticker);
            if ($cached !== null) {
                Response::json([
                    'ok'           => true,
                    'cached'       => true,
                    'content'      => $cached['content'],
                    'generated_at' => $cached['generated_at'],
                ]);
                return;
            }
        }

        // Force-refresh: enforce min-age to prevent API spam.
        if ($isForceRefresh && !$this->aiRepo->needsRefresh($ticker, $minHours)) {
            Response::json([
                'ok'      => false,
                'message' => "Odświeżenie możliwe po {$minHours} godzinach od ostatniej analizy.",
            ], 429);
            return;
        }

        // Fetch financial data.
        $financials = $this->fetcher->fetch($ticker);
        if ($financials === null) {
            Response::json([
                'ok'      => false,
                'message' => 'Nie udało się pobrać danych rynkowych. Sprawdź symbol i spróbuj ponownie.',
            ], 503);
            return;
        }

        // Calculate CVS scores.
        $cvsResult = $this->model->calculate($ticker, $financials)->toArray();

        // Calculate CVS implied fair value (sector-median-parity price).
        $cvsFairPrice = FairPriceCalculator::compute($financials, $this->cvsConfig, MedianResolver::fromConfig($this->cvsConfig));

        // Phase 8 enrichment — CVS trajectory + ATR execution plan for the prompt.
        $modelVersion = (string) ($this->cvsConfig['model_version'] ?? '');
        $trajWindow   = (int) ($this->cvsConfig['trajectory']['window_days'] ?? 90);
        $trajMin      = (int) ($this->cvsConfig['trajectory']['min_points']  ?? 2);
        $since        = (new DateTimeImmutable())->modify('-' . $trajWindow . ' days');
        $trajRows     = (new CvsSnapshotRepository())->findTrajectory($ticker, $since, $modelVersion);
        $trajectory   = TrajectoryCalculator::summarise($trajRows, $trajMin);

        $atrCfg   = is_array($this->cvsConfig['atr_zones'] ?? null) ? $this->cvsConfig['atr_zones'] : [];
        $execPlan = (!empty($financials['daily_ohlc']) && isset($financials['current_price']))
            ? AtrZoneCalculator::compute($financials['daily_ohlc'], (float) $financials['current_price'], $atrCfg)
            : null;

        // Call AI service.
        $aiResult = $this->service->generate($ticker, $cvsResult, $financials, $cvsFairPrice, $trajectory, $execPlan);

        if (!$aiResult->ok) {
            Response::json([
                'ok'      => false,
                'message' => 'Analiza AI niedostępna — spróbuj ponownie za chwilę.',
            ], 503);
            return;
        }

        // Persist analysis and log usage.
        $usage     = $aiResult->usage;
        $tokensIn  = $usage !== null ? $usage->inputTokens  : 0;
        $tokensOut = $usage !== null ? $usage->outputTokens : 0;
        $model       = $aiResult->model ?? '';
        $generatedAt = date('Y-m-d H:i');

        $this->aiRepo->save($ticker, (string) $aiResult->text, $model,
            $tokensIn, $tokensOut, $userId);
        $this->usageRepo->log($userId, $this->gate->getSessionCode(),
            $tokensIn, $tokensOut);

        Response::json([
            'ok'           => true,
            'cached'       => false,
            'content'      => $aiResult->text,
            'generated_at' => $generatedAt,
        ]);
    }

    // ------------------------------------------------------------------
    // POST /analysis/{ticker}/share-prompt
    // ------------------------------------------------------------------

    /**
     * Build and return a one-directional export prompt the user can paste
     * into any external LLM. No PRO gate — available to any logged-in user
     * who already has a cached AI analysis for the ticker.
     */
    public function sharePrompt(Request $req): void
    {
        AuthController::requireAuth();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'Błąd CSRF.'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        if ($ticker === '') {
            Response::json(['ok' => false, 'message' => 'Brak symbolu spółki.'], 400);
            return;
        }

        $lang = strtolower(trim((string) $req->input('lang', 'pl')));

        // Require an existing cached AI analysis — we enrich it, not replace it.
        $cached = $this->aiRepo->findByTicker($ticker);
        if ($cached === null) {
            Response::json([
                'ok'      => false,
                'message' => 'Najpierw wygeneruj analizę AI dla tej spółki.',
            ], 409);
            return;
        }

        // Fetch fresh financial data to build the CVS data block.
        $financials = $this->fetcher->fetch($ticker);
        if ($financials === null) {
            Response::json([
                'ok'      => false,
                'message' => 'Nie udało się pobrać danych rynkowych. Spróbuj ponownie.',
            ], 503);
            return;
        }

        $cvsResult    = $this->model->calculate($ticker, $financials)->toArray();
        $cvsFairPrice = FairPriceCalculator::compute($financials, $this->cvsConfig, MedianResolver::fromConfig($this->cvsConfig));

        $modelVersion = (string) ($this->cvsConfig['model_version'] ?? '');
        $trajWindow   = (int) ($this->cvsConfig['trajectory']['window_days'] ?? 90);
        $trajMin      = (int) ($this->cvsConfig['trajectory']['min_points']  ?? 2);
        $since        = (new DateTimeImmutable())->modify('-' . $trajWindow . ' days');
        $trajRows     = (new CvsSnapshotRepository())->findTrajectory($ticker, $since, $modelVersion);
        $trajectory   = TrajectoryCalculator::summarise($trajRows, $trajMin);

        $atrCfg   = is_array($this->cvsConfig['atr_zones'] ?? null) ? $this->cvsConfig['atr_zones'] : [];
        $execPlan = (!empty($financials['daily_ohlc']) && isset($financials['current_price']))
            ? AtrZoneCalculator::compute($financials['daily_ohlc'], (float) $financials['current_price'], $atrCfg)
            : null;

        $dataBlock = $this->service->buildDataBlock(
            $ticker, $cvsResult, $financials, $cvsFairPrice, $trajectory, $execPlan
        );

        $sector = (string) ($financials['sector'] ?? 'Unknown');

        $prompt = (new ExportPromptBuilder())->build(
            $ticker,
            $sector,
            $dataBlock,
            (string) ($cached['content'] ?? ''),
            $lang
        );

        Response::json(['ok' => true, 'prompt' => $prompt, 'lang' => $lang]);
    }

    // ------------------------------------------------------------------
    // POST /analysis/{ticker}/critical-review — change: cvs-ai-critical-review
    // ------------------------------------------------------------------

    /**
     * Start a stage-2 "Recenzja krytyczna" background job. Returns immediately
     * (202) — the actual Claude/Gemini call (web search enabled, measured
     * 90-140s+) runs in a detached CLI process (bin/generate_critical_review.php
     * or bin/generate_critical_review_gemini.php, selected by the `provider`
     * param) fired via exec($cmd . ' &'). Poll criticalReviewStatus() for the
     * result. Each provider is an independent row/job — triggering one never
     * blocks or is blocked by the other (change: critical-review-models).
     */
    public function criticalReview(Request $req): void
    {
        AuthController::requireAuth();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'Błąd CSRF.'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        if ($ticker === '') {
            Response::json(['ok' => false, 'message' => 'Brak symbolu spółki.'], 400);
            return;
        }

        // Backward-compatible: no provider param defaults to Claude — old
        // callers keep working unchanged (change: critical-review-models).
        // NOTE: `provider` travels in the POST body (form-urlencoded), not
        // the route path — must read via input(), not param() (param() only
        // reads {ticker}-style route params, see Request::param()).
        $provider = strtolower(trim((string) $req->input('provider', CriticalReviewProvider::CLAUDE)));
        if (!CriticalReviewProvider::isValid($provider)) {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowy dostawca.'], 400);
            return;
        }

        if ($this->gate === null || $this->usageRepo === null || $this->criticalReviewRepo === null) {
            Response::json(['ok' => false, 'message' => 'Controller not configured for AI generation.'], 500);
            return;
        }

        if (!function_exists('exec')) {
            Response::json(['ok' => false, 'message' => 'Generowanie w tle jest niedostępne na tym serwerze.'], 500);
            return;
        }

        $userId   = (int) $_SESSION['user_id'];
        $aiConfig = require dirname(__DIR__, 2) . '/config/ai.php';
        $freshDays = (int) ($aiConfig['pro']['cache_fresh_days'] ?? 7);

        // The critical review deepens an existing stage-1 analysis — require
        // one to already be fresh, rather than silently generating it first.
        if (!$this->aiRepo->isFresh($ticker, $freshDays)) {
            Response::json([
                'ok'      => false,
                'message' => 'Najpierw wygeneruj świeżą analizę AI (etap 1) dla tej spółki.',
            ], 409);
            return;
        }

        if ($this->criticalReviewRepo->isPending($ticker, $provider)) {
            Response::json([
                'ok'      => false,
                'message' => 'Recenzja krytyczna jest już w trakcie generowania.',
            ], 409);
            return;
        }

        if (!$this->gate->canGenerate($userId)) {
            $usage = $this->gate->getUsage($userId);
            if ($usage['today'] >= $usage['daily_limit']) {
                $msg = 'Osiągnięto dzienny limit analiz AI. Spróbuj jutro.';
            } elseif ($usage['month'] >= $usage['monthly_limit']) {
                $msg = 'Osiągnięto miesięczny limit analiz AI.';
            } else {
                $msg = 'Brak aktywnego kodu PRO. Aktywuj kod aby generować analizy AI.';
            }
            Response::json(['ok' => false, 'message' => $msg], 403);
            return;
        }

        // Logged at acceptance time — the background job's own token counts
        // land in ai_critical_reviews via markCompleted(); this entry only
        // enforces the PRO usage quota against a duplicate rapid-fire request.
        $this->usageRepo->log($userId, $this->gate->getSessionCode(), 0, 0);
        $this->criticalReviewRepo->markPending($ticker, $provider, $userId);

        $phpBin     = '/usr/local/bin/php82';
        $scriptName = $provider === CriticalReviewProvider::GEMINI
            ? 'generate_critical_review_gemini.php'
            : 'generate_critical_review.php';
        $logName    = $provider === CriticalReviewProvider::GEMINI
            ? 'critical_review_gemini.log'
            : 'critical_review.log';
        $script = dirname(__DIR__, 2) . '/bin/' . $scriptName;
        $logDir = dirname(__DIR__, 2) . '/logs';
        $cmd    = $phpBin . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg($ticker)
                . ' ' . escapeshellarg((string) $userId)
                . ' >> ' . escapeshellarg($logDir . '/' . $logName)
                . ' 2>&1';
        exec($cmd . ' &');

        Response::json(['ok' => true, 'status' => 'pending'], 202);
    }

    // ------------------------------------------------------------------
    // GET /analysis/{ticker}/critical-review/status — change: cvs-ai-critical-review
    // ------------------------------------------------------------------

    public function criticalReviewStatus(Request $req): void
    {
        AuthController::requireAuth();

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        if ($ticker === '') {
            Response::json(['ok' => false, 'message' => 'Brak symbolu spółki.'], 400);
            return;
        }

        // `provider` travels as a query-string param on this GET endpoint —
        // must read via query(), not param() (see note in criticalReview()).
        $provider = strtolower(trim((string) $req->query('provider', CriticalReviewProvider::CLAUDE)));
        if (!CriticalReviewProvider::isValid($provider)) {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowy dostawca.'], 400);
            return;
        }

        if ($this->criticalReviewRepo === null) {
            Response::json(['ok' => false, 'message' => 'Controller not configured for AI generation.'], 500);
            return;
        }

        $row = $this->criticalReviewRepo->findByTickerAndProvider($ticker, $provider);
        if ($row === null) {
            Response::json(['ok' => true, 'status' => 'none']);
            return;
        }

        $status = (string) $row['status'];

        if ($status === 'completed') {
            $generatedAt = (string) ($row['generated_at'] ?? '');
            $stale       = true;
            $generatedAtDt = $generatedAt !== '' ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $generatedAt) : false;
            if ($generatedAtDt !== false) {
                $stale = $generatedAtDt < (new DateTimeImmutable())->modify('-48 hours');
            }

            $sources = json_decode((string) ($row['sources'] ?? '[]'), true);

            Response::json([
                'ok'                => true,
                'status'            => 'completed',
                'content'           => (string) ($row['content'] ?? ''),
                'sources'           => is_array($sources) ? $sources : [],
                'generated_at'      => $generatedAt,
                'stale'             => $stale,
                'bull_probability'  => isset($row['bull_probability']) ? (int) $row['bull_probability'] : null,
                'bear_probability'  => isset($row['bear_probability']) ? (int) $row['bear_probability'] : null,
                'probability_rationale' => $row['probability_rationale'] ?? null,
            ]);
            return;
        }

        if ($status === 'failed') {
            Response::json([
                'ok'            => true,
                'status'        => 'failed',
                'error_message' => (string) ($row['error_message'] ?? 'Nieznany błąd.'),
            ]);
            return;
        }

        Response::json(['ok' => true, 'status' => 'pending']);
    }
}
