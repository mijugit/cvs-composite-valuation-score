<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

/**
 * Pure evaluation logic — no DB access.
 *
 * Determines whether a CVS recommendation was correct based on price direction
 * after N days.  "Hit" = price moved in the direction implied by the reco.
 *
 * Recommendation mapping:
 *   SILNE KUPUJ / KUPUJ / AKUMULUJ → bullish → price up = hit
 *   REDUKUJ / UNIKAJ / SILNA SPRZEDAŻ → bearish → price down = hit
 *   NEUTRALNIE → no directional call → neutral (not counted)
 *
 * This is intentionally simple: direction only, no minimum threshold.
 */
class TrackRecordCalculator
{
    private const BULLISH = ['SILNE KUPUJ', 'KUPUJ', 'AKUMULUJ'];
    private const BEARISH  = ['REDUKUJ', 'UNIKAJ', 'SILNA SPRZEDAŻ'];

    // ------------------------------------------------------------------
    // Per-row
    // ------------------------------------------------------------------

    /**
     * Evaluate a single recommendation against price change.
     *
     * @param  string $recoSwing      Recommendation label (reco_swing from snapshot)
     * @param  float  $priceChangePct Percentage change: (now - then) / then * 100
     * @return bool|null              true=hit, false=miss, null=neutral/no-direction
     */
    public static function isHit(string $recoSwing, float $priceChangePct): ?bool
    {
        $reco = strtoupper(trim($recoSwing));

        if ($reco === '') {
            return null;
        }

        // Match if the stored reco contains a known label keyword.
        foreach (self::BULLISH as $label) {
            if (str_contains($reco, strtoupper($label))) {
                return $priceChangePct > 0;
            }
        }

        foreach (self::BEARISH as $label) {
            if (str_contains($reco, strtoupper($label))) {
                return $priceChangePct < 0;
            }
        }

        return null; // NEUTRALNIE or unknown
    }

    /**
     * Determine display result for a single evaluation row.
     *
     * @param array<string, mixed> $row Must contain reco_swing, price_change_pct
     * @return 'hit'|'miss'|'neutral'
     */
    public static function getResult(array $row): string
    {
        $reco      = (string) ($row['reco_swing']       ?? '');
        $changePct = isset($row['price_change_pct']) ? (float) $row['price_change_pct'] : null;

        if ($changePct === null) {
            return 'neutral';
        }

        $hit = self::isHit($reco, $changePct);
        if ($hit === null) return 'neutral';
        return $hit ? 'hit' : 'miss';
    }

    // ------------------------------------------------------------------
    // Batch enrichment
    // ------------------------------------------------------------------

    /**
     * Add 'result' key ('hit'|'miss'|'neutral') to each evaluation row.
     *
     * @param array<int, array<string, mixed>> $evaluations
     * @return array<int, array<string, mixed>>
     */
    public static function enrichWithResult(array $evaluations): array
    {
        return array_map(static function (array $row): array {
            $row['result'] = self::getResult($row);
            return $row;
        }, $evaluations);
    }

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------

    /**
     * Aggregate statistics across enriched evaluation rows.
     *
     * @param array<int, array<string, mixed>> $enriched Must have 'result' key
     * @return array{total: int, hits: int, misses: int, neutral: int,
     *               pending: int, hit_rate_pct: float|null, avg_change_pct: float|null}
     */
    public static function summarise(array $enriched): array
    {
        $hits    = 0;
        $misses  = 0;
        $neutral = 0;
        $changes = [];

        foreach ($enriched as $row) {
            $result = $row['result'] ?? 'neutral';
            if ($result === 'hit')     { $hits++;   }
            elseif ($result === 'miss') { $misses++; }
            else                        { $neutral++; }

            if (isset($row['price_change_pct'])) {
                $changes[] = (float) $row['price_change_pct'];
            }
        }

        $total      = $hits + $misses + $neutral;
        $evaluated  = $hits + $misses; // neutral excluded from hit_rate
        $hitRate    = $evaluated > 0 ? round($hits / $evaluated * 100, 1) : null;
        $avgChange  = count($changes) > 0 ? round(array_sum($changes) / count($changes), 2) : null;

        return [
            'total'         => $total,
            'hits'          => $hits,
            'misses'        => $misses,
            'neutral'       => $neutral,
            'pending'       => 0, // pending rows are not passed here (they come from getAllForTicker)
            'hit_rate_pct'  => $hitRate,
            'avg_change_pct'=> $avgChange,
        ];
    }

    // ------------------------------------------------------------------
    // Per-ticker trend (track-record accordion)
    // ------------------------------------------------------------------

    /**
     * Change in hit-rate between a "recent" and an "older" band, for one ticker.
     *
     * $currentRows (horizon N) is a superset of $olderRows (horizon 2N) because
     * getEvaluations()'s cutoff is a lower bound, not a window (old.score_date is
     * always "at least N days old", unbounded further back — see
     * TrackRecordRepository::getEvaluations()). Subtracting $olderRows out of
     * $currentRows by score_date isolates exactly the rows that only just matured
     * into the N-day horizon: aged [N, 2N) days. Comparing that band's hit-rate
     * against $olderRows' own hit-rate (the established, ≥2N-day track record)
     * answers "are freshly-matured calls for this ticker doing better or worse
     * than its longer history" — without any new SQL (both inputs come from the
     * same getEvaluations() call, just at horizon N and horizon 2N).
     *
     * @param array<int, array<string, mixed>> $currentRows enriched rows at horizon N, one ticker
     * @param array<int, array<string, mixed>> $olderRows   enriched rows at horizon 2N, same ticker
     * @return float|null percentage-point delta (recent - older); null when either
     *                     band has no evaluated (hit/miss) pairs to compare
     */
    public static function deltaHitRatePct(array $currentRows, array $olderRows): ?float
    {
        $olderDates = array_column($olderRows, 'score_date');
        $recentBand = array_values(array_filter(
            $currentRows,
            static fn(array $row): bool => !in_array($row['score_date'] ?? null, $olderDates, true)
        ));

        $recentRate = self::summarise($recentBand)['hit_rate_pct'];
        $olderRate  = self::summarise($olderRows)['hit_rate_pct'];

        if ($recentRate === null || $olderRate === null) {
            return null;
        }

        return round($recentRate - $olderRate, 1);
    }
}
