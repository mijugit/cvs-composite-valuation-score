<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Computes a genuine 200-daily-close simple moving average from a wider
 * one-off FinancialDataFetcher::fetchDailyOhlc('1y') call.
 *
 * The default fetch() only requests '3mo' of daily OHLC (~63 bars) — enough
 * for ATR/entry-zone math but not for a 200-day window. Rather than widening
 * the shared default (a cost hit on every fetch across the whole app), the
 * fundamentals-validation worker requests a wider range for this one
 * calculation only. Pure and I/O-free — the caller does the fetching.
 */
final class MovingAverageCalculator
{
    /** Below this, "200-day average" would be an average of too few days to mean anything. */
    private const MIN_CLOSES = 150;

    private const WINDOW = 200;

    /**
     * @param array{open?: float[], high?: float[], low?: float[], close?: float[], date?: string[]} $dailyOhlc
     *        Native currency, oldest-first (FinancialDataFetcher::fetchDailyOhlc()'s return shape).
     * @param float $priceFxRate the PRICE fx rate (current_price / native_price), never the
     *        financial-statement rate — see context/foundation/lessons.md, "Konwersja walut:
     *        wielkości 'na akcję' idą kursem ceny, nie kursem sprawozdań".
     */
    public static function computeMa200(array $dailyOhlc, float $priceFxRate): ?float
    {
        $closes = $dailyOhlc['close'] ?? [];
        if (count($closes) < self::MIN_CLOSES) {
            return null;
        }

        $window = array_slice($closes, -self::WINDOW);
        $sum    = 0.0;
        foreach ($window as $close) {
            $sum += ((float) $close) * $priceFxRate;
        }

        return $sum / count($window);
    }
}
