<?php

declare(strict_types=1);

namespace CVS\Api;

use CVS\Api\Sec\SecFacts;
use CVS\Api\Sec\SecShareCountClient;
use CVS\CVS\Valuation\ProfitabilityMetrics;
use CVS\Forecast\EarningsCalendarParser;
use CVS\Forecast\EarningsSurpriseParser;
use CVS\Forecast\EarningsTrendParser;
use CVS\Forecast\ForecastParser;
use DateTimeImmutable;

/**
 * Yahoo Finance data fetcher — free public API (no API key required).
 *
 * Fetches raw JSON from the Yahoo Finance v10/v11 quoteSummary endpoint,
 * normalises it into the flat array structure consumed by the CVS pillars,
 * and caches the result in $_SESSION for `cache_ttl` seconds.
 *
 * Also fetches monthly price history via the chart endpoint (v8) for both
 * the target ticker and its MomentumPillar benchmark — SPY for US tickers,
 * or a market-appropriate ETF for others (see resolveBenchmarkTicker() and
 * config/cvs-weights.php → data_source.momentum_benchmark).
 *
 * fetchDailyOhlc() is public: besides feeding fetch()'s internal ATR/entry-zone
 * pipeline, LabTickService (change: cvs-experimental-portfolios) calls it
 * directly for a small set of tickers (stop-loss checks, pending open-execution
 * fills) without needing a full quoteSummary fetch.
 *
 * Yahoo Finance crumb flow (required since 2024):
 *   1. GET https://fc.yahoo.com  → set A3 cookie
 *   2. GET /v1/test/getcrumb     → obtain crumb string
 *   3. All API calls include Cookie: A3=… and &crumb=… in URL
 * The crumb + cookie are cached in $_SESSION under `cvs_yahoo_crumb` for 1 hour.
 *
 * Cache strategy: per-session, keyed by ticker. Momentum benchmark closes are
 * cached per benchmark ticker under `cvs_benchmark_closes_<ticker>` (e.g. SPY
 * for US tickers, WIG20TR for Warsaw), so a mixed-market batch doesn't evict
 * one market's cache with another's. This is intentionally simple (no Redis /
 * APCu) to stay dependency-free on shared hosting. The cache is invalidated
 * automatically when the session expires.
 *
 * Production note: Yahoo Finance does not offer an official public API.
 * These endpoints work as of the current date but can break without notice.
 * The data layer is isolated behind this class so that swapping providers
 * later requires changes only here.
 */
class FinancialDataFetcher implements LatestPriceSource
{
    private const BASE_URL    = 'https://query2.finance.yahoo.com/v10/finance/quoteSummary/';
    private const CHART_URL   = 'https://query1.finance.yahoo.com/v8/finance/chart/';
    private const CONSENT_URL = 'https://fc.yahoo.com';
    private const CRUMB_URL   = 'https://query2.finance.yahoo.com/v1/test/getcrumb';

    /**
     * Quote currencies Yahoo reports in a minor unit, mapped to their major one.
     * A price in these is one hundredth of the statement currency's unit, so it
     * must be divided by 100 before any comparison with balance-sheet figures.
     * London (GBp) is the one that bites in this universe; the other two are
     * included because they behave identically and cost nothing to cover.
     */
    private const MINOR_UNIT_CURRENCIES = [
        'GBp' => 'GBP', // pence      → pound sterling
        'ZAc' => 'ZAR', // cents      → rand
        'ILA' => 'ILS', // agorot     → shekel
    ];

    private const MODULES = [
        'assetProfile',
        'quoteType',
        'price',
        'financialData',
        'defaultKeyStatistics',
        'summaryDetail',
        'incomeStatementHistory',
        'balanceSheetHistory',
        'cashflowStatementHistory',
        'recommendationTrend',
        'earningsTrend',
        'calendarEvents',
        'earningsHistory',
    ];

    /** Built lazily from config so none of the 13 construction sites change. */
    private ?SecShareCountClient $sec = null;
    private bool $secResolved = false;

    /** @param array<string, mixed> $config  The 'data_source' section from cvs-weights.php */
    public function __construct(private readonly array $config) {}

    /**
     * SEC client, or null when disabled or unconfigured.
     *
     * Consulted only when Yahoo has no share count at all, so on a normal run
     * this makes a handful of calls, not one per ticker.
     */
    private function sec(): ?SecShareCountClient
    {
        if ($this->secResolved) {
            return $this->sec;
        }
        $this->secResolved = true;

        $cfg = is_array($this->config['sec_edgar'] ?? null) ? $this->config['sec_edgar'] : [];
        $ua  = is_string($cfg['user_agent'] ?? null) ? trim($cfg['user_agent']) : '';
        if (empty($cfg['enabled']) || $ua === '') {
            return $this->sec = null;
        }

        $cacheDir = is_string($cfg['cache_dir'] ?? null) && $cfg['cache_dir'] !== ''
            ? $cfg['cache_dir']
            : dirname(__DIR__, 2) . '/tmp';

        return $this->sec = new SecShareCountClient(
            userAgent: $ua,
            cacheDir: $cacheDir,
            timeoutSeconds: (int) ($cfg['timeout_seconds'] ?? 20),
        );
    }

    // ------------------------------------------------------------------
    // Public
    // ------------------------------------------------------------------

    public function maxTickers(): int
    {
        return (int) ($this->config['max_tickers'] ?? 10);
    }

    /**
     * Fetch and normalise financial data for a ticker.
     *
     * Returns null on network failure, HTTP error, or malformed response.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $ticker): ?array
    {
        $ticker   = strtoupper(trim($ticker));
        $cacheKey = 'cvs_fin_' . $ticker;
        $ttl      = (int) ($this->config['cache_ttl'] ?? 3600);

        // Session-level cache check.
        if (isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts'])) {
            if (time() - $_SESSION[$cacheKey . '_ts'] < $ttl) {
                return $_SESSION[$cacheKey];
            }
        }

        $raw = $this->callApi($ticker);

        if ($raw === null) {
            return null;
        }

        // Determine financial currency early so we can fetch FX rate before
        // making the more expensive chart/spy calls (skip fast if unavailable).
        $financialCurrency = is_string($raw['financialData']['financialCurrency'] ?? null)
            ? (string) $raw['financialData']['financialCurrency']
            : 'USD';

        // FR-015 determinism seam: FX rate fetched ONCE in fetch(), injected
        // into normalise() — never computed inside scoring layers.
        $fxRateToUsd = $this->fetchFxRateToUsd($financialCurrency);

        // Skip non-USD tickers when FX rate is unavailable (no Yahoo {CCY}=X data).
        if ($financialCurrency !== 'USD' && $fxRateToUsd === null) {
            return null;
        }

        $closes          = $this->fetchChartData($ticker, '3y');
        $benchmarkConfig = $this->config['momentum_benchmark'] ?? [];
        $benchmarkTicker = self::resolveBenchmarkTicker($ticker, $benchmarkConfig);
        $benchmarkLabel  = self::resolveBenchmarkLabel($benchmarkTicker, $benchmarkConfig);
        $spyCloses       = $this->fetchBenchmarkCloses($benchmarkTicker);
        // Phase 8 (slice 2) — daily OHLC for ATR / entry-zone math (AtrZoneCalculator).
        $dailyOhlc = $this->fetchDailyOhlc($ticker, '3mo');

        // Phase 5 (slice 2) — fetch-time reference date, determined ONCE here
        // and handed down to normalise()/EarningsCalendarParser. This is the
        // determinism seam (FR-015): "now" is an injected input, not computed
        // inside the parsing/scoring layers — keeps them pure and offline-testable.
        $referenceDate = new DateTimeImmutable();

        $normalised = $this->normalise($raw, $closes, $spyCloses, $referenceDate, $fxRateToUsd, $dailyOhlc, $benchmarkTicker, $benchmarkLabel);

        if ($normalised === null) {
            return null;
        }

        // Store in session cache.
        $_SESSION[$cacheKey]         = $normalised;
        $_SESSION[$cacheKey . '_ts'] = time();

        return $normalised;
    }

    // ------------------------------------------------------------------
    // Crumb / Cookie management
    // ------------------------------------------------------------------

    /**
     * Return the Yahoo Finance crumb string and A3 cookie for authenticated requests.
     *
     * Cached in $_SESSION under `cvs_yahoo_crumb` for 1 hour. On any failure
     * returns ['crumb' => '', 'cookie' => ''] so callers can still try without auth.
     *
     * @return array{crumb: string, cookie: string}
     */
    private function getCrumbAndCookie(): array
    {
        $cacheKey = 'cvs_yahoo_crumb';
        $ttl      = 3600;

        if (isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts'])) {
            if (time() - $_SESSION[$cacheKey . '_ts'] < $ttl) {
                return $_SESSION[$cacheKey];
            }
        }

        // Step 1: visit consent URL to receive the A3 cookie.
        $consentResult = $this->curlGetWithHeaders(self::CONSENT_URL, '', []);
        $cookie        = $consentResult['cookie'];

        if ($cookie === '') {
            return ['crumb' => '', 'cookie' => ''];
        }

        // Step 2: fetch crumb using the A3 cookie.
        $crumbResult = $this->curlGetWithHeaders(self::CRUMB_URL, $cookie, []);
        $crumb       = trim($crumbResult['body'] ?? '');

        // Validate: crumb should be a short alphanumeric-ish string.
        if ($crumb === '' || strlen($crumb) > 64 || str_starts_with($crumb, '{')) {
            return ['crumb' => '', 'cookie' => ''];
        }

        $data = ['crumb' => $crumb, 'cookie' => $cookie];

        $_SESSION[$cacheKey]         = $data;
        $_SESSION[$cacheKey . '_ts'] = time();

        return $data;
    }

    // ------------------------------------------------------------------
    // API calls
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function callApi(string $ticker): ?array
    {
        $auth    = $this->getCrumbAndCookie();
        $modules = implode(',', self::MODULES);
        $url     = self::BASE_URL . urlencode($ticker)
                 . '?modules=' . urlencode($modules)
                 . '&crumb=' . urlencode($auth['crumb'])
                 . '&lang=en-US&region=US';

        $result = $this->curlGetWithHeaders($url, $auth['cookie'], []);
        $body   = $result['body'] ?? null;

        if ($body === null) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $result2 = $decoded['quoteSummary']['result'][0] ?? null;

        return is_array($result2) ? $result2 : null;
    }

    /**
     * Fetch monthly closing prices for a ticker via the chart endpoint.
     *
     * Returns an array of float closes (oldest first), or an empty array
     * on any failure (MomentumPillar falls back to neutral 50 when < 7 entries).
     *
     * @return float[]
     */
    private function fetchChartData(string $ticker, string $range): array
    {
        $auth = $this->getCrumbAndCookie();
        $url  = self::CHART_URL . urlencode($ticker)
              . '?interval=1mo&range=' . urlencode($range)
              . '&crumb=' . urlencode($auth['crumb']);

        $result = $this->curlGetWithHeaders($url, $auth['cookie'], []);
        $body   = $result['body'] ?? null;

        if ($body === null) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $chartResult = $decoded['chart']['result'][0] ?? null;
        if (!is_array($chartResult)) {
            return [];
        }

        $closes = $chartResult['indicators']['quote'][0]['close'] ?? [];
        if (!is_array($closes)) {
            return [];
        }

        // Filter nulls (incomplete months), cast to float, preserve order oldest→newest.
        $filtered = [];
        foreach ($closes as $c) {
            if ($c !== null) {
                $filtered[] = (float) $c;
            }
        }

        return $filtered;
    }

    /**
     * Light current-price read (native currency) via the chart endpoint — for the
     * price-alert cron (Phase 8 slice 3). One chart call, no quoteSummary. Returns the
     * most recent non-null close, or null on any failure. Caller converts to USD using
     * the fx rate stored alongside the zone.
     */
    public function fetchLatestPrice(string $ticker, string $range = '1d'): ?float
    {
        $auth = $this->getCrumbAndCookie();
        $url  = self::CHART_URL . urlencode($ticker)
              . '?interval=1d&range=' . urlencode($range)
              . '&crumb=' . urlencode($auth['crumb']);

        $result = $this->curlGetWithHeaders($url, $auth['cookie'], []);
        $body   = $result['body'] ?? null;
        if ($body === null) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $closes = $decoded['chart']['result'][0]['indicators']['quote'][0]['close'] ?? null;
        if (!is_array($closes)) {
            return null;
        }
        foreach (array_reverse($closes) as $c) {
            if ($c !== null) {
                return (float) $c;
            }
        }
        return null;
    }

    /**
     * Fetch daily OHLC (open/high/low/close + date) via the chart endpoint for
     * ATR / entry-zone math (AtrZoneCalculator) and the Lab execution engine
     * (change: cvs-experimental-portfolios — open-price execution for P2,
     * gap-detection for stop fills).
     *
     * Returns parallel arrays (oldest first) with nulls dropped consistently across
     * all five series (a row is kept only when open, high, low, close AND the
     * timestamp are present), or an empty structure on any failure. Same v8 chart
     * endpoint as fetchChartData, daily interval. The heavy lifting (network +
     * JSON decode) stays here; the pure array-building is delegated to
     * parseOhlcChartResult() so it can be unit-tested offline on a fixture.
     *
     * @return array{open: float[], high: float[], low: float[], close: float[], date: string[]}
     */
    public function fetchDailyOhlc(string $ticker, string $range): array
    {
        $empty = ['open' => [], 'high' => [], 'low' => [], 'close' => [], 'date' => []];

        $auth = $this->getCrumbAndCookie();
        $url  = self::CHART_URL . urlencode($ticker)
              . '?interval=1d&range=' . urlencode($range)
              . '&crumb=' . urlencode($auth['crumb']);

        $result = $this->curlGetWithHeaders($url, $auth['cookie'], []);
        $body   = $result['body'] ?? null;
        if ($body === null) {
            return $empty;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $empty;
        }

        $chartResult = $decoded['chart']['result'][0] ?? null;
        if (!is_array($chartResult)) {
            return $empty;
        }

        return self::parseOhlcChartResult($chartResult);
    }

    /**
     * Pure parser: Yahoo chart `result[0]` (one ticker) → aligned OHLC+date arrays.
     *
     * Extracted from fetchDailyOhlc() so the row-alignment/null-dropping logic is
     * unit-testable offline on a fixture, without stubbing the network layer.
     * `date` is derived from the chart's per-bar `timestamp` (Unix seconds, UTC)
     * via gmdate('Y-m-d') — used only to align a bar to "today" by calendar date,
     * not for intraday timing.
     *
     * @param array<string, mixed> $chartResult decoded chart.result[0]
     * @return array{open: float[], high: float[], low: float[], close: float[], date: string[]}
     */
    private static function parseOhlcChartResult(array $chartResult): array
    {
        $empty = ['open' => [], 'high' => [], 'low' => [], 'close' => [], 'date' => []];

        $quote      = $chartResult['indicators']['quote'][0] ?? null;
        $timestamps = $chartResult['timestamp'] ?? null;
        if (!is_array($quote) || !is_array($timestamps)) {
            return $empty;
        }

        $opens  = $quote['open']  ?? [];
        $highs  = $quote['high']  ?? [];
        $lows   = $quote['low']   ?? [];
        $closes = $quote['close'] ?? [];
        if (!is_array($opens) || !is_array($highs) || !is_array($lows) || !is_array($closes)) {
            return $empty;
        }

        $out   = $empty;
        $count = min(count($opens), count($highs), count($lows), count($closes), count($timestamps));
        for ($i = 0; $i < $count; $i++) {
            $o  = $opens[$i];
            $h  = $highs[$i];
            $l  = $lows[$i];
            $c  = $closes[$i];
            $ts = $timestamps[$i];
            if ($o === null || $h === null || $l === null || $c === null || $ts === null) {
                continue; // incomplete session — drop the whole row to keep arrays aligned
            }
            $out['open'][]  = (float) $o;
            $out['high'][]  = (float) $h;
            $out['low'][]   = (float) $l;
            $out['close'][] = (float) $c;
            $out['date'][]  = gmdate('Y-m-d', (int) $ts);
        }

        return $out;
    }

    /**
     * Picks the MomentumPillar benchmark for a ticker's home market instead of
     * always comparing against the US market — e.g. WIG20TR for Warsaw-listed
     * (.WA) tickers, KOSPI 200 for Korea (.KS). Falls back to the configured
     * default (SPY) for US tickers (no suffix) and any suffix not yet mapped,
     * so covering a new market later is a one-line config addition, not a
     * code change.
     *
     * @param array{default?: string, by_suffix?: array<string, string>} $benchmarkConfig
     */
    private static function resolveBenchmarkTicker(string $ticker, array $benchmarkConfig): string
    {
        $default  = (string) ($benchmarkConfig['default'] ?? 'SPY');
        $bySuffix = $benchmarkConfig['by_suffix'] ?? [];

        $dotPos = strrpos($ticker, '.');
        if ($dotPos === false) {
            return $default;
        }

        $suffix = substr($ticker, $dotPos); // e.g. ".WA"
        return is_string($bySuffix[$suffix] ?? null) ? $bySuffix[$suffix] : $default;
    }

    /**
     * Human-readable name for a resolved benchmark ticker, for the analysis
     * page's chart legend/tooltip (e.g. "WIG20TR" instead of the raw ticker
     * "ETFBW20TR.WA"). Falls back to the ticker itself when no label is
     * configured, so a newly-added market still renders something sensible
     * before someone gets around to naming it.
     *
     * @param array{labels?: array<string, string>} $benchmarkConfig
     */
    private static function resolveBenchmarkLabel(string $benchmarkTicker, array $benchmarkConfig): string
    {
        $labels = $benchmarkConfig['labels'] ?? [];
        return is_string($labels[$benchmarkTicker] ?? null) ? $labels[$benchmarkTicker] : $benchmarkTicker;
    }

    /**
     * Fetch monthly closing prices for the resolved momentum benchmark, lazily
     * cached in session under `cvs_benchmark_closes_<ticker>`. Keyed per
     * benchmark ticker (not a single shared key) because one analysis/rescore
     * run can now mix tickers from several markets (SPY for US, WIG20TR for
     * Warsaw, KOSPI 200 for Korea, ...) that must not evict each other's cache.
     *
     * @return float[]
     */
    private function fetchBenchmarkCloses(string $benchmarkTicker): array
    {
        $cacheKey = 'cvs_benchmark_closes_' . $benchmarkTicker;
        $ttl      = (int) ($this->config['cache_ttl'] ?? 3600);

        if (isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts'])) {
            if (time() - $_SESSION[$cacheKey . '_ts'] < $ttl) {
                return $_SESSION[$cacheKey];
            }
        }

        $closes = $this->fetchChartData($benchmarkTicker, '1y');

        $_SESSION[$cacheKey]         = $closes;
        $_SESSION[$cacheKey . '_ts'] = time();

        return $closes;
    }

    /**
     * Fetch daily SPY closes for the last year (change: cvs-experimental-portfolios,
     * Phase 1) — the Lab module's benchmark (P0) and the /lab NAV chart's SPY
     * comparison series. Distinct from fetchSpyCloses() above (monthly, used by
     * MomentumPillar) — different cadence, different cache key, so neither
     * invalidates the other's TTL. Thin wrapper over fetchDailyCloses() that
     * preserves the original 'cvs_spy_daily_closes' cache key untouched.
     *
     * SPY is always USD — no FX conversion needed.
     *
     * @return array{date: string[], close: float[]}|null null on any fetch/parse failure.
     */
    public function fetchSpyDailyCloses(): ?array
    {
        return $this->fetchDailyCloses('SPY', 'cvs_spy_daily_closes');
    }

    /**
     * Fetch daily closes for the last year for any ticker (generalises
     * fetchSpyDailyCloses() above) — used by the wallet NAV comparison chart
     * (change: wallet-nav-chart) for the Nasdaq-100 (QQQ) benchmark line, and
     * reusable for any future daily-resolution benchmark need.
     *
     * @return array{date: string[], close: float[]}|null null on any fetch/parse failure.
     */
    public function fetchDailyCloses(string $ticker, ?string $cacheKey = null): ?array
    {
        $cacheKey ??= 'cvs_daily_closes_' . $ticker;
        $ttl      = (int) ($this->config['cache_ttl'] ?? 3600);

        if (isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts'])) {
            if (time() - $_SESSION[$cacheKey . '_ts'] < $ttl) {
                return $_SESSION[$cacheKey];
            }
        }

        $auth = $this->getCrumbAndCookie();
        $url  = self::CHART_URL . urlencode($ticker)
              . '?interval=1d&range=1y'
              . '&crumb=' . urlencode($auth['crumb']);

        $result = $this->curlGetWithHeaders($url, $auth['cookie'], []);
        $body   = $result['body'] ?? null;
        if ($body === null) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $chartResult = $decoded['chart']['result'][0] ?? null;
        if (!is_array($chartResult)) {
            return null;
        }

        $parsed = self::parseOhlcChartResult($chartResult);
        $out    = ['date' => $parsed['date'], 'close' => $parsed['close']];

        $_SESSION[$cacheKey]         = $out;
        $_SESSION[$cacheKey . '_ts'] = time();

        return $out;
    }

    /**
     * Fetch the multiplicative FX rate so that: usd = native × fx_rate_to_usd.
     *
     * Uses Yahoo Finance chart endpoint for {CCY}=X (e.g. KRW=X), which returns
     * how many CCY units per 1 USD.  The stored factor is the reciprocal (1 / close).
     *
     * USD → 1.0 immediately (no network call).
     * Non-USD with no Yahoo data → null (caller must skip the ticker).
     *
     * Cached per-currency in session under cvs_fx_{CCY}.
     */
    private function fetchFxRateToUsd(string $financialCurrency): ?float
    {
        if ($financialCurrency === 'USD') {
            return 1.0;
        }

        $cacheKey = 'cvs_fx_' . $financialCurrency;
        $ttl      = (int) ($this->config['cache_ttl'] ?? 3600);

        if (isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts'])) {
            if (time() - $_SESSION[$cacheKey . '_ts'] < $ttl) {
                $cached = $_SESSION[$cacheKey];
                return is_float($cached) ? $cached : null;
            }
        }

        $auth   = $this->getCrumbAndCookie();
        $fxTicker = $financialCurrency . '=X';
        $url    = self::CHART_URL . urlencode($fxTicker)
                . '?interval=1d&range=5d'
                . '&crumb=' . urlencode($auth['crumb']);

        $result = $this->curlGetWithHeaders($url, $auth['cookie'], []);
        $body   = $result['body'] ?? null;

        if ($body === null) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $chartResult = $decoded['chart']['result'][0] ?? null;
        if (!is_array($chartResult)) {
            return null;
        }

        $closes = $chartResult['indicators']['quote'][0]['close'] ?? [];
        if (!is_array($closes)) {
            return null;
        }

        // Take the last non-null close (most recent trading session).
        $lastClose = null;
        foreach (array_reverse($closes) as $c) {
            if ($c !== null) {
                $lastClose = (float) $c;
                break;
            }
        }

        if ($lastClose === null || $lastClose <= 0.0) {
            return null;
        }

        // {CCY}=X gives CCY per 1 USD, so to get USD: usd = native / close.
        // Store as multiplicative factor: usd = native * fx_rate_to_usd.
        $fxRate = 1.0 / $lastClose;

        $_SESSION[$cacheKey]         = $fxRate;
        $_SESSION[$cacheKey . '_ts'] = time();

        return $fxRate;
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * Perform a cURL GET request and return body + extracted A3 cookie.
     *
     * @param string   $url
     * @param string   $cookie  Value for Cookie header (e.g. "A3=d=AQA…"), or empty
     * @param string[] $extraHeaders
     * @return array{body: string|null, cookie: string}
     */
    private function curlGetWithHeaders(string $url, string $cookie, array $extraHeaders): array
    {
        $timeout = (int) ($this->config['timeout_seconds'] ?? 25);

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
        ];

        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        foreach ($extraHeaders as $h) {
            $headers[] = $h;
        }

        $receivedCookie = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$receivedCookie): int {
                // Extract A3 cookie from Set-Cookie headers.
                if (stripos($header, 'set-cookie:') === 0) {
                    if (preg_match('/A3=([^;]+)/i', $header, $m)) {
                        $receivedCookie = 'A3=' . $m[1];
                    }
                }
                return strlen($header);
            },
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return ['body' => null, 'cookie' => $receivedCookie];
        }

        return ['body' => (string) $body, 'cookie' => $receivedCookie];
    }

    /**
     * Picks the price rescore/screener/AnalysisController should treat as
     * "current" — prefers the extended-hours quote when Yahoo's `price`
     * module says the market is currently in that session, so a pre-market
     * gap or post-market reaction isn't hidden behind a stale regular-session
     * close on a re-score run that fires outside market hours.
     *
     * Falls back to financialData.currentPrice (the pre-existing source)
     * whenever the extended-hours field is absent — thinly-traded tickers
     * often have no pre/post print — or the market is REGULAR/CLOSED, where
     * there is no fresher extended-hours quote to offer anyway.
     *
     * Deliberately scoped to fetch()/normalise() only. Portfolio and Lab
     * price reads go through fetchLatestPrice() (chart endpoint) instead —
     * a separate method this does not touch, so trade sizing/execution keep
     * today's regular-session-price assumption unchanged.
     *
     * @param array<string, mixed> $priceModule raw['price'] ?? []
     */
    private static function resolveCurrentPrice(array $priceModule, ?float $financialCurrentPrice): ?float
    {
        $v = static fn($obj): ?float => isset($obj['raw']) ? (float) $obj['raw'] : null;

        $marketState = is_string($priceModule['marketState'] ?? null) ? $priceModule['marketState'] : null;
        $regular     = $v($priceModule['regularMarketPrice'] ?? []);

        return match ($marketState) {
            'PRE'   => $v($priceModule['preMarketPrice']  ?? []) ?? $regular ?? $financialCurrentPrice,
            'POST'  => $v($priceModule['postMarketPrice'] ?? []) ?? $regular ?? $financialCurrentPrice,
            default => $financialCurrentPrice ?? $regular,
        };
    }

    // ------------------------------------------------------------------
    // Normalisation
    // ------------------------------------------------------------------

    /**
     * Map the raw Yahoo Finance response to the flat structure expected
     * by QualityGate and the four pillars.
     *
     * Returns null if critical fields are absent or FX rate is unavailable for
     * a non-USD ticker.
     *
     * @param array<string, mixed> $raw
     * @param float[]              $closes         Monthly closes for the ticker (oldest first)
     * @param float[]              $spyCloses       Monthly SPY closes (oldest first)
     * @param DateTimeImmutable    $referenceDate   Fetch-time "now", injected (FR-015 determinism seam)
     * @param float|null           $fxRateToUsd     Multiplicative factor: usd = native × rate; null = unavailable
     * @param array{open?: float[], high: float[], low: float[], close: float[], date?: string[]} $dailyOhlc  Daily OHLC (native; FX-converted to USD inside; date passed through unconverted)
     * @return array<string, mixed>|null
     */
    private function normalise(array $raw, array $closes, array $spyCloses, DateTimeImmutable $referenceDate, ?float $fxRateToUsd = null, array $dailyOhlc = ['open' => [], 'high' => [], 'low' => [], 'close' => [], 'date' => []], string $benchmarkTicker = 'SPY', string $benchmarkLabel = 'S&P 500'): ?array
    {
        $ap   = $raw['assetProfile']            ?? [];
        $qt   = $raw['quoteType']                ?? [];
        $fin  = $raw['financialData']           ?? [];
        $ks   = $raw['defaultKeyStatistics']    ?? [];
        $sd   = $raw['summaryDetail']           ?? [];
        $is   = $raw['incomeStatementHistory']['incomeStatementHistory'] ?? [];
        $bs   = $raw['balanceSheetHistory']['balanceSheetStatements']    ?? [];

        // Helper: extract raw value from Yahoo's {"raw": x, "fmt": "y"} objects.
        $v = static fn($obj): ?float =>
            isset($obj['raw']) ? (float) $obj['raw'] : null;

        $financialCurrency = is_string($fin['financialCurrency'] ?? null) ? (string) $fin['financialCurrency'] : 'USD';

        // Safety net: skip non-USD tickers with no FX rate (primary check is in fetch()).
        if ($financialCurrency !== 'USD' && $fxRateToUsd === null) {
            return null;
        }

        $currentPrice = self::resolveCurrentPrice($raw['price'] ?? [], $v($fin['currentPrice'] ?? []));

        if ($currentPrice === null) {
            return null; // Cannot continue without a price.
        }

        // Phase 5 (slice 1) — overlay signal inputs.
        $forecast            = ForecastParser::parse($raw, $currentPrice);
        $epsRevisionPct      = EarningsTrendParser::revisionPct($raw);
        $analystTargetUpside = $forecast['targets']['upside'];

        // Phase 7 (slice 2) — predictive-signal inputs (shadow model_version 3.2).
        $epsSurprisePct    = EarningsSurpriseParser::surprisePct($raw);
        $epsBeatCount4q    = EarningsSurpriseParser::beatCount($raw);
        $epsRevisionBreadth = EarningsTrendParser::revisionBreadth($raw);

        // Phase 5 (slice 2) — earnings-timing inputs (days since/to earnings),
        // computed once against the injected fetch-time reference date.
        $earningsTiming = EarningsCalendarParser::parse($raw, $referenceDate);

        // Revenue history — newest last.
        $revenueHistory = [];
        foreach (array_reverse($is) as $stmt) {
            $rev = $v($stmt['totalRevenue'] ?? []);
            if ($rev !== null) {
                $revenueHistory[] = $rev;
            }
        }

        // Gross margin history — newest last.
        // Note: Yahoo Finance incomeStatementHistory.grossProfit occasionally
        // returns 0 as a data artefact for the most-recent fiscal year before
        // the annual report is filed. Guard with $gross > 0 to avoid corrupting
        // the margin-trend calculation in FundamentalQualityPillar.
        $grossMarginHistory = [];
        foreach (array_reverse($is) as $stmt) {
            $rev   = $v($stmt['totalRevenue']  ?? []);
            $gross = $v($stmt['grossProfit']   ?? []);
            if ($rev !== null && $gross !== null && $gross > 0 && $rev > 0) {
                $grossMarginHistory[] = $gross / $rev;
            }
        }

        // Latest balance-sheet / income-statement figures.
        $latestBs = $bs[0] ?? [];
        $latestIs = $is[0] ?? [];

        // --- OpCF fallback for capex-heavy companies (S-05, fixes XOM) ---
        // If capex absorbs more than 70 % of operating cash flow, the reported FCF
        // understates the company's underlying earning power. We use OpCF × 0.5
        // as a conservative midpoint that better reflects normalised earnings.
        $fcf  = $v($fin['freeCashflow']      ?? []);
        $opCf = $v($fin['operatingCashflow'] ?? []);

        $fcfAdjusted  = false;
        $fcfEffective = $fcf;

        if ($fcf !== null && $opCf !== null && $opCf > 0) {
            $capexRatio = ($opCf - $fcf) / $opCf;  // ≈ capex / opCf (dimensionless)
            if ($capexRatio > 0.70) {
                $fcfEffective = $opCf * 0.50;
                $fcfAdjusted  = true;
            }
        }

        // ------------------------------------------------------------------
        // FX Conversion — bring all monetary fields to USD
        // fxF: multiplier for financial statement fields (revenue, FCF, debt…)
        // fxP: multiplier for price fields; for ADRs currency='USD' so fxP=1.0
        //      (price is already USD, only financials need conversion).
        // ------------------------------------------------------------------
        $fxF = $fxRateToUsd ?? 1.0;

        // The quote currency is NOT always the financial currency, and it is not
        // always a major unit. London quotes in GBp (pence), Johannesburg in ZAc,
        // Tel Aviv in ILA — each one hundredth of the major unit — while the
        // statements stay in GBP/ZAR/ILS (or something else entirely: Unilever
        // reports in EUR but trades in GBp). The old line assumed "not USD means
        // same rate as the financials", which on ULVR.L multiplied a pence price
        // by the EUR rate: 4589 GBp (£45.89) became $5311 instead of ~$62, and an
        // enterprise value of 11.5 TRILLION followed from it.
        //
        // Resolve the price side on its own terms: strip the minor unit, then
        // convert that currency — not the reporting one — to USD.
        $quoteCurrency = is_string($sd['currency'] ?? null) ? $sd['currency'] : null;
        $minorUnit     = $quoteCurrency !== null && isset(self::MINOR_UNIT_CURRENCIES[$quoteCurrency]);
        $majorQuote    = $minorUnit ? self::MINOR_UNIT_CURRENCIES[$quoteCurrency] : $quoteCurrency;

        // Order matters. When the quote and reporting currencies are the SAME,
        // both sides must use the SAME rate: enterprise value adds price x shares
        // to debt - cash, so two independently-fetched rates would make EV — and
        // with it the supposedly dimensionless EV/FCF — depend on which currency
        // it was computed in. Only a genuine mismatch (Unilever: quoted in GBp,
        // reports in EUR) justifies resolving the price side separately.
        if ($majorQuote === null || $majorQuote === '' || $majorQuote === $financialCurrency) {
            $fxP = $fxF;
        } elseif ($majorQuote === 'USD') {
            $fxP = 1.0;
        } else {
            $fxP = $this->fetchFxRateToUsd($majorQuote) ?? $fxF;
        }

        if ($minorUnit) {
            $fxP /= 100.0; // pence → pounds, cents → rand, agorot → shekel
        }

        // ------------------------------------------------------------------
        // Share count. The ladder and the reasoning behind it live in
        // ShareCount, which is pure and unit-tested against the figures
        // measured across this universe; here we only hand it the raw fields.
        //
        // Market cap and the quote must be in the same unit: marketCap is given
        // in the MAJOR currency while a GBp price is not, which is why this sits
        // after the minor-unit resolution above rather than beside the other
        // fields.
        $sharesReported     = $v($ks['sharesOutstanding']        ?? []);
        $sharesImpliedField = $v($ks['impliedSharesOutstanding'] ?? []);
        $marketCapRaw       = $v($raw['price']['marketCap'] ?? []) ?? $v($sd['marketCap'] ?? []);

        // The regulator's figure, but only when Yahoo has nothing and only for
        // US domestic primary listings. Gated this tightly for two reasons: it
        // is a network call, and for an ADR the SEC counts ORDINARY shares while
        // we price the receipt (see SecFacts::isUsDomesticPrimary).
        $secShares = null;
        $yahooHasCount = $sharesReported !== null
            || $sharesImpliedField !== null
            || $marketCapRaw !== null;
        // The symbol Yahoo itself resolved, rather than the one asked for — if
        // the two ever disagree, the payload is the company we are describing.
        $symbol = is_string($raw['quoteType']['symbol'] ?? null)
            ? (string) $raw['quoteType']['symbol']
            : (is_string($raw['price']['symbol'] ?? null) ? (string) $raw['price']['symbol'] : '');

        if ($symbol !== ''
            && !$yahooHasCount
            && SecFacts::isUsDomesticPrimary(
                $symbol,
                $financialCurrency,
                is_string($ap['country'] ?? null) ? $ap['country'] : null
            )
        ) {
            $secShares = $this->sec()?->dilutedShares($symbol)['count'] ?? null;
        }

        $shares = ShareCount::resolve(
            reported:        $sharesReported,
            impliedField:    $sharesImpliedField,
            marketCap:       $marketCapRaw,
            priceMajor:      $minorUnit ? $currentPrice / 100.0 : $currentPrice,
            secDiluted:      $secShares,
            revenue:         $v($fin['totalRevenue']    ?? []),
            revenuePerShare: $v($fin['revenuePerShare'] ?? []),
        );
        $sharesOutstanding = $shares['count'];
        $sharesSource      = $shares['source'];

        /** Apply FX rate to a nullable float; preserves null when value is absent. */
        $fxApply = static fn (?float $val, float $rate): ?float =>
            $val !== null ? $val * $rate : null;

        $nativePrice  = $currentPrice;        // preserve before conversion for dual-display
        $currentPrice = $currentPrice * $fxP; // guaranteed non-null (checked above)

        // Convert analyst price targets and monthly closes to USD so the forecast chart
        // stays on the same scale as current_price and cvsFairPrice.
        if ($fxP !== 1.0) {
            $targets = $forecast['targets'];
            foreach (['mean', 'median', 'high', 'low'] as $k) {
                if ($targets[$k] !== null) {
                    $targets[$k] = round((float) $targets[$k] * $fxP, 2);
                }
            }
            $forecast = array_replace($forecast, ['targets' => $targets]);
            // upside is (mean/price - 1) — dimensionless, already correct.

            $closes = array_map(static fn(float $c): float => $c * $fxP, $closes);
        }

        // Phase 8 (slice 2) — daily OHLC to USD (price scale, like monthly closes) so the
        // ATR / entry-zone levels match current_price. fxP=1.0 for USD tickers and ADRs.
        // `open` (Lab, cvs-experimental-portfolios) converts the same way as high/low/close;
        // `date` is a calendar string, passed through unconverted.
        $dailyOhlcUsd = [
            'open'  => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['open']  ?? []),
            'high'  => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['high']),
            'low'   => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['low']),
            'close' => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['close']),
            'date'  => $dailyOhlc['date'] ?? [],
        ];

        // Convert FCF intermediates before deriving forward_fcf_est.
        $fcf          = $fxApply($fcf,          $fxF);
        $opCf         = $fxApply($opCf,         $fxF);
        $fcfEffective = $fxApply($fcfEffective, $fxF);

        // Convert EPS to USD so forward_fcf_est is derived from consistent USD inputs.
        $forwardEps  = $fxApply($v($ks['forwardEps']  ?? []), $fxF);
        $trailingEps = $fxApply($v($ks['trailingEps'] ?? []), $fxF);

        // forward_fcf_est: forward_eps × (fcfEffective / trailing_eps).
        // The ratio fcfEffective/trailingEps is dimensionless; multiplied by forwardEps_USD
        // yields forward_fcf_est_USD. Do NOT convert again (FR-011 double-conversion guard).
        $forwardFcfEst = (static function () use ($fcfEffective, $forwardEps, $trailingEps): ?float {
            if ($fcfEffective === null || $fcfEffective <= 0.0) return null;
            if ($forwardEps   === null)                         return null;
            if ($trailingEps  === null || $trailingEps <= 0.0) return null;
            return $forwardEps * ($fcfEffective / $trailingEps);
        })();

        // Revenue history: convert each entry to USD.
        $revenueHistory = array_map(static fn (float $r): float => $r * $fxF, $revenueHistory);
        // gross_margin_history: already dimensionless (grossProfit/revenue) — no conversion.

        // return_on_equity: fall back to a derived figure when Yahoo's own
        // returnOnEquity is absent (observed for XTB.WA and other Capital
        // Markets names). P/B and trailing P/E must exist as locals before the
        // return array below, since PHP array literals cannot reference their
        // own keys — see ProfitabilityMetrics::deriveRoe() for the formula and
        // its measured accuracy.
        $priceToBookVal = $v($ks['priceToBook'] ?? []);
        $trailingPeVal  = $v($sd['trailingPE']  ?? []);
        $roeYahoo       = $v($fin['returnOnEquity'] ?? []);
        $roeDerived     = $roeYahoo === null
            ? ProfitabilityMetrics::deriveRoe(['price_to_book' => $priceToBookVal, 'trailing_pe' => $trailingPeVal])
            : null;

        return [
            // Company metadata
            'sector'                     => is_string($ap['sector'] ?? null) ? $ap['sector'] : null,

            // Currency — quote and financial currency (may differ for ADRs).
            // native_price / native_currency / fx_rate_to_usd enable dual-display.
            'currency'           => is_string($sd['currency']  ?? null) ? $sd['currency']  : null,
            'financial_currency' => $financialCurrency,
            'native_currency'    => $financialCurrency,
            'native_price'       => $nativePrice,
            'fx_rate_to_usd'     => $fxF,

            // Company profile (assetProfile/quoteType — already fetched, zero extra cost)
            // longName lives on quoteType, not assetProfile — Yahoo's assetProfile module
            // never carries it, so this was silently null for every ticker until the
            // quoteType module was added (2026-07-02 bug fix).
            'long_name'        => is_string($qt['longName'] ?? null) ? $qt['longName']
                                 : (is_string($qt['shortName'] ?? null) ? $qt['shortName'] : null),
            'industry'         => is_string($ap['industry']             ?? null) ? $ap['industry']             : null,
            'country'          => is_string($ap['country']              ?? null) ? $ap['country']              : null,
            'website'          => is_string($ap['website']              ?? null) ? $ap['website']              : null,
            'employees'        => isset($ap['fullTimeEmployees']) ? (int) $ap['fullTimeEmployees'] : null,
            'long_description' => is_string($ap['longBusinessSummary']  ?? null) ? $ap['longBusinessSummary']  : null,

            // Pricing — all in USD (ADR: price already USD, fxP=1.0)
            'current_price'              => $currentPrice,
            'fifty_two_week_low'         => $fxApply($v($sd['fiftyTwoWeekLow']  ?? []), $fxP),
            'fifty_two_week_high'        => $fxApply($v($sd['fiftyTwoWeekHigh'] ?? []), $fxP),
            'moving_average_200'         => $fxApply($v($fin['twoHundredDayAverage'] ?? []), $fxP),

            // Income statement — all in USD
            //
            // Revenue falls back to financialData.totalRevenue (TTM) when the
            // annual statement is absent. Yahoo drops incomeStatementHistory
            // entirely for some tickers — MU, NIO and several .WA small caps
            // returned zero annual AND zero quarterly rows, deterministically,
            // on both narrow and full module requests — while still populating
            // financialData. Without the fallback those companies have no
            // revenue at all and drop out of scoring completely.
            //
            // The two figures are NOT the same measure: TTM ran +2% to +18%
            // above the last annual figure on US names (ordinary growth since
            // fiscal year end) but −64% on LPP.WA, where the periods clearly do
            // not line up. `revenue_source` records which one was used so a
            // score computed on TTM stays auditable instead of silently
            // blending two bases. Annual always wins when present, so no
            // currently-scoring ticker changes.
            'revenue'                    => $fxApply($v($latestIs['totalRevenue'] ?? []), $fxF)
                                            ?? $fxApply($v($fin['totalRevenue'] ?? []), $fxF),
            'revenue_source'             => isset($latestIs['totalRevenue']['raw']) ? 'annual'
                                            : (isset($fin['totalRevenue']['raw']) ? 'ttm' : null),
            'gross_profit'               => $fxApply($v($latestIs['grossProfit']  ?? []), $fxF),
            'ebitda'                     => $fxApply($v($fin['ebitda'] ?? []), $fxF),
            'revenue_history'            => $revenueHistory,
            'gross_margin_history'       => $grossMarginHistory, // ratios — no conversion

            // Balance sheet — all in USD
            'total_debt'                 => $fxApply($v($fin['totalDebt']          ?? []), $fxF),
            'total_equity'               => $fxApply($v($latestBs['totalStockholderEquity'] ?? []), $fxF),
            'cash'                       => $fxApply($v($fin['totalCash']           ?? []), $fxF),
            'current_assets'             => $fxApply($v($latestBs['totalCurrentAssets']      ?? []), $fxF),
            'current_liabilities'        => $fxApply($v($latestBs['totalCurrentLiabilities'] ?? []), $fxF),

            // Cash flow — with capex-heavy company fallback (S-05), all in USD.
            // If capex absorbs > 70 % of OpCF, use OpCF × 0.5 as a proxy FCF.
            'free_cash_flow'             => $fcfEffective,
            'free_cash_flow_raw'         => $fcf,
            'free_cash_flow_adjusted'    => $fcfAdjusted,
            'operating_cash_flow'        => $opCf,

            // FCF normalisation estimate (FR-011) — pre-computed from USD inputs above.
            'forward_fcf_est'            => $forwardFcfEst,

            // Quality ratios (dimensionless — no conversion)
            'return_on_equity'           => $roeYahoo ?? $roeDerived,
            // 'yahoo' | 'derived_pb_pe' | null (neither Yahoo nor P/B÷P/E available).
            // ValuationPillar's roe_divergence cross-check only runs when this is
            // 'yahoo' — a self-check against its own derivation is meaningless.
            'return_on_equity_source'    => $roeYahoo !== null ? 'yahoo' : ($roeDerived !== null ? 'derived_pb_pe' : null),

            // --- Financial-sector metrics (variant C) ---
            // Banks report no meaningful gross profit or free cash flow, so the
            // ordinary EV/FCF and EV/Sales paths measure nothing for them. These
            // are the fields the sector is actually priced on. Ratios and
            // per-share book value need no FX conversion — the ratio is
            // currency-neutral, and book value is per share in the same currency
            // as the quote.
            'price_to_book'              => $priceToBookVal,
            // Per SHARE, so it converts with the PRICE rate ($fxP), not the
            // statement rate — it is compared against current_price, which is
            // itself converted that way. Getting this wrong would compare a
            // PLN book value to a USD price on every Warsaw-listed bank.
            'book_value_per_share'       => $fxApply($v($ks['bookValue'] ?? []), $fxP),
            'return_on_assets'           => $v($fin['returnOnAssets']     ?? []),
            'trailing_pe'                => $trailingPeVal,
            'dividend_yield'             => $v($sd['dividendYield']       ?? []),
            'payout_ratio'               => $v($sd['payoutRatio']         ?? []),
            'operating_margin'           => $v($fin['operatingMargins']  ?? []),
            'profit_margin'              => $v($fin['profitMargins']      ?? []),

            // Valuation multiples (dimensionless — no conversion)
            'pe_ratio'                   => $trailingPeVal,
            'forward_pe'                 => $v($ks['forwardPE']     ?? []),
            'ps_ratio'                   => $v($ks['priceToSalesTrailing12Months'] ?? []),
            'ev_ebitda'                  => $v($ks['enterpriseToEbitda'] ?? []),
            'peg_ratio'                  => $v($ks['pegRatio']      ?? []),

            // Market structure (dimensionless / shares — no conversion)
            'beta'                       => $v($ks['beta']                       ?? []),
            'short_pct_float'            => $v($ks['shortPercentOfFloat']        ?? []),
            'short_ratio'                => $v($ks['shortRatio']                 ?? []),
            'float_shares'               => $v($ks['floatShares']               ?? []),
            'institutional_ownership'    => $v($ks['heldPercentInstitutions']   ?? []),

            // EV / Sector fields
            'shares_outstanding'         => $sharesOutstanding,
            'shares_source'              => $sharesSource,
            'gross_margins'              => $v($fin['grossMargins']              ?? []), // ratio
            'forward_eps'                => $forwardEps,    // USD
            'trailing_eps'               => $trailingEps,   // USD
            'revenue_growth'             => $v($fin['revenueGrowth']             ?? []), // ratio
            'earnings_quarterly_growth'  => $v($ks['earningsQuarterlyGrowth']   ?? []), // ratio

            // Price history (for MomentumPillar — base-100 index, dimensionless)
            'monthly_closes'             => $closes,
            'spy_closes'                 => $spyCloses,
            'benchmark_ticker'           => $benchmarkTicker,
            'benchmark_label'            => $benchmarkLabel,

            // Phase 8 (slice 2) — daily OHLC in USD for ATR / entry-zone math (AtrZoneCalculator).
            'daily_ohlc'                 => $dailyOhlcUsd,

            // Analyst forecast (S-09) — price targets + recommendation breakdown/trend.
            'forecast'                   => $forecast,

            // Recommendation momentum: change in (strongBuy+buy) count vs prior month.
            // Positive = more analysts turned bullish this month; negative = turned bearish.
            // Derived from recommendationTrend[0m] vs [-1m] — zero extra API calls.
            'recommendation_change'      => (static function () use ($forecast): ?int {
                $cur  = null;
                $prev = null;
                foreach ($forecast['trend'] as $row) {
                    if ($row['period'] === '0m')  { $cur  = $row; }
                    if ($row['period'] === '-1m') { $prev = $row; }
                }
                if ($cur === null || $prev === null) { return null; }
                return ($cur['strong_buy'] + $cur['buy']) - ($prev['strong_buy'] + $prev['buy']);
            })(),

            // Phase 5 (slice 1) — overlay signal inputs (shadow model_version 3.1).
            // eps_revision_pct:      +1q EPS estimate revision, fraction (e.g. -0.13 = -13%); null = no coverage/data.
            // analyst_target_upside: (mean target - price) / price, fraction; null = no analyst coverage.
            'eps_revision_pct'           => $epsRevisionPct,
            'analyst_target_upside'      => $analystTargetUpside,

            // Phase 5 (slice 2) — earnings-timing inputs (shadow + badge, FR-006/007).
            // days_since_earnings: whole days since defaultKeyStatistics.mostRecentQuarter; null = no data.
            // days_to_earnings:    whole days to calendarEvents.earnings.earningsDate (first/earliest if a
            //                      range); MAY BE NEGATIVE (calendar date passed but mostRecentQuarter
            //                      hasn't caught up yet — a deliberate 'in_transit' signal); null = no data.
            // Both computed once at fetch-time against an injected reference date — deterministic inputs,
            // never recomputed inside scoring (FR-015).
            'days_since_earnings'        => $earningsTiming['days_since_earnings'],
            'days_to_earnings'           => $earningsTiming['days_to_earnings'],

            // Phase 7 (slice 2) — predictive-signal inputs (shadow model_version 3.2, FR-004/006).
            // eps_surprise_pct:     surprisePercent of the most recently reported quarter, fraction
            //                       (e.g. 0.05 = +5% beat); null = no coverage/data.
            // eps_beat_count_4q:    number of quarters with surprisePercent > 0 among the last
            //                       (up to) 4 reported quarters; null = no coverage (distinct from 0).
            // eps_revision_breadth: (up30d - down30d) / (up30d + down30d) for +1q EPS estimates,
            //                       fraction in [-1, 1]; null = no coverage/data/zero opinions.
            'eps_surprise_pct'           => $epsSurprisePct,
            'eps_beat_count_4q'          => $epsBeatCount4q,
            'eps_revision_breadth'       => $epsRevisionBreadth,
        ];
    }
}
