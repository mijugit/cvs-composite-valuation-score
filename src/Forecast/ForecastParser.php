<?php

declare(strict_types=1);

namespace CVS\Forecast;

/**
 * Pure parser for Yahoo Finance analyst-forecast data (S-09).
 *
 * Turns the raw quoteSummary response into a flat, template-ready structure.
 * Intentionally free of any I/O so it can be unit-tested offline (the rest of
 * FinancialDataFetcher is excluded from the test suite by design — see CLAUDE.md).
 *
 * Two concerns live here:
 *   - parse():          extract price targets + recommendation breakdown/trend (numbers only)
 *   - consensusLabel():  map Yahoo's recommendationMean (1=Strong Buy … 5=Strong Sell)
 *                        to a Polish summary label using config thresholds (FR-010).
 */
final class ForecastParser
{
    /**
     * Extract forecast figures from the raw Yahoo response.
     *
     * The returned array is structurally stable: every sub-key is present, set to
     * null / [] when the underlying Yahoo field is absent, so callers can test each
     * block independently with !empty().
     *
     * @param array<string, mixed> $raw           Raw quoteSummary result[0]
     * @param float|null           $currentPrice  Current price, for upside computation
     * @return array{
     *     targets: array{mean: float|null, median: float|null, high: float|null, low: float|null, upside: float|null},
     *     num_analysts: int|null,
     *     recommendation_mean: float|null,
     *     recommendation_key: string|null,
     *     trend: array<int, array{period: string, strong_buy: int, buy: int, hold: int, sell: int, strong_sell: int}>,
     *     latest: array{strong_buy: int, buy: int, hold: int, sell: int, strong_sell: int}|null
     * }
     */
    public static function parse(array $raw, ?float $currentPrice): array
    {
        $fin = $raw['financialData'] ?? [];

        // Unwrap Yahoo's {"raw": x, "fmt": "y"} value objects.
        $v = static fn($obj): ?float =>
            isset($obj['raw']) && is_numeric($obj['raw']) ? (float) $obj['raw'] : null;

        $numAnalystsRaw = $v($fin['numberOfAnalystOpinions'] ?? []);
        $numAnalysts    = $numAnalystsRaw !== null ? (int) $numAnalystsRaw : null;

        // numberOfAnalystOpinions === 0 explicitly means no coverage — null the targets.
        $hasCoverage = $numAnalysts !== 0;

        $mean = $hasCoverage ? $v($fin['targetMeanPrice']   ?? []) : null;
        $med  = $hasCoverage ? $v($fin['targetMedianPrice'] ?? []) : null;
        $high = $hasCoverage ? $v($fin['targetHighPrice']   ?? []) : null;
        $low  = $hasCoverage ? $v($fin['targetLowPrice']    ?? []) : null;

        $upside = ($mean !== null && $currentPrice !== null && $currentPrice > 0)
            ? ($mean - $currentPrice) / $currentPrice
            : null;

        $recMean = $v($fin['recommendationMean'] ?? []);
        $recKey  = is_string($fin['recommendationKey'] ?? null) ? $fin['recommendationKey'] : null;

        // recommendationTrend.trend — Yahoo returns periods newest-first (0m, -1m, …).
        $trend = [];
        foreach ($raw['recommendationTrend']['trend'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $trend[] = [
                'period'      => is_string($row['period'] ?? null) ? $row['period'] : '',
                'strong_buy'  => (int) ($row['strongBuy']  ?? 0),
                'buy'         => (int) ($row['buy']        ?? 0),
                'hold'        => (int) ($row['hold']       ?? 0),
                'sell'        => (int) ($row['sell']       ?? 0),
                'strong_sell' => (int) ($row['strongSell'] ?? 0),
            ];
        }

        // Latest breakdown for the consensus block = the current period (first row).
        $latest = null;
        if ($trend !== []) {
            $first  = $trend[0];
            $latest = [
                'strong_buy'  => $first['strong_buy'],
                'buy'         => $first['buy'],
                'hold'        => $first['hold'],
                'sell'        => $first['sell'],
                'strong_sell' => $first['strong_sell'],
            ];
        }

        return [
            'targets' => [
                'mean'   => $mean,
                'median' => $med,
                'high'   => $high,
                'low'    => $low,
                'upside' => $upside,
            ],
            'num_analysts'        => $numAnalysts,
            'recommendation_mean' => $recMean,
            'recommendation_key'  => $recKey,
            'trend'               => $trend,
            'latest'              => $latest,
        ];
    }

    /**
     * Map Yahoo's recommendationMean to a Polish consensus label.
     *
     * Yahoo scale: 1 = Strong Buy … 5 = Strong Sell (lower is more bullish).
     * Thresholds are inclusive upper bounds read from config/cvs-weights.php →
     * analyst_consensus (FR-010 — never hardcode thresholds in business logic).
     *
     * @param array{strong_buy?: float, buy?: float, hold?: float, sell?: float} $thresholds
     */
    public static function consensusLabel(float $mean, array $thresholds): string
    {
        if ($mean <= ($thresholds['strong_buy'] ?? 1.5)) {
            return 'Silne Kupuj';
        }
        if ($mean <= ($thresholds['buy'] ?? 2.5)) {
            return 'Kupuj';
        }
        if ($mean <= ($thresholds['hold'] ?? 3.5)) {
            return 'Trzymaj';
        }
        if ($mean <= ($thresholds['sell'] ?? 4.5)) {
            return 'Sprzedaj';
        }
        return 'Silna Sprzedaż';
    }
}
