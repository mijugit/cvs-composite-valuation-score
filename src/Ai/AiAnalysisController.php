<?php

declare(strict_types=1);

namespace CVS\Ai;

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
 * Handles POST /analysis/{ticker}/generate-ai and POST /analysis/{ticker}/share-prompt.
 *
 * generate(): auth → PRO gate → cache check → Claude → log → save → respond.
 * sharePrompt(): auth → cache check → rebuild data block → assemble export prompt → respond.
 * Always returns JSON; never throws.
 */
class AiAnalysisController
{
    private AiAnalysisRepository $aiRepo;
    private ?ProGate              $gate;
    private FinancialDataFetcher  $fetcher;
    private CVSModel              $model;
    private AiDivergenceService   $service;
    private ?AiUsageRepository    $usageRepo;
    /** @var array<string, mixed> */
    private array $cvsConfig;

    /**
     * @param array<string, mixed>   $aiConfig Full config/ai.php
     *
     * Optional parameters allow injecting test doubles without hitting the
     * database. When all four are provided the PRO gate (generate-only) is
     * skipped; sharePrompt() never needs it.
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

        // PRO gate only needed by generate(); skip when test doubles are injected.
        if ($aiRepo === null && $fetcher === null && $model === null && $service === null) {
            $proRepo         = new ProRepository();
            $this->usageRepo = new AiUsageRepository();
            $this->gate      = new ProGate($proRepo, $this->usageRepo, $aiConfig);
        } else {
            $this->gate      = null;
            $this->usageRepo = null;
        }
    }

    // ------------------------------------------------------------------
    // POST /analysis/{ticker}/generate-ai
    // ------------------------------------------------------------------

    public function generate(Request $req): void
    {
        // AI generation can take up to 60s — extend PHP execution limit.
        @set_time_limit(120);

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
        $cvsFairPrice = $this->calcFairPrice($financials);

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
        $cvsFairPrice = $this->calcFairPrice($financials);

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

    /**
     * CVS implied fair value: price at which Valuation pillar = 50 (sector-median parity).
     * Fair EV = median_ev_fcf × FCF × (1 + growth_capped)²
     * Fair Price = (Fair EV - debt + cash) / shares
     *
     * @param array<string, mixed> $financials
     */
    private function calcFairPrice(array $financials): ?float
    {
        $sector     = (string) ($financials['sector'] ?? 'DEFAULT');
        $benchmarks = $this->cvsConfig['benchmarks'] ?? [];
        $bm         = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? [];
        $medEvFcf   = (float) ($bm['median_ev_fcf'] ?? 0);
        $maxGrowth  = (float) ($bm['max_growth']    ?? 20);

        $fcf = (float) ($financials['free_cash_flow'] ?? 0);
        if ($fcf <= 0) $fcf = (float) ($financials['free_cash_flow_adjusted'] ?? 0);

        $debt   = (float) ($financials['total_debt']         ?? 0);
        $cash   = (float) ($financials['cash']               ?? 0);
        $shares = (float) ($financials['shares_outstanding'] ?? 0);

        $fwdEps   = (float) ($financials['forward_eps']  ?? 0);
        $trailEps = (float) ($financials['trailing_eps'] ?? 0);
        $growth   = null;
        if ($fwdEps > 0 && $trailEps > 0) {
            $implied = ($fwdEps / $trailEps - 1) * 100;
            if ($implied > 0 && $implied <= 200) $growth = $implied;
        }
        if ($growth === null) {
            $rg = (float) ($financials['revenue_growth'] ?? 0);
            if ($rg > 0) $growth = $rg * 100;
        }
        if ($growth !== null) $growth = min($growth, $maxGrowth);

        if ($fcf <= 0 || $growth === null || $medEvFcf <= 0 || $shares <= 0) {
            return null;
        }

        // Phase 4 (multi-currency-fx): guard removed — all inputs are in USD after normalise().
        $fwdFcf = $fcf * (1 + $growth / 100) ** 2;
        $fairEv = $medEvFcf * $fwdFcf;
        $price  = ($fairEv - $debt + $cash) / $shares;

        if ($price <= 0) {
            return null;
        }

        // Sanity bounds: fair value must be within 0.05× – 10× current price.
        // Values outside this range indicate a data quality problem (currency mismatch,
        // unusual share structure, stale FCF) — suppress rather than mislead.
        $currentPrice = (float) ($financials['current_price'] ?? 0);
        if ($currentPrice > 0) {
            $ratio = $price / $currentPrice;
            if ($ratio > 10.0 || $ratio < 0.05) {
                return null;
            }
        }

        return round($price, 2);
    }
}
