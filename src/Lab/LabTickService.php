<?php

declare(strict_types=1);

namespace CVS\Lab;

use CVS\Alerts\PriceAlertRepository;
use CVS\Api\FinancialDataFetcher;
use CVS\Portfolio\MarketCalendar;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Daily orchestration for the Lab experimental portfolios (change:
 * cvs-experimental-portfolios). Glues LabRepository (state) and LabEngine
 * (pure math) together for one calendar day; contains no scoring math itself.
 *
 * Per portfolio, in order:
 *   1. Seed on first-ever run (started_at IS NULL) — a rebalance from empty
 *      positions, tagged reason='seed'. Skips the remaining steps that day.
 *   2. Fill any pending open-execution (P2) trades decided on a prior day,
 *      using today's open.
 *   3. Apply stop-losses against today's low (ATR-based for P3, fixed % for P4).
 *   4. On the first NYSE session of the calendar month, rebalance to the
 *      current ranking (tagged reason='rebalance').
 *   5. Persist today's NAV (cash + positions at today's close, with a light
 *      fallback fetch for any held ticker missing from today's snapshot).
 *
 * Idempotency falls out of the design rather than an explicit "already ran
 * today" guard: rebalancing is driven by current DB state vs targets, so a
 * second run the same day sees positions already converged and emits no new
 * trades; NAV upserts by (portfolio, date) primary key.
 */
final class LabTickService
{
    private readonly DateTimeZone $marketTz;

    /** @param array<string, mixed> $labConfig config/lab-portfolios.php */
    public function __construct(
        private readonly LabRepository $repo,
        private readonly FinancialDataFetcher $fetcher,
        private readonly PriceAlertRepository $priceAlertRepo,
        private readonly MarketCalendar $calendar,
        private readonly array $labConfig,
        private readonly string $liveModelVersion,
        string $marketTimezone = 'America/New_York'
    ) {
        $this->marketTz = new DateTimeZone($marketTimezone);
    }

    /**
     * @return array{seeded: int, rebalanced: int, stops: int, navs: int, errors: list<string>}
     */
    public function run(DateTimeImmutable $today): array
    {
        $summary = ['seeded' => 0, 'rebalanced' => 0, 'stops' => 0, 'navs' => 0, 'errors' => []];

        if (!$this->calendar->isMarketDay($today)) {
            return $summary;
        }

        $dateStr            = $today->format('Y-m-d');
        $candidatesByTicker  = $this->candidatesByTicker($dateStr);
        $isFirstSessionMonth = $this->isFirstSessionOfMonth($today);

        foreach ($this->labConfig['portfolios'] as $code => $def) {
            try {
                $this->repo->initPortfolio(
                    $code,
                    $def['name'],
                    (string) $this->labConfig['experiment_version'],
                    (float) $this->labConfig['initial_capital_usd']
                );
                $portfolio = $this->repo->getPortfolio($code);
                if ($portfolio === null) {
                    $summary['errors'][] = sprintf('%s: portfolio registration failed', $code);
                    continue;
                }
                $rules = $def['rules'];

                if ($portfolio['started_at'] === null) {
                    if ($this->doRebalance($code, $rules, $today, $candidatesByTicker, 'seed') > 0) {
                        $summary['seeded']++;
                    }
                } else {
                    $this->fillPendingTrades($code, $today);

                    if ($this->applyStopsForPortfolio($code, $rules, $today) > 0) {
                        $summary['stops']++;
                    }

                    if ($isFirstSessionMonth
                        && $this->doRebalance($code, $rules, $today, $candidatesByTicker, 'rebalance') > 0
                    ) {
                        $summary['rebalanced']++;
                    }
                }

                $this->computeAndPersistNav($code, $today, $candidatesByTicker);
                $summary['navs']++;
            } catch (Throwable $e) {
                $summary['errors'][] = sprintf('%s: %s', $code, $e->getMessage());
            }
        }

        return $summary;
    }

    // ------------------------------------------------------------------
    // Rebalance (also used for the first-ever seed)
    // ------------------------------------------------------------------

    /**
     * @param array{execution?: string, weighting?: string, stops?: array<string, mixed>|null, sector_cap_pct?: float|null, benchmark_ticker?: string|null} $rules
     * @param array<string, array{ticker: string, cvs_swing: float, cvs_fund: float|null, price: float, sector: string|null}> $candidatesByTicker
     * @return int number of trades generated (0 = nothing to do, retried next tick)
     */
    private function doRebalance(string $code, array $rules, DateTimeImmutable $today, array $candidatesByTicker, string $reason): int
    {
        $dateStr = $today->format('Y-m-d');

        // Same-day re-run guard (see LabRepository::hasTradeToday docblock) — load-bearing
        // for open-execution (P2) trades, which sit pending without mutating positions
        // until the next day's fill, so re-deciding would otherwise queue a duplicate.
        if ($this->repo->hasTradeToday($code, $dateStr, $reason)) {
            return 0;
        }

        $candidates = array_values($candidatesByTicker);

        $targets = LabEngine::selectTargets($candidates, $rules, $this->labConfig['selection']);
        if ($targets === []) {
            return 0; // no eligible candidates today (or benchmark ticker unpriced later) — retry next tick
        }

        $positions = $this->repo->getPositions($code);
        $portfolio = $this->repo->getPortfolio($code);
        $cash      = $portfolio !== null ? (float) $portfolio['cash'] : 0.0;

        $allTickers = array_unique(array_merge(array_keys($targets), array_keys($positions)));
        $prices     = $this->resolveClosePrices($allTickers, $candidatesByTicker, $dateStr);

        $navCloses = [];
        foreach ($positions as $ticker => $pos) {
            $navCloses[$ticker] = $prices[$ticker] ?? $pos['avg_entry_price'];
        }
        $navTotal = LabEngine::computeNav($positions, $navCloses, $cash)['nav'];

        $costFrac = (float) $this->labConfig['cost_per_side_frac'];
        $trades   = LabEngine::planRebalance($positions, $targets, $prices, $cash, $navTotal, $costFrac, $reason);

        // Open-execution portfolios (P2) decide today, size against today's close as
        // an estimate, but fill tomorrow at the real open — persisted with price=NULL
        // (LabRepository::applyTrade nulls it out for 'pending') and no state mutation
        // until fillPendingTrades() resolves it the following day.
        $status = ($rules['execution'] ?? 'close') === 'open' ? 'pending' : 'filled';
        foreach ($trades as $trade) {
            $this->repo->applyTrade($code, $dateStr, $trade, $status);
        }

        if ($trades !== []) {
            $this->repo->markStarted($code, $dateStr);
        }

        return count($trades);
    }

    // ------------------------------------------------------------------
    // Pending (P2) fills
    // ------------------------------------------------------------------

    private function fillPendingTrades(string $code, DateTimeImmutable $today): int
    {
        $pending = $this->repo->findPendingTrades($code);
        if ($pending === []) {
            return 0;
        }

        $dateStr  = $today->format('Y-m-d');
        $costFrac = (float) $this->labConfig['cost_per_side_frac'];
        $filled   = 0;

        foreach ($pending as $row) {
            if ((string) $row['trade_date'] === $dateStr) {
                continue; // decided TODAY — not eligible until at least the next tick's calendar day
                          // (otherwise a same-day re-run of the tick that just queued this trade
                          // would fill it against today's own open instead of tomorrow's)
            }
            $ticker = strtoupper((string) $row['ticker']);
            $bar    = $this->ohlcBarForDate($ticker, $dateStr);
            if ($bar === null) {
                continue; // today's bar not available yet — retry next tick
            }

            $open     = $bar['open'];
            $notional = (float) $row['quantity'] * $open;
            $fee      = round($notional * $costFrac, 4);

            $this->repo->fillPendingTrade((int) $row['id'], $open, $fee);
            $filled++;
        }

        return $filled;
    }

    // ------------------------------------------------------------------
    // Stop-losses
    // ------------------------------------------------------------------

    /** @param array{stops?: array<string, mixed>|null} $rules */
    private function applyStopsForPortfolio(string $code, array $rules, DateTimeImmutable $today): int
    {
        $stopsRule = $rules['stops'] ?? null;
        if ($stopsRule === null) {
            return 0;
        }

        $positions = $this->repo->getPositions($code);
        if ($positions === []) {
            return 0;
        }

        $dateStr      = $today->format('Y-m-d');
        $stopLevels   = [];
        $ohlcByTicker = [];

        foreach ($positions as $ticker => $pos) {
            $bar = $this->ohlcBarForDate($ticker, $dateStr);
            if ($bar === null) {
                continue;
            }

            $stop = match ($stopsRule['type']) {
                'atr_swing' => $this->atrStopFor($ticker),
                'fixed_pct' => $pos['avg_entry_price'] * (1.0 - ((float) $stopsRule['pct'] / 100.0)),
                default     => null,
            };
            if ($stop === null) {
                continue;
            }

            $ohlcByTicker[$ticker] = $bar;
            $stopLevels[$ticker]   = $stop;
        }

        if ($stopLevels === []) {
            return 0;
        }

        $costFrac = (float) $this->labConfig['cost_per_side_frac'];
        $trades   = LabEngine::applyStops($positions, $ohlcByTicker, $stopLevels, $costFrac);

        foreach ($trades as $trade) {
            $this->repo->applyTrade($code, $dateStr, $trade, 'filled'); // stops always fill same-day
        }

        return count($trades);
    }

    private function atrStopFor(string $ticker): ?float
    {
        $zone = $this->priceAlertRepo->findZone($ticker);
        if ($zone === null || $zone['stop_swing'] === null) {
            return null;
        }
        return (float) $zone['stop_swing'];
    }

    // ------------------------------------------------------------------
    // NAV
    // ------------------------------------------------------------------

    /** @param array<string, array{ticker: string, cvs_swing: float, cvs_fund: float|null, price: float, sector: string|null}> $candidatesByTicker */
    private function computeAndPersistNav(string $code, DateTimeImmutable $today, array $candidatesByTicker): void
    {
        $portfolio = $this->repo->getPortfolio($code);
        if ($portfolio === null || $portfolio['started_at'] === null) {
            return; // not seeded yet — nothing to value
        }

        $dateStr   = $today->format('Y-m-d');
        $positions = $this->repo->getPositions($code);
        $cash      = (float) $portfolio['cash'];

        $closes = $this->resolveClosePrices(array_keys($positions), $candidatesByTicker, $dateStr);

        $nav = LabEngine::computeNav($positions, $closes, $cash);
        $this->repo->upsertNav($code, $dateStr, $nav['nav'], $cash, $nav['positions_value']);
    }

    // ------------------------------------------------------------------
    // Price resolution helpers
    // ------------------------------------------------------------------

    /**
     * Resolves a USD price per ticker: today's rescore snapshot first, SPY's
     * daily close for the benchmark ticker, then a light fallback fetch
     * (Critical Implementation Detail — a held ticker that fell out of the
     * watchlist still needs a valuation/exit price). Tickers with no resolvable
     * price are simply omitted — callers treat a missing entry as "skip".
     *
     * @param list<string> $tickers
     * @param array<string, array{ticker: string, cvs_swing: float, cvs_fund: float|null, price: float, sector: string|null}> $candidatesByTicker
     * @return array<string, float>
     */
    private function resolveClosePrices(array $tickers, array $candidatesByTicker, string $dateStr): array
    {
        $prices = [];
        foreach ($tickers as $ticker) {
            if (isset($candidatesByTicker[$ticker])) {
                $prices[$ticker] = $candidatesByTicker[$ticker]['price'];
                continue;
            }
            if ($ticker === 'SPY') {
                $spy = $this->spyCloseForDate($dateStr);
                if ($spy !== null) {
                    $prices[$ticker] = $spy;
                }
                continue;
            }
            $fallback = $this->fallbackPrice($ticker);
            if ($fallback !== null) {
                $prices[$ticker] = $fallback;
            }
        }
        return $prices;
    }

    private function spyCloseForDate(string $dateStr): ?float
    {
        $daily = $this->fetcher->fetchSpyDailyCloses();
        if ($daily !== null) {
            $idx = array_search($dateStr, $daily['date'], true);
            if ($idx !== false) {
                return $daily['close'][$idx];
            }
        }
        return $this->fetcher->fetchLatestPrice('SPY');
    }

    /** Light current-price read, converted to USD via the ticker's cached FX rate (ticker_zone). */
    private function fallbackPrice(string $ticker): ?float
    {
        $native = $this->fetcher->fetchLatestPrice($ticker);
        if ($native === null) {
            return null;
        }
        $zone = $this->priceAlertRepo->findZone($ticker);
        $fx   = ($zone !== null && $zone['fx_rate_to_usd'] !== null) ? (float) $zone['fx_rate_to_usd'] : 1.0;
        return $native * $fx;
    }

    /** @return array{open: float, high: float, low: float, close: float}|null */
    private function ohlcBarForDate(string $ticker, string $dateStr): ?array
    {
        $ohlc = $this->fetcher->fetchDailyOhlc($ticker, '5d');
        $idx  = array_search($dateStr, $ohlc['date'], true);
        if ($idx === false) {
            return null;
        }
        return [
            'open'  => $ohlc['open'][$idx],
            'high'  => $ohlc['high'][$idx],
            'low'   => $ohlc['low'][$idx],
            'close' => $ohlc['close'][$idx],
        ];
    }

    /** @return array<string, array{ticker: string, cvs_swing: float, cvs_fund: float|null, price: float, sector: string|null}> */
    private function candidatesByTicker(string $dateStr): array
    {
        $out = [];
        foreach ($this->repo->findCandidatesForDate($dateStr, $this->liveModelVersion) as $c) {
            $out[$c['ticker']] = $c;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Calendar
    // ------------------------------------------------------------------

    private function isFirstSessionOfMonth(DateTimeImmutable $today): bool
    {
        $frequency = $this->labConfig['rebalance']['frequency'] ?? 'monthly';
        if ($frequency !== 'monthly') {
            return false; // only 'monthly' is implemented for experiment_version '1'
        }

        $eastern = $today->setTimezone($this->marketTz);
        $cursor  = $eastern->modify('first day of this month');
        while ($cursor->format('Y-m-d') < $eastern->format('Y-m-d')) {
            if ($this->calendar->isMarketDay($cursor)) {
                return false; // an earlier day this month already traded
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $this->calendar->isMarketDay($eastern);
    }
}
