<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\AuthController;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\CVS\CVSModel;
use CVS\Pro\AiUsageRepository;
use CVS\Pro\ProGate;
use CVS\Pro\ProRepository;

/**
 * Handles POST /analysis/{ticker}/generate-ai.
 *
 * Flow: auth → PRO gate → cache check → generate → log → save → respond.
 * Always returns JSON; never throws. Guardrail: failure returns ok:false,
 * page stays functional.
 */
class AiAnalysisController
{
    private AiAnalysisRepository $aiRepo;
    private ProGate               $gate;
    private FinancialDataFetcher  $fetcher;
    private CVSModel              $model;
    private AiDivergenceService   $service;
    private AiUsageRepository     $usageRepo;

    /** @param array<string, mixed> $aiConfig Full config/ai.php */
    public function __construct(array $aiConfig)
    {
        $this->aiRepo    = new AiAnalysisRepository();
        $proRepo         = new ProRepository();
        $this->usageRepo = new AiUsageRepository();
        $this->gate      = new ProGate($proRepo, $this->usageRepo, $aiConfig);

        $cvsConfig      = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->fetcher  = new FinancialDataFetcher($cvsConfig['data_source']);
        $this->model    = new CVSModel($cvsConfig);
        $this->service  = new AiDivergenceService(ClaudeClientFactory::fromConfig($aiConfig));
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

        // PRO gate check.
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

        // Call AI service.
        $aiResult = $this->service->generate($ticker, $cvsResult, $financials);

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
}
