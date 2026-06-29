<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Minimal seam for a single live-price read, so consumers (e.g. the portfolio
 * view) can depend on an interface rather than the full FinancialDataFetcher,
 * and tests can supply a fake without hitting the network.
 */
interface LatestPriceSource
{
    /**
     * Returns the most recent price for $ticker in its native currency,
     * or null on any failure. For US-listed tickers this is USD.
     */
    public function fetchLatestPrice(string $ticker, string $range = '1d'): ?float;
}
