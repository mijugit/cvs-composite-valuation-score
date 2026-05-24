<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Yahoo Finance data fetcher — free public API (no API key required).
 *
 * Fetches raw JSON from the Yahoo Finance v10/v11 quoteSummary endpoint,
 * normalises it into the flat array structure consumed by the CVS pillars,
 * and caches the result in $_SESSION for `cache_ttl` seconds.
 *
 * Cache strategy: per-session, keyed by ticker.
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
    private const BASE_URL = 'https://query2.finance.yahoo.com/v10/finance/quoteSummary/';

    private const MODULES = [
        'financialData',
        'defaultKeyStatistics',
        'summaryDetail',
        'incomeStatementHistory',
        'balanceSheetHistory',
        'cashflowStatementHistory',
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
        $ticker = strtoupper(trim($ticker));
        $cacheKey = 'cvs_fin_' . $ticker;
        $ttl      = (int) ($this->config['cache_ttl'] ?? 3600);

        // Session-level cache check.
        if (isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts'])) {
            if (time() - $_SESSION[$cacheKey . '_ts'] < $ttl) {
                return $_SESSION[$cacheKey]; // @phpstan-ignore-line
            }
        }

        $raw = $this->callApi($ticker);

        if ($raw === null) {
            return null;
        }

        $normalised = $this->normalise($raw);

        if ($normalised === null) {
            return null;
        }

        // Store in session cache.
        $_SESSION[$cacheKey]         = $normalised;
        $_SESSION[$cacheKey . '_ts'] = time();

        return $normalised;
    }

    // ------------------------------------------------------------------
    // API call
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function callApi(string $ticker): ?array
    {
        $modules = implode(',', self::MODULES);
        $url     = self::BASE_URL . urlencode($ticker)
                 . '?modules=' . urlencode($modules)
                 . '&crumb=&lang=en-US&region=US';

        $timeout = (int) ($this->config['timeout_seconds'] ?? 25);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CVS-App/1.0)',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return null;
        }

        try {
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $result = $decoded['quoteSummary']['result'][0] ?? null;

        return is_array($result) ? $result : null;
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
     * @return array<string, mixed>|null
     */
    private function normalise(array $raw): ?array
    {
        $fin  = $raw['financialData']         ?? [];
        $ks   = $raw['defaultKeyStatistics']  ?? [];
        $sd   = $raw['summaryDetail']         ?? [];
        $is   = $raw['incomeStatementHistory']['incomeStatementHistory'] ?? [];
        $bs   = $raw['balanceSheetHistory']['balanceSheetStatements']    ?? [];
        $cf   = $raw['cashflowStatementHistory']['cashflowStatements']   ?? [];

        // Helper: extract raw value from Yahoo's {"raw": x, "fmt": "y"} objects.
        $v = static fn($obj): ?float =>
            isset($obj['raw']) ? (float) $obj['raw'] : null;

        $currentPrice = $v($fin['currentPrice'] ?? []);

        if ($currentPrice === null) {
            return null; // Cannot continue without a price.
        }

        // Revenue history — newest last.
        $revenueHistory = [];
        foreach (array_reverse($is) as $stmt) {
            $rev = $v($stmt['totalRevenue'] ?? []);
            if ($rev !== null) {
                $revenueHistory[] = $rev;
            }
        }

        // Gross margin history.
        $grossMarginHistory = [];
        foreach (array_reverse($is) as $stmt) {
            $rev   = $v($stmt['totalRevenue']  ?? []);
            $gross = $v($stmt['grossProfit']   ?? []);
            if ($rev !== null && $gross !== null && $rev > 0) {
                $grossMarginHistory[] = $gross / $rev;
            }
        }

        // Latest balance-sheet figures.
        $latestBs     = $bs[0]  ?? [];
        $latestIs     = $is[0]  ?? [];
        $latestCf     = $cf[0]  ?? [];

        return [
            // Pricing
            'current_price'         => $currentPrice,
            'fifty_two_week_low'    => $v($sd['fiftyTwoWeekLow']  ?? []),
            'fifty_two_week_high'   => $v($sd['fiftyTwoWeekHigh'] ?? []),
            'moving_average_200'    => $v($fin['twoHundredDayAverage'] ?? []),

            // Income statement
            'revenue'               => $v($latestIs['totalRevenue'] ?? []),
            'gross_profit'          => $v($latestIs['grossProfit']  ?? []),
            'ebitda'                => $v($fin['ebitda'] ?? []),
            'revenue_history'       => $revenueHistory,
            'gross_margin_history'  => $grossMarginHistory,

            // Balance sheet
            'total_debt'            => $v($fin['totalDebt']          ?? []),
            'total_equity'          => $v($latestBs['totalStockholderEquity'] ?? []),
            'cash'                  => $v($fin['totalCash']           ?? []),
            'current_assets'        => $v($latestBs['totalCurrentAssets']      ?? []),
            'current_liabilities'   => $v($latestBs['totalCurrentLiabilities'] ?? []),

            // Cash flow
            'free_cash_flow'        => $v($fin['freeCashflow'] ?? []),

            // Quality ratios
            'return_on_equity'      => $v($fin['returnOnEquity'] ?? []),

            // Valuation multiples (company)
            'pe_ratio'              => $v($sd['trailingPE']    ?? []),
            'ps_ratio'              => $v($ks['priceToSalesTrailing12Months'] ?? []),
            'ev_ebitda'             => $v($ks['enterpriseToEbitda'] ?? []),

            // Sector medians — Yahoo Finance does not provide these directly.
            // They need to be fetched separately (e.g. via sector ETF constituents)
            // or hardcoded per sector for MVP. Pillar (b) falls back to neutral 50
            // when these are null.
            'sector_pe_median'      => null,
            'sector_ps_median'      => null,
            'sector_ev_ebitda_median' => null,
        ];
    }
}
