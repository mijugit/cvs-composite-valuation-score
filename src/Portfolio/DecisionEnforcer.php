<?php

declare(strict_types=1);

namespace CVS\Portfolio;

/**
 * Hard, deterministic enforcement of portfolio construction limits.
 *
 * The LLM is instructed to respect the per-stock and per-sector caps, but it
 * cannot reliably keep a running sum in sync with its structured `quantity`
 * fields (it narrates "reduce to 5" yet emits quantity 8). This class is the
 * server-side guard: it TRIMS each BUY down to the quantity that actually fits
 * all caps, regardless of what the model asked for. It never invents trades —
 * it only reduces or drops BUYs and passes SELL/HOLD/NO_ACTION through.
 *
 * Caps are expressed as a percentage of the portfolio base value
 * (cash + current holdings valued at execution price), read from
 * config/portfolio.php['strategy']. Stateless apart from the per-call running
 * tallies; safe to instantiate per cycle.
 */
final class DecisionEnforcer
{
    private readonly float $maxSectorPct;
    private readonly float $maxWeightPct;

    /** @param array<string, mixed> $strategy config/portfolio.php['strategy'] */
    public function __construct(array $strategy)
    {
        $this->maxSectorPct = (float) ($strategy['max_sector_pct'] ?? 40.0);
        $this->maxWeightPct = (float) ($strategy['max_weight_pct'] ?? 15.0);
    }

    /**
     * Trims BUY quantities so no single stock exceeds max_weight_pct and no
     * sector exceeds max_sector_pct of the portfolio base value. Decisions are
     * processed in order; SELLs free up cash/sector/stock budget for later BUYs.
     *
     * @param array<int, array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}> $decisions
     * @param array<int, array<string, mixed>> $holdings   current portfolio_holdings rows (ticker, quantity, avg_entry_price)
     * @param array<string, float>             $priceMap   ticker → execution price (USD)
     * @param array<string, string>            $sectorMap  ticker → sector
     * @param float                            $cash       available cash (USD)
     *
     * @return array{decisions: array<int, array{ticker: string|null, action: string, quantity: int|null, price_usd: float|null, reason: string|null}>, notes: array<int, string>}
     */
    public function apply(array $decisions, array $holdings, array $priceMap, array $sectorMap, float $cash): array
    {
        // --- Base value + seed running tallies from current holdings ---
        $stockUsd  = [];          // ticker  → USD currently held
        $sectorUsd = [];          // sector  → USD currently held
        $holdingsValue = 0.0;

        foreach ($holdings as $h) {
            $t   = strtoupper((string) ($h['ticker'] ?? ''));
            if ($t === '') {
                continue;
            }
            $qty = (int) ($h['quantity'] ?? 0);
            $px  = $priceMap[$t] ?? (float) ($h['avg_entry_price'] ?? 0);
            $val = $qty * $px;
            $sec = $sectorMap[$t] ?? 'UNKNOWN';

            $stockUsd[$t]    = ($stockUsd[$t]    ?? 0.0) + $val;
            $sectorUsd[$sec] = ($sectorUsd[$sec] ?? 0.0) + $val;
            $holdingsValue  += $val;
        }

        $base         = $cash + $holdingsValue;
        $sectorCapUsd = $base * ($this->maxSectorPct / 100);
        $stockCapUsd  = $base * ($this->maxWeightPct / 100);
        $runningCash  = $cash;

        $out   = [];
        $notes = [];

        foreach ($decisions as $decision) {
            $action = strtoupper($decision['action']);
            $ticker = strtoupper((string) ($decision['ticker'] ?? ''));

            if ($action === 'SELL') {
                // Free up budget: reduce stock/sector tallies, return proceeds to cash.
                $px  = $priceMap[$ticker] ?? null;
                $qty = (int) ($decision['quantity'] ?? 0);
                if ($px !== null && $qty > 0) {
                    $sec = $sectorMap[$ticker] ?? 'UNKNOWN';
                    $val = $qty * $px;
                    $stockUsd[$ticker] = max(0.0, ($stockUsd[$ticker] ?? 0.0) - $val);
                    $sectorUsd[$sec]   = max(0.0, ($sectorUsd[$sec] ?? 0.0) - $val);
                    $runningCash      += $val;
                }
                $out[] = $decision;
                continue;
            }

            if ($action !== 'BUY') {
                // HOLD / NO_ACTION / unknown — pass through untouched.
                $out[] = $decision;
                continue;
            }

            // --- BUY: trim to the quantity that fits stock ∩ sector ∩ cash ---
            $px = $priceMap[$ticker] ?? null;
            if ($px === null || $px <= 0) {
                $notes[] = "{$ticker}: pominięto BUY — brak ceny";
                continue;
            }

            $sec          = $sectorMap[$ticker] ?? 'UNKNOWN';
            $requestedQty = max(0, (int) ($decision['quantity'] ?? 0));

            $stockRoom  = $stockCapUsd  - ($stockUsd[$ticker] ?? 0.0);
            $sectorRoom = $sectorCapUsd - ($sectorUsd[$sec] ?? 0.0);

            $maxByStock  = (int) floor($stockRoom  / $px);
            $maxBySector = (int) floor($sectorRoom / $px);
            $maxByCash   = (int) floor($runningCash / $px);

            $allowed = min($requestedQty, $maxByStock, $maxBySector, $maxByCash);

            if ($allowed <= 0) {
                $reason = $maxBySector <= 0 ? 'limit sektorowy'
                    : ($maxByStock <= 0 ? 'limit pozycji' : 'brak gotówki');
                $notes[] = "{$ticker}: pominięto BUY — {$reason} ({$sec})";
                continue;
            }

            if ($allowed < $requestedQty) {
                $notes[] = "{$ticker}: BUY przycięty {$requestedQty}→{$allowed} szt. (limit, {$sec})";
            }

            $cost = $allowed * $px;
            $runningCash      -= $cost;
            $stockUsd[$ticker] = ($stockUsd[$ticker] ?? 0.0) + $cost;
            $sectorUsd[$sec]   = ($sectorUsd[$sec] ?? 0.0) + $cost;

            $decision['quantity'] = $allowed;
            $out[] = $decision;
        }

        return ['decisions' => $out, 'notes' => $notes];
    }
}
