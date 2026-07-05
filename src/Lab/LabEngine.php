<?php

declare(strict_types=1);

namespace CVS\Lab;

/**
 * Pure execution-policy math for the Lab experimental portfolios (change:
 * cvs-experimental-portfolios) — no I/O, no DB, no network. Every method takes
 * already-fetched data (candidates, prices, positions) and config rules, and
 * returns a plain array (target weights or a list of trades). Mirrors the
 * pure-calculator pattern of AtrZoneCalculator / TrajectoryCalculator so the
 * whole experiment is deterministic and unit-testable offline on fixtures.
 *
 * Orchestration (which snapshot date to read, when to fetch OHLC for stops,
 * how to persist trades as filled/pending) lives in LabTickService — this
 * class never touches "today" or a data source.
 */
final class LabEngine
{
    /**
     * Rank candidates and assign target weights for one portfolio variant.
     *
     * P0 (rules.benchmark_ticker set) short-circuits to 100% of that ticker,
     * ignoring $candidates entirely — every other variant ranks candidates by
     * $selection['rank_by'] (ties broken by cvs_fund desc), applies the
     * per-sector cap when $rules['sector_cap_pct'] is set (walking the ranked
     * list and skipping a candidate once its sector already holds
     * floor(top_n × cap%) selected slots — the skipped slot is naturally
     * filled by the next-ranked candidate from another sector), then weights
     * the selected set either equally or proportionally to cvs_swing (score
     * weighting clamps negative/zero scores to 0 and falls back to equal
     * weighting if the clamped sum is ~0, so a portfolio can never divide by
     * zero or go net-short on a bad batch of scores).
     *
     * @param array<int, array{ticker: string, cvs_swing: float, cvs_fund: float|null, price: float, sector: string|null}> $candidates
     * @param array{execution?: string, weighting?: string, stops?: array<string, mixed>|null, sector_cap_pct?: float|null, benchmark_ticker?: string|null} $rules
     * @param array{top_n: int, rank_by: string} $selection
     * @return array<string, float> ticker => weight (sums to 1.0), or [] when there are no eligible candidates
     */
    public static function selectTargets(array $candidates, array $rules, array $selection): array
    {
        $benchmarkTicker = $rules['benchmark_ticker'] ?? null;
        if ($benchmarkTicker !== null && $benchmarkTicker !== '') {
            return [strtoupper($benchmarkTicker) => 1.0];
        }

        if ($candidates === []) {
            return [];
        }

        $rankBy = $selection['rank_by'];
        $topN   = max(0, $selection['top_n']);
        if ($topN === 0) {
            return [];
        }

        $ranked = $candidates;
        usort($ranked, static function (array $a, array $b) use ($rankBy): int {
            $primary = ($b[$rankBy] ?? 0.0) <=> ($a[$rankBy] ?? 0.0);
            if ($primary !== 0) {
                return $primary;
            }
            // Tie-break: higher cvs_fund wins (nulls sort last).
            return ($b['cvs_fund'] ?? -INF) <=> ($a['cvs_fund'] ?? -INF);
        });

        $sectorCapPct = $rules['sector_cap_pct'] ?? null;
        $maxPerSector = null;
        if ($sectorCapPct !== null) {
            $maxPerSector = max(1, (int) floor($topN * ((float) $sectorCapPct / 100.0) + 1e-9));
        }

        $selected      = [];
        $sectorCounts  = [];
        foreach ($ranked as $c) {
            if (count($selected) >= $topN) {
                break;
            }
            $sector = $c['sector'] ?? 'UNKNOWN';
            if ($sector === '') {
                $sector = 'UNKNOWN';
            }
            if ($maxPerSector !== null && ($sectorCounts[$sector] ?? 0) >= $maxPerSector) {
                continue; // sector already full — next-ranked candidate from another sector fills the slot
            }
            $selected[] = $c;
            $sectorCounts[$sector] = ($sectorCounts[$sector] ?? 0) + 1;
        }

        if ($selected === []) {
            return [];
        }

        $weighting = $rules['weighting'] ?? 'equal';
        if ($weighting === 'score') {
            $clamped = [];
            $sum     = 0.0;
            foreach ($selected as $c) {
                $score = max(0.0, $c['cvs_swing']);
                $clamped[strtoupper($c['ticker'])] = $score;
                $sum += $score;
            }
            if ($sum > 1e-9) {
                $weights = [];
                foreach ($clamped as $ticker => $score) {
                    $weights[$ticker] = $score / $sum;
                }
                return $weights;
            }
            // All-zero/negative scores — fall back to equal weighting rather than divide by ~0.
        }

        $equalWeight = 1.0 / count($selected);
        $weights     = [];
        foreach ($selected as $c) {
            $weights[strtoupper($c['ticker'])] = $equalWeight;
        }
        return $weights;
    }

    /**
     * Build the trade list to move current $positions toward $targets, given
     * execution prices and a cost fraction charged on both BUY and SELL notional.
     *
     * SELLs (full exit of any held ticker no longer in $targets, or partial
     * trim/top-up for tickers present in both) are planned before BUYs so the
     * freed cash is available in the same pass. A ticker missing from $prices
     * is skipped entirely (no forced trade) — its position carries over
     * untouched to the next rebalance. BUY quantities are clamped to what the
     * running cash actually affords after fees, so cash never goes negative;
     * a BUY that would round to ~0 shares is dropped rather than emitted as a
     * zero-quantity trade.
     *
     * @param array<string, array{quantity: float, avg_entry_price: float}> $positions ticker => current holding
     * @param array<string, float> $targets ticker => target weight (from selectTargets)
     * @param array<string, float> $prices  ticker => execution price (USD) for every ticker touched this rebalance
     * @param float $cash     cash available before this rebalance
     * @param float $navTotal portfolio base value (cash + positions at $prices) — targets are fractions of this
     * @param float $costFrac fee fraction charged on each trade's notional (both BUY and SELL)
     * @param string $reason  stamped on every returned trade — 'rebalance' (default) or 'seed' for a portfolio's first-ever trade
     * @return list<array{ticker: string, action: string, quantity: float, price: float, fee: float, reason: string}>
     */
    public static function planRebalance(
        array $positions,
        array $targets,
        array $prices,
        float $cash,
        float $navTotal,
        float $costFrac,
        string $reason = 'rebalance'
    ): array {
        $trades      = [];
        $runningCash = $cash;

        // --- Pass 1: SELL everything no longer targeted (full exit). ---
        foreach ($positions as $ticker => $pos) {
            if (isset($targets[$ticker])) {
                continue; // stays in the portfolio — handled in pass 2 (top-up/trim)
            }
            $price = $prices[$ticker] ?? null;
            $qty   = (float) $pos['quantity'];
            if ($price === null || $qty <= 0.0) {
                continue;
            }
            $proceeds = $qty * $price;
            $fee      = round($proceeds * $costFrac, 4);
            $trades[] = ['ticker' => $ticker, 'action' => 'SELL', 'quantity' => $qty, 'price' => $price, 'fee' => $fee, 'reason' => $reason];
            $runningCash += $proceeds - $fee;
        }

        // --- Pass 2: trim/top-up tickers staying in the portfolio. Trims (delta<0) are
        //     ordered before top-ups (delta>0) so a trim can fund a top-up within this pass. ---
        $staying = [];
        foreach ($targets as $ticker => $weight) {
            if (!isset($positions[$ticker])) {
                continue; // not currently held — brand new position, handled in pass 3
            }
            $price = $prices[$ticker] ?? null;
            if ($price === null) {
                continue; // brak ceny kandydata — skip this ticker's trade entirely this cycle
            }
            $targetUsd  = $weight * $navTotal;
            $currentQty = (float) $positions[$ticker]['quantity'];
            $currentUsd = $currentQty * $price;
            $delta      = $targetUsd - $currentUsd;
            if (abs($delta) < 0.01) {
                continue; // negligible — not worth a trade
            }
            $staying[] = ['ticker' => $ticker, 'price' => $price, 'delta' => $delta, 'currentQty' => $currentQty];
        }
        usort($staying, static fn(array $a, array $b): int => ($a['delta'] < 0 ? 0 : 1) <=> ($b['delta'] < 0 ? 0 : 1));

        foreach ($staying as $s) {
            $ticker = $s['ticker'];
            $price  = $s['price'];
            $delta  = $s['delta'];

            if ($delta < 0.0) {
                $sellUsd = min(-$delta, $s['currentQty'] * $price);
                $qty     = $sellUsd / $price;
                if ($qty <= 1e-9) {
                    continue;
                }
                $fee      = round($sellUsd * $costFrac, 4);
                $trades[] = ['ticker' => $ticker, 'action' => 'SELL', 'quantity' => $qty, 'price' => $price, 'fee' => $fee, 'reason' => $reason];
                $runningCash += $sellUsd - $fee;
                continue;
            }

            // BUY: clamp to what running cash actually affords after the fee.
            $affordableQty = $runningCash / ($price * (1.0 + $costFrac));
            $qty           = min($delta / $price, max(0.0, $affordableQty));
            if ($qty <= 1e-9) {
                continue;
            }
            $notional = $qty * $price;
            $fee      = round($notional * $costFrac, 4);
            $trades[] = ['ticker' => $ticker, 'action' => 'BUY', 'quantity' => $qty, 'price' => $price, 'fee' => $fee, 'reason' => $reason];
            $runningCash -= $notional + $fee;
        }

        // --- Pass 3: brand-new tickers (in targets, not currently held). ---
        foreach ($targets as $ticker => $weight) {
            if (isset($positions[$ticker])) {
                continue; // handled in pass 2
            }
            $price = $prices[$ticker] ?? null;
            if ($price === null) {
                continue; // brak ceny kandydata — skip
            }
            $targetUsd     = $weight * $navTotal;
            $affordableQty = $runningCash / ($price * (1.0 + $costFrac));
            $qty           = min($targetUsd / $price, max(0.0, $affordableQty));
            if ($qty <= 1e-9) {
                continue;
            }
            $notional = $qty * $price;
            $fee      = round($notional * $costFrac, 4);
            $trades[] = ['ticker' => $ticker, 'action' => 'BUY', 'quantity' => $qty, 'price' => $price, 'fee' => $fee, 'reason' => $reason];
            $runningCash -= $notional + $fee;
        }

        return $trades;
    }

    /**
     * Force-sell any held ticker whose stop level was breached by today's low.
     *
     * Execution price realism: if today's open already gapped below the stop,
     * the fill happens at the open (you cannot sell at a stop price the market
     * never traded at); otherwise the fill is the stop price itself. $costFrac
     * defaults to 0.0 for callers that don't care about fees, but every real
     * caller should pass the portfolio's actual cost_per_side_frac — stop-loss
     * exits are transactions like any other (zasady nadrzędne #5: costs modeled
     * from day one).
     *
     * @param array<string, array{quantity: float, avg_entry_price: float}> $positions
     * @param array<string, array{open: float, high: float, low: float, close: float}> $ohlcByTicker today's single OHLC row per ticker
     * @param array<string, float> $stopLevels ticker => stop price (already resolved by the caller — ATR-based for P3, avg_entry_price×(1-pct) for P4)
     * @return list<array{ticker: string, action: string, quantity: float, price: float, fee: float, reason: string}>
     */
    public static function applyStops(array $positions, array $ohlcByTicker, array $stopLevels, float $costFrac = 0.0): array
    {
        $trades = [];

        foreach ($stopLevels as $ticker => $stop) {
            $pos = $positions[$ticker] ?? null;
            $bar = $ohlcByTicker[$ticker] ?? null;
            if ($pos === null || $bar === null) {
                continue;
            }
            $qty = (float) $pos['quantity'];
            if ($qty <= 0.0) {
                continue;
            }
            $low = (float) $bar['low'];
            if ($low > $stop) {
                continue; // stop not breached today
            }
            $open      = (float) $bar['open'];
            $execPrice = $open < $stop ? $open : $stop; // gap-down realism
            $proceeds  = $qty * $execPrice;
            $fee       = round($proceeds * $costFrac, 4);

            $trades[] = [
                'ticker'   => $ticker,
                'action'   => 'SELL',
                'quantity' => $qty,
                'price'    => $execPrice,
                'fee'      => $fee,
                'reason'   => 'stop_loss',
            ];
        }

        return $trades;
    }

    /**
     * Value a portfolio's current positions at $closes and add cash.
     *
     * A ticker held but missing from $closes contributes 0 (defensive fallback
     * only — resolving a real closing price for every held ticker, including
     * one that fell out of the watchlist, is LabTickService's job via
     * fetchLatestPrice(), not this method's).
     *
     * @param array<string, array{quantity: float, avg_entry_price: float}> $positions
     * @param array<string, float> $closes ticker => valuation price (USD), already resolved by the caller
     * @param float $cash
     * @return array{nav: float, positions_value: float}
     */
    public static function computeNav(array $positions, array $closes, float $cash): array
    {
        $positionsValue = 0.0;
        foreach ($positions as $ticker => $pos) {
            $price = $closes[$ticker] ?? null;
            if ($price === null) {
                continue;
            }
            $positionsValue += (float) $pos['quantity'] * $price;
        }

        return [
            'nav'             => $cash + $positionsValue,
            'positions_value' => $positionsValue,
        ];
    }
}
