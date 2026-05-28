<?php

declare(strict_types=1);

namespace CVS\CVS;

use CVS\Auth\AuthController;
use CVS\Api\FinancialDataFetcher;
use CVS\Core\Request;
use CVS\Core\Response;

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

    public function __construct()
    {
        $config        = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->model   = new CVSModel($config);
        $this->fetcher = new FinancialDataFetcher($config['data_source']);
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function dashboard(Request $req): void
    {
        AuthController::requireAuth();
        Response::view('dashboard', []);
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

        $results = [];
        foreach ($tickers as $ticker) {
            $financials = $this->fetcher->fetch($ticker);

            if ($financials === null) {
                $results[] = [
                    'ticker' => $ticker,
                    'error'  => 'Nie udało się pobrać danych. Sprawdź symbol.',
                ];
                continue;
            }

            $results[] = $this->model->calculate($ticker, $financials)->toArray();
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
            Response::view('analysis', [
                'ticker'     => $ticker,
                'result'     => null,
                'financials' => null,
                'error'      => 'Nie udało się pobrać danych. Sprawdź symbol.',
            ]);
            return;
        }

        $result = $this->model->calculate($ticker, $financials);

        Response::view('analysis', [
            'ticker'     => $ticker,
            'result'     => $result->toArray(),
            'financials' => $financials,   // S-03: raw data for detail panel
            'error'      => null,
        ]);
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
