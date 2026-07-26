<?php

declare(strict_types=1);

namespace CVS\Screener;

/**
 * Pure ticker-suffix -> market-label math, no I/O (mirrors the LabEngine /
 * TrajectoryCalculator "pure calculator" pattern). Used by the screener's
 * market filter (ScreenerRepository::getDistinctMarkets/getFiltered) and by
 * the admin ticker-add confirmation (TickersController) — same suffix
 * convention already established for the per-market momentum benchmark in
 * FinancialDataFetcher::resolveBenchmarkTicker(), but keyed directly by
 * suffix -> display name rather than suffix -> benchmark ETF ticker (picking
 * a benchmark ETF is a research decision; naming a market is not).
 */
final class MarketResolver
{
    /**
     * Extracts the market suffix (e.g. ".WA") from a ticker, or null for a
     * plain US ticker (no suffix).
     */
    public static function suffixForTicker(string $ticker): ?string
    {
        $dotPos = strrpos($ticker, '.');
        return $dotPos === false ? null : strtoupper(substr($ticker, $dotPos));
    }

    /**
     * Human-readable market name for a suffix ($suffix === null means US).
     * Falls back to the raw suffix itself when not yet in config — a
     * brand-new market still renders something sensible the moment a ticker
     * from it is added, before anyone gets around to naming it in config.
     *
     * @param array{default_label?: string, labels?: array<string, string>} $marketsConfig config/cvs-weights.php -> markets
     */
    public static function labelForSuffix(?string $suffix, array $marketsConfig): string
    {
        if ($suffix === null) {
            return (string) ($marketsConfig['default_label'] ?? 'USA');
        }
        $labels = $marketsConfig['labels'] ?? [];
        return is_string($labels[$suffix] ?? null) ? $labels[$suffix] : $suffix;
    }

    /** @param array{default_label?: string, labels?: array<string, string>} $marketsConfig */
    public static function labelForTicker(string $ticker, array $marketsConfig): string
    {
        return self::labelForSuffix(self::suffixForTicker($ticker), $marketsConfig);
    }
}
