<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use CVS\Api\LatestPriceSource;

/**
 * Resolves a live USD price per held ticker for the portfolio view, with a
 * graceful fallback to the last known price (the daily CVS snapshot, already
 * USD-converted) whenever the live quote is unavailable.
 *
 * Only US-listed tickers (no exchange suffix like ".WA" / ".KS") are fetched
 * live: fetchLatestPrice returns the native currency, which is USD only for US
 * listings. Non-US holdings keep the snapshot price (already USD) to avoid a
 * currency mismatch. Caching (the "≤ every 15 min" rule) is owned by the caller.
 */
final class LivePriceProvider
{
    public function __construct(private readonly LatestPriceSource $source) {}

    /**
     * @param array<int, string>     $tickers     held tickers
     * @param array<string, float>   $fallbackUsd ticker → last known USD price (snapshot)
     * @return array<string, array{price: float, is_live: bool}>
     */
    public function fetch(array $tickers, array $fallbackUsd): array
    {
        $out = [];

        foreach ($tickers as $ticker) {
            $t        = strtoupper($ticker);
            $fallback = $fallbackUsd[$t] ?? 0.0;

            // Non-US listings report a non-USD price — keep the USD snapshot.
            if (str_contains($t, '.')) {
                $out[$t] = ['price' => $fallback, 'is_live' => false];
                continue;
            }

            $live = $this->source->fetchLatestPrice($t);

            $out[$t] = ($live !== null && $live > 0.0)
                ? ['price' => $live, 'is_live' => true]
                : ['price' => $fallback, 'is_live' => false];
        }

        return $out;
    }
}
