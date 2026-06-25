<?php

declare(strict_types=1);

namespace CVS\Api;

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
 * the target ticker and SPY (used by MomentumPillar).
 *
 * Yahoo Finance crumb flow (required since 2024):
 *   1. GET https://fc.yahoo.com  → set A3 cookie
 *   2. GET /v1/test/getcrumb     → obtain crumb string
 *   3. All API calls include Cookie: A3=… and &crumb=… in URL
 * The crumb + cookie are cached in $_SESSION under `cvs_yahoo_crumb` for 1 hour.
 *
 * Cache strategy: per-session, keyed by ticker. SPY closes are shared across
 * all tickers under the key `cvs_spy_closes` (fetched once per session).
 * This is intentionally simple (no Redis / APCu) to stay dependency-free
 * on shared hosting.  The cache is invalidated automatically when the
 * session expires.
 *
 * Production note: Yahoo Finance does not offer an official public API.
 * These endpoints work as of the current date but can break without notice.
 * The data layer is isolated behind this class so that swapping providers
 * later requires changes only here.
 */
class FinancialDataFetcher
{
    private const BASE_URL    = 'https://query2.finance.yahoo.com/v10/finance/quoteSummary/';
    private const CHART_URL   = 'https://query1.finance.yahoo.com/v8/finance/chart/';
    private const CONSENT_URL = 'https://fc.yahoo.com';
    private const CRUMB_URL   = 'https://query2.finance.yahoo.com/v1/test/getcrumb';

    private const MODULES = [
        'assetProfile',
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

    /** @param array<string, mixed> $config  The 'data_source' section from cvs-weights.php */
    public function __construct(private readonly array $config) {}

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

        $closes    = $this->fetchChartData($ticker, '3y');
        $spyCloses = $this->fetchSpyCloses();
        // Phase 8 (slice 2) — daily OHLC for ATR / entry-zone math (AtrZoneCalculator).
        $dailyOhlc = $this->fetchDailyOhlc($ticker, '3mo');

        // Phase 5 (slice 2) — fetch-time reference date, determined ONCE here
        // and handed down to normalise()/EarningsCalendarParser. This is the
        // determinism seam (FR-015): "now" is an injected input, not computed
        // inside the parsing/scoring layers — keeps them pure and offline-testable.
        $referenceDate = new DateTimeImmutable();

        $normalised = $this->normalise($raw, $closes, $spyCloses, $referenceDate, $fxRateToUsd, $dailyOhlc);

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
     * Fetch daily OHLC (high/low/close) via the chart endpoint for ATR / entry-zone math.
     *
     * Returns parallel float arrays (oldest first) with nulls dropped consistently across
     * all three series (a row is kept only when high, low AND close are present), or an
     * empty structure on any failure. Same v8 chart endpoint as fetchChartData, daily interval.
     *
     * @return array{high: float[], low: float[], close: float[]}
     */
    private function fetchDailyOhlc(string $ticker, string $range): array
    {
        $empty = ['high' => [], 'low' => [], 'close' => []];

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

        $quote = $decoded['chart']['result'][0]['indicators']['quote'][0] ?? null;
        if (!is_array($quote)) {
            return $empty;
        }

        $highs  = $quote['high']  ?? [];
        $lows   = $quote['low']   ?? [];
        $closes = $quote['close'] ?? [];
        if (!is_array($highs) || !is_array($lows) || !is_array($closes)) {
            return $empty;
        }

        $out = ['high' => [], 'low' => [], 'close' => []];
        $count = min(count($highs), count($lows), count($closes));
        for ($i = 0; $i < $count; $i++) {
            $h = $highs[$i];
            $l = $lows[$i];
            $c = $closes[$i];
            if ($h === null || $l === null || $c === null) {
                continue; // incomplete session — drop the whole row to keep arrays aligned
            }
            $out['high'][]  = (float) $h;
            $out['low'][]   = (float) $l;
            $out['close'][] = (float) $c;
        }

        return $out;
    }

    /**
     * Fetch monthly SPY closing prices, lazily cached in session under `cvs_spy_closes`.
     *
     * SPY data is shared across all tickers in a single analysis run,
     * so we cache it separately from individual ticker data.
     *
     * @return float[]
     */
    private function fetchSpyCloses(): array
    {
        $spyCacheKey = 'cvs_spy_closes';
        $ttl         = (int) ($this->config['cache_ttl'] ?? 3600);

        if (isset($_SESSION[$spyCacheKey], $_SESSION[$spyCacheKey . '_ts'])) {
            if (time() - $_SESSION[$spyCacheKey . '_ts'] < $ttl) {
                return $_SESSION[$spyCacheKey];
            }
        }

        $closes = $this->fetchChartData('SPY', '1y');

        $_SESSION[$spyCacheKey]         = $closes;
        $_SESSION[$spyCacheKey . '_ts'] = time();

        return $closes;
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
     * @param array{high: float[], low: float[], close: float[]} $dailyOhlc  Daily OHLC (native; FX-converted to USD inside)
     * @return array<string, mixed>|null
     */
    private function normalise(array $raw, array $closes, array $spyCloses, DateTimeImmutable $referenceDate, ?float $fxRateToUsd = null, array $dailyOhlc = ['high' => [], 'low' => [], 'close' => []]): ?array
    {
        $ap   = $raw['assetProfile']            ?? [];
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

        $currentPrice = $v($fin['currentPrice'] ?? []);

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
        $fxP = (is_string($sd['currency'] ?? null) && ($sd['currency'] ?? '') === 'USD') ? 1.0 : $fxF;

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
        $dailyOhlcUsd = [
            'high'  => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['high']),
            'low'   => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['low']),
            'close' => array_map(static fn(float $x): float => $x * $fxP, $dailyOhlc['close']),
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

            // Company profile (assetProfile — already fetched, zero extra cost)
            'long_name'        => is_string($ap['longName']             ?? null) ? $ap['longName']             : null,
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
            'revenue'                    => $fxApply($v($latestIs['totalRevenue'] ?? []), $fxF),
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
            'return_on_equity'           => $v($fin['returnOnEquity']    ?? []),
            'operating_margin'           => $v($fin['operatingMargins']  ?? []),
            'profit_margin'              => $v($fin['profitMargins']      ?? []),

            // Valuation multiples (dimensionless — no conversion)
            'pe_ratio'                   => $v($sd['trailingPE']    ?? []),
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
            'shares_outstanding'         => $v($ks['sharesOutstanding']         ?? []),
            'gross_margins'              => $v($fin['grossMargins']              ?? []), // ratio
            'forward_eps'                => $forwardEps,    // USD
            'trailing_eps'               => $trailingEps,   // USD
            'revenue_growth'             => $v($fin['revenueGrowth']             ?? []), // ratio
            'earnings_quarterly_growth'  => $v($ks['earningsQuarterlyGrowth']   ?? []), // ratio

            // Price history (for MomentumPillar — base-100 index, dimensionless)
            'monthly_closes'             => $closes,
            'spy_closes'                 => $spyCloses,

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
