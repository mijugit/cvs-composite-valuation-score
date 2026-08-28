<?php

declare(strict_types=1);

namespace CVS\LlmFree;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\AuthController;
use CVS\Charts\WalletNavChartService;
use CVS\Core\Database;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\LlmGemini\LlmGeminiCycleRepository;
use CVS\LlmGptLuna\LlmGptLunaCycleRepository;
use CVS\Portfolio\CycleRepository;
use CVS\Portfolio\LivePriceProvider;

/**
 * Read-only LLM_Free_Wallet view controller.
 *
 * Renders the wallet page at GET /llm-free. Mirrors
 * CVS\Portfolio\PortfolioController::index()'s live-repricing shape exactly
 * (same LivePriceProvider reuse, same session-cache pattern) plus the legend
 * history that is this wallet's whole point.
 */
class LlmFreeController
{
    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $cvsConfig        = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $walletConfig     = require dirname(__DIR__, 2) . '/config/llm-free-wallet.php';
        // holidays only — shared NYSE calendar fact, not module logic (same
        // reuse as bin/llm-free-wallet-rebalance.php).
        $portfolioConfig  = require dirname(__DIR__, 2) . '/config/portfolio.php';
        $marketHolidays   = $portfolioConfig['holidays'] ?? [];
        $liveModelVersion = (string) ($cvsConfig['model_version'] ?? '4.0');

        $db         = Database::connection();
        $walletRepo = new LlmFreeRepository($db);

        $state    = $walletRepo->getCurrentState();
        $holdings = $walletRepo->getCurrentHoldingsWithPrice($liveModelVersion);

        // Live re-pricing: refresh quotes on load (≤ every 15 min, session-cached),
        // falling back to the last snapshot price per ticker if the quote fails.
        // Uses its own cache key (not the baseline wallet's) — sharing one slot
        // between two independently-held ticker sets would cause each wallet's
        // page load to overwrite the other's cached entries with its own subset.
        $tickers  = array_map(static fn(array $h): string => (string) $h['ticker'], $holdings);
        $fallback = [];
        foreach ($holdings as $h) {
            $fallback[(string) $h['ticker']] = (float) $h['live_price'];
        }
        $liveMap = $this->resolveLivePrices($tickers, $fallback, $cvsConfig['data_source'] ?? []);

        foreach ($holdings as &$h) {
            $t      = (string) $h['ticker'];
            $px     = $liveMap[$t]['price']   ?? (float) $h['live_price'];
            $isLive = $liveMap[$t]['is_live'] ?? false;
            $avg    = (float) $h['avg_entry_price'];

            $h['live_price']    = $px;
            $h['price_is_live'] = $isLive;
            $h['value_usd']     = round((int) $h['quantity'] * $px, 2);
            $h['pnl_pct']       = $avg > 0 ? round(($px - $avg) / $avg * 100, 2) : 0.0;
        }
        unset($h);

        $totalValue = round(
            (float) $state['cash'] + array_sum(array_column($holdings, 'value_usd')),
            2
        );

        // Same count the model itself reads as memory — what the user sees
        // matches what the model "remembers" on the next cycle.
        $legendHistory = $walletRepo->getLegendHistory((int) ($walletConfig['legend_context_count'] ?? 10));

        // NAV comparison chart (change: wallet-nav-chart, four-way since
        // llm-gpt-luna-wallet) — all four wallets + both benchmarks, so
        // every wallet page shows the identical comparison instead of the
        // viewer's own wallet plus a partial subset of the others.
        $navChart = (new WalletNavChartService(
            new CycleRepository($db),
            new LlmFreeCycleRepository($db),
            new FinancialDataFetcher($cvsConfig['data_source'] ?? []),
            new LlmGeminiCycleRepository($db),
            new LlmGptLunaCycleRepository($db),
        ))->fetch();
        $chartSeries = $navChart['chartSeries'];
        $chartD0     = $navChart['d0'];

        Response::view('llm-free', compact(
            'state',
            'holdings',
            'totalValue',
            'walletConfig',
            'legendHistory',
            'marketHolidays',
            'chartSeries',
            'chartD0',
        ));
    }

    /**
     * @param array<int, string>   $tickers
     * @param array<string, float> $fallback   ticker → last snapshot USD price
     * @param array<string, mixed> $dataSource cvs-weights.php['data_source']
     * @return array<string, array{price: float, is_live: bool}>
     */
    private function resolveLivePrices(array $tickers, array $fallback, array $dataSource): array
    {
        if ($tickers === []) {
            return [];
        }

        $ttl = 900; // 15 minutes
        $key = 'cvs_llmfree_px';
        $now = time();

        $cached   = $_SESSION[$key]             ?? null;
        $cachedTs = (int) ($_SESSION[$key . '_ts'] ?? 0);
        $hasAll   = is_array($cached) && array_diff($tickers, array_keys($cached)) === [];

        if ($hasAll && ($now - $cachedTs) < $ttl) {
            /** @var array<string, array{price: float, is_live: bool}> $cached */
            return $cached;
        }

        $fastSource = array_merge($dataSource, ['timeout_seconds' => 5]);
        $provider   = new LivePriceProvider(new FinancialDataFetcher($fastSource));
        $resolved   = $provider->fetch($tickers, $fallback);

        $_SESSION[$key]         = $resolved;
        $_SESSION[$key . '_ts'] = $now;

        return $resolved;
    }
}
