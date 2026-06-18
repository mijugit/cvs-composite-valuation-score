<?php

declare(strict_types=1);

namespace CVS\Execution;

/**
 * Pure ATR-based execution-zone logic (Phase 8, slice 2) — no I/O, no DB.
 *
 * From a daily OHLC series + current price + config knobs, derives:
 *   - ATR (Wilder, `atr_period`),
 *   - a support-anchored accumulation zone [support, support + zone_atr_mult×ATR],
 *     with a volatility fallback [price − fallback_k×ATR, price] when there is not
 *     enough history for the support window,
 *   - per-mode stops (zone_low − stop_mult[mode]×ATR), and
 *   - the price's state relative to the zone (in_zone / above / below).
 *
 * Deterministic and offline-testable; mirrors the pure-calculator pattern of
 * TrajectoryCalculator / TrackRecordCalculator. Never touches CVS scoring.
 */
class AtrZoneCalculator
{
    /**
     * @param array{high?: float[], low?: float[], close?: float[]} $ohlc Parallel arrays, oldest first.
     * @param array<string, mixed> $cfg `atr_zones` config section.
     * @return array{
     *   has_zone: bool, atr: float|null, support: float|null,
     *   zone_low: float|null, zone_high: float|null,
     *   stop_swing: float|null, stop_fund: float|null,
     *   state: string|null, source: string|null
     * }
     */
    public static function compute(array $ohlc, float $currentPrice, array $cfg): array
    {
        $none = [
            'has_zone' => false, 'atr' => null, 'support' => null,
            'zone_low' => null, 'zone_high' => null,
            'stop_swing' => null, 'stop_fund' => null,
            'state' => null, 'source' => null,
        ];

        $high  = array_values($ohlc['high']  ?? []);
        $low   = array_values($ohlc['low']   ?? []);
        $close = array_values($ohlc['close'] ?? []);

        $n = min(count($high), count($low), count($close));
        $atrPeriod     = max(1, (int) ($cfg['atr_period']     ?? 14));
        $supportWindow = max(1, (int) ($cfg['support_window'] ?? 20));
        $zoneMult      = (float) ($cfg['zone_atr_mult'] ?? 1.0);
        $fallbackK     = (float) ($cfg['fallback_k']    ?? 1.0);
        $stopSwingMult = (float) (($cfg['stop_mult']['swing'] ?? 1.5));
        $stopFundMult  = (float) (($cfg['stop_mult']['fund']  ?? 3.0));

        // Need at least atr_period+1 points (true range references the prior close).
        if ($n < $atrPeriod + 1 || $currentPrice <= 0.0) {
            return $none;
        }

        $atr = self::wilderAtr($high, $low, $close, $n, $atrPeriod);
        if ($atr === null || $atr <= 0.0) {
            return $none;
        }

        // Support = lowest low over the support window, when we have enough history;
        // otherwise fall back to a purely volatility-based zone around the price.
        if ($n >= $supportWindow) {
            $support  = min(array_slice($low, $n - $supportWindow, $supportWindow));
            $zoneLow  = $support;
            $zoneHigh = $support + $zoneMult * $atr;
            $source   = 'support';
        } else {
            $support  = null;
            $zoneLow  = $currentPrice - $fallbackK * $atr;
            $zoneHigh = $currentPrice;
            $source   = 'fallback';
        }

        $state = $currentPrice >= $zoneLow && $currentPrice <= $zoneHigh
            ? 'in_zone'
            : ($currentPrice > $zoneHigh ? 'above' : 'below');

        return [
            'has_zone'   => true,
            'atr'        => round($atr, 2),
            'support'    => $support !== null ? round($support, 2) : null,
            'zone_low'   => round($zoneLow, 2),
            'zone_high'  => round($zoneHigh, 2),
            'stop_swing' => round($zoneLow - $stopSwingMult * $atr, 2),
            'stop_fund'  => round($zoneLow - $stopFundMult * $atr, 2),
            'state'      => $state,
            'source'     => $source,
        ];
    }

    /**
     * Wilder's ATR over `period`, using all available true ranges.
     *
     * TR[i] = max(high-low, |high-prevClose|, |low-prevClose|) for i≥1; TR[0]=high-low.
     * Seed = SMA of the first `period` TRs, then smoothed: ATR = (ATR×(p−1) + TR)/p.
     *
     * @param float[] $high
     * @param float[] $low
     * @param float[] $close
     */
    private static function wilderAtr(array $high, array $low, array $close, int $n, int $period): ?float
    {
        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $tr[] = $high[$i] - $low[$i];
                continue;
            }
            $prevClose = $close[$i - 1];
            $tr[] = max(
                $high[$i] - $low[$i],
                abs($high[$i] - $prevClose),
                abs($low[$i] - $prevClose),
            );
        }

        if (count($tr) < $period) {
            return null;
        }

        // Seed: SMA of first `period` true ranges.
        $atr = array_sum(array_slice($tr, 0, $period)) / $period;
        // Wilder smoothing across the remaining TRs.
        for ($i = $period; $i < count($tr); $i++) {
            $atr = ($atr * ($period - 1) + $tr[$i]) / $period;
        }

        return $atr;
    }
}
