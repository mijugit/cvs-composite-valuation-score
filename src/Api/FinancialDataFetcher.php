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

        $closes    = $this->fetchChartData($ticker, '3y');
        $spyCloses = $this->fetchSpyCloses();

        // Phase 5 (slice 2) — fetch-time reference date, determined ONCE here
        // and handed down to normalise()/EarningsCalendarParser. This is the
        // determinism seam (FR-015): "now" is an injected input, not computed
        // inside the parsing/scoring layers — keeps them pure and offline-testable.
        $referenceDate = new DateTimeImmutable();

        $normalised = $this->normalise($raw, $closes, $spyCloses, $referenceDate);

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
     * Returns null if critical fields are absent.
     *
     * @param array<string, mixed> $raw
     * @param float[]              $closes     Monthly closes for the ticker (oldest first)
     * @param float[]              $spyCloses  Monthly SPY closes (oldest first)
     * @param  DateTimeImmutable $referenceDate  Fetch-time "now", injected (Phase 5 slice 2 — determinism seam)
     * @return array<string, mixed>|null
     */
    private function normalise(array $raw, array $closes, array $spyCloses, DateTimeImmutable $referenceDate): ?array
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
            $capexRatio = ($opCf - $fcf) / $opCf;  // ≈ capex / opCf
            if ($capexRatio > 0.70) {
                $fcfEffective = $opCf * 0.50;
                $fcfAdjusted  = true;
            }
        }

        return [
            // Company metadata
            'sector'                     => is_string($ap['sector'] ?? null) ? $ap['sector'] : null,

            // Currency — needed for fair-value guard (financial_currency may differ from quote currency)
            'currency'           => is_string($sd['currency']  ?? null) ? $sd['currency']  : null,
            'financial_currency' => is_string($fin['financialCurrency'] ?? null) ? $fin['financialCurrency'] : null,

            // Company profile (assetProfile — already fetched, zero extra cost)
            'long_name'        => is_string($ap['longName']             ?? null) ? $ap['longName']             : null,
            'industry'         => is_string($ap['industry']             ?? null) ? $ap['industry']             : null,
            'country'          => is_string($ap['country']              ?? null) ? $ap['country']              : null,
            'website'          => is_string($ap['website']              ?? null) ? $ap['website']              : null,
            'employees'        => isset($ap['fullTimeEmployees']) ? (int) $ap['fullTimeEmployees'] : null,
            'long_description' => is_string($ap['longBusinessSummary']  ?? null) ? $ap['longBusinessSummary']  : null,

            // Pricing
            'current_price'              => $currentPrice,
            'fifty_two_week_low'         => $v($sd['fiftyTwoWeekLow']  ?? []),
            'fifty_two_week_high'        => $v($sd['fiftyTwoWeekHigh'] ?? []),
            'moving_average_200'         => $v($fin['twoHundredDayAverage'] ?? []),

            // Income statement
            'revenue'                    => $v($latestIs['totalRevenue'] ?? []),
            'gross_profit'               => $v($latestIs['grossProfit']  ?? []),
            'ebitda'                     => $v($fin['ebitda'] ?? []),
            'revenue_history'            => $revenueHistory,
            'gross_margin_history'       => $grossMarginHistory,

            // Balance sheet
            'total_debt'                 => $v($fin['totalDebt']          ?? []),
            'total_equity'               => $v($latestBs['totalStockholderEquity'] ?? []),
            'cash'                       => $v($fin['totalCash']           ?? []),
            'current_assets'             => $v($latestBs['totalCurrentAssets']      ?? []),
            'current_liabilities'        => $v($latestBs['totalCurrentLiabilities'] ?? []),

            // Cash flow — with capex-heavy company fallback (S-05)
            // If capex absorbs > 70 % of OpCF, use OpCF × 0.5 as a proxy FCF.
            // This prevents XOM-style companies from scoring 0 on valuation
            // when their reported FCF is artificially low due to heavy investment.
            'free_cash_flow'             => $fcfEffective,
            'free_cash_flow_raw'         => $fcf,
            'free_cash_flow_adjusted'    => $fcfAdjusted,
            'operating_cash_flow'        => $opCf,

            // FCF normalization estimate (FR-011) — computed unconditionally when inputs are
            // available; ValuationPillar decides whether to use it (bounds + feature flag).
            // Derives analyst-forward FCF: forward_eps × (fcfEffective / trailing_eps).
            // Uses $fcfEffective (capex-adjusted) for denominator parity with free_cash_flow.
            'forward_fcf_est' => (static function () use ($fcfEffective, $ks, $v): ?float {
                if ($fcfEffective === null || $fcfEffective <= 0.0) return null;
                $forwardEps  = $v($ks['forwardEps']  ?? []);
                $trailingEps = $v($ks['trailingEps'] ?? []);
                if ($forwardEps === null) return null;
                if ($trailingEps === null || $trailingEps <= 0.0) return null;
                return $forwardEps * ($fcfEffective / $trailingEps);
            })(),

            // Quality ratios
            'return_on_equity'           => $v($fin['returnOnEquity'] ?? []),

            // Valuation multiples (company-level)
            'pe_ratio'                   => $v($sd['trailingPE']    ?? []),
            'ps_ratio'                   => $v($ks['priceToSalesTrailing12Months'] ?? []),
            'ev_ebitda'                  => $v($ks['enterpriseToEbitda'] ?? []),

            // EV / Sector fields (for SectorBenchmarkPillar)
            'shares_outstanding'         => $v($ks['sharesOutstanding']         ?? []),
            'gross_margins'              => $v($fin['grossMargins']              ?? []),
            'forward_eps'                => $v($ks['forwardEps']                 ?? []),
            'trailing_eps'               => $v($ks['trailingEps']                ?? []),
            'revenue_growth'             => $v($fin['revenueGrowth']             ?? []),
            'earnings_quarterly_growth'  => $v($ks['earningsQuarterlyGrowth']   ?? []),

            // Price history (for MomentumPillar)
            'monthly_closes'             => $closes,
            'spy_closes'                 => $spyCloses,

            // Analyst forecast (S-09) — price targets + recommendation breakdown/trend.
            'forecast'                   => $forecast,

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
