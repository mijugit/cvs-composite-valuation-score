<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\AuthController;
use CVS\Core\Database;
use CVS\Core\Request;
use CVS\Core\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only portfolio view controller.
 *
 * Renders the global virtual portfolio page at GET /portfolio.
 * Accessible to all authenticated users (FR-017: global portfolio).
 */
class PortfolioController
{
    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $cvsConfig       = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $portfolioConfig = require dirname(__DIR__, 2) . '/config/portfolio.php';
        $liveModelVersion = (string) ($cvsConfig['model_version'] ?? '4.0');

        $db            = Database::connection();
        $portfolioRepo = new PortfolioRepository($db);
        $calendar      = new MarketCalendar($portfolioConfig);

        $state      = $portfolioRepo->getCurrentState();
        $holdings   = $portfolioRepo->getCurrentHoldingsWithPrice($liveModelVersion);
        $latestCycle = $portfolioRepo->getLatestCycle();

        // Live re-pricing: refresh quotes on load (≤ every 15 min, session-cached),
        // falling back to the last snapshot price per ticker if the quote fails.
        $tickers  = array_map(static fn(array $h): string => (string) $h['ticker'], $holdings);
        $fallback = [];
        foreach ($holdings as $h) {
            $fallback[(string) $h['ticker']] = (float) $h['live_price'];
        }
        $liveMap = $this->resolveLivePrices($tickers, $fallback, $cvsConfig['data_source'] ?? []);

        // Per-position justification from the last rebalance (info popover).
        $reasons = $portfolioRepo->getLatestReasonsByTicker();

        // Overlay live prices, recompute value + unrealized P&L per holding.
        foreach ($holdings as &$h) {
            $t     = (string) $h['ticker'];
            $px    = $liveMap[$t]['price']   ?? (float) $h['live_price'];
            $isLive = $liveMap[$t]['is_live'] ?? false;
            $avg   = (float) $h['avg_entry_price'];

            $h['live_price']        = $px;
            $h['price_is_live']     = $isLive;
            $h['value_usd']         = round((int) $h['quantity'] * $px, 2);
            $h['pnl_pct']           = $avg > 0 ? round(($px - $avg) / $avg * 100, 2) : 0.0;
            $h['reason']            = $reasons[$t] ?? null;
        }
        unset($h);

        $totalValue = round(
            (float) $state['cash'] + array_sum(array_column($holdings, 'value_usd')),
            2
        );

        // Find next NYSE trading day (up to 7 days ahead) for the empty-cycle message.
        $nextTradingDay = null;
        $check = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
        for ($i = 0; $i < 7; $i++) {
            if ($calendar->isMarketDay($check)) {
                $nextTradingDay = $check;
                break;
            }
            $check = $check->modify('+1 day');
        }

        Response::view('portfolio', compact(
            'state',
            'holdings',
            'latestCycle',
            'totalValue',
            'nextTradingDay',
            'portfolioConfig',
        ));
    }

    /**
     * Live price map per ticker, session-cached so we never hit Yahoo more than
     * once per 15 minutes. On a cache miss (or when a newly-held ticker is not yet
     * cached) we refetch all current tickers. Per-ticker failures fall back to the
     * supplied snapshot price inside LivePriceProvider.
     *
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

        $ttl   = 900; // 15 minutes
        $key   = 'cvs_portfolio_px';
        $now   = time();

        $cached     = $_SESSION[$key]          ?? null;
        $cachedTs   = (int) ($_SESSION[$key . '_ts'] ?? 0);
        $hasAll     = is_array($cached)
            && array_diff($tickers, array_keys($cached)) === [];

        if ($hasAll && ($now - $cachedTs) < $ttl) {
            /** @var array<string, array{price: float, is_live: bool}> $cached */
            return $cached;
        }

        // Bound each live-quote call tightly so a slow Yahoo never hangs page load;
        // any timeout falls back to the snapshot price inside LivePriceProvider.
        $fastSource = array_merge($dataSource, ['timeout_seconds' => 5]);
        $provider = new LivePriceProvider(new FinancialDataFetcher($fastSource));
        $resolved = $provider->fetch($tickers, $fallback);

        $_SESSION[$key]          = $resolved;
        $_SESSION[$key . '_ts']  = $now;

        return $resolved;
    }
}
