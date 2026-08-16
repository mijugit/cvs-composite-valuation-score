<?php

declare(strict_types=1);

namespace CVS\Api\Sec;

/**
 * Reading share counts out of an SEC XBRL `companyconcept` response.
 *
 * The regulator's own figure, filed quarterly, and the only source measured
 * here that gets multi-class companies right. Yahoo's derived alternatives do
 * not: `revenue / revenuePerShare` undercounts Estée Lauder by 32.7% and HEICO
 * by 12.1%, and `floatShares` undercounts Alphabet by 10.6%. Every one of those
 * errors runs the same way — fewer shares, smaller enterprise value, a company
 * that looks cheaper than it is.
 *
 * Pure and offline: parsing and selection only, no HTTP. SecShareCountClient
 * does the fetching.
 */
final class SecFacts
{
    /**
     * The XBRL concept to ask for. `WeightedAverageNumberOfDilutedSharesOutstanding`
     * is the count used to compute diluted EPS, so it spans every share class.
     * `dei:EntityCommonStockSharesOutstanding` — the cover-page figure — was
     * tried first and rejected: it is filed per class, which returned 0.027B for
     * HEICO against a true 0.141B, a bare 404 for Estée Lauder, and a value last
     * updated in 2015 for HEICO.
     */
    public const CONCEPT = 'WeightedAverageNumberOfDilutedSharesOutstanding';

    /** Day counts bounding what we accept as "one quarter". */
    private const QUARTER_MIN_DAYS = 60;
    private const QUARTER_MAX_DAYS = 120;

    /**
     * Most recent quarterly figure from a `units.shares` array.
     *
     * Quarterly rather than annual on purpose: the annual periods run up to
     * twelve months behind (Micron's latest annual ended 2025-08-28 while its
     * latest quarter ended 2026-05-28), and a share count that stale misses
     * exactly the buybacks that move it.
     *
     * Typed loosely on purpose: this comes straight from json_decode, so any
     * row may be malformed and every field has to be checked rather than
     * assumed.
     *
     * @param  array<int|string, mixed> $units the `units.shares` list
     * @return array{count: float, period_end: string}|null
     */
    public static function latestQuarterly(array $units): ?array
    {
        $best = null;

        foreach ($units as $row) {
            if (!is_array($row) || !isset($row['start'], $row['end'], $row['val'])) {
                continue;
            }
            if (!is_numeric($row['val']) || (float) $row['val'] <= 0.0) {
                continue;
            }

            $start = strtotime((string) $row['start']);
            $end   = strtotime((string) $row['end']);
            if ($start === false || $end === false || $end <= $start) {
                continue;
            }

            $days = ($end - $start) / 86400;
            if ($days < self::QUARTER_MIN_DAYS || $days > self::QUARTER_MAX_DAYS) {
                continue;
            }

            // Later period wins. Filings are restated and amended, so the same
            // period can appear more than once; the array order is not a
            // reliable proxy for recency.
            if ($best === null || $end > $best['end_ts']) {
                $best = [
                    'end_ts'     => $end,
                    'count'      => (float) $row['val'],
                    'period_end' => (string) $row['end'],
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return ['count' => $best['count'], 'period_end' => $best['period_end']];
    }

    /**
     * Should this ticker be looked up at the SEC at all?
     *
     * Only US domestic primary listings. The restriction is not about coverage
     * but about UNITS: for an ADR the SEC reports ORDINARY shares while we price
     * the depositary receipt, and the two differ by the conversion ratio — JD
     * files 2.978B ordinary against roughly 1.489B ADRs. Using the filed figure
     * against an ADR price would overstate enterprise value by that ratio, and
     * the ratio is not published in either API. For those, Yahoo's per-receipt
     * derivation is the one in the right unit.
     *
     * Foreign primaries (ITX.MC, SIE.DE, SPM.MI, UBI.PA) are absent from the SEC
     * altogether, so the same rule excludes them for free.
     */
    public static function isUsDomesticPrimary(
        string $ticker,
        ?string $financialCurrency,
        ?string $country
    ): bool {
        // Any exchange suffix means a non-US venue.
        if (str_contains($ticker, '.')) {
            return false;
        }
        if ($financialCurrency === null || strtoupper($financialCurrency) !== 'USD') {
            return false;
        }
        if ($country === null) {
            return false;
        }

        return strcasecmp(trim($country), 'United States') === 0;
    }

    /**
     * Zero-pad a CIK to the ten digits data.sec.gov expects in the path.
     */
    public static function padCik(int|string $cik): string
    {
        return str_pad((string) (int) $cik, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Ticker => padded CIK, from `https://www.sec.gov/files/company_tickers.json`.
     *
     * That file is a JSON object keyed by row number, each row carrying
     * `cik_str` and `ticker`.
     *
     * @param  mixed $decoded json_decode(..., true) of the file
     * @return array<string, string>
     */
    public static function parseCikMap(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['ticker'], $row['cik_str'])) {
                continue;
            }
            $ticker = strtoupper(trim((string) $row['ticker']));
            if ($ticker === '') {
                continue;
            }
            $out[$ticker] = self::padCik($row['cik_str']);
        }

        return $out;
    }
}
