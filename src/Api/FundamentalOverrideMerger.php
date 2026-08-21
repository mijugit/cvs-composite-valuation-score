<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Applies admin-confirmed fundamental-data overrides on top of a freshly
 * fetched $financials array — the one place this happens, so every caller
 * (the validation confirm endpoint today, any future caller tomorrow) shares
 * identical merge behavior (context/foundation/lessons.md, "Dwie
 * implementacje jednej reguły zawsze się rozjadą").
 *
 * Insertion point in every caller: immediately after FinancialDataFetcher::fetch()
 * returns, before PayloadCompleteness::missingEssentialFields() and before
 * CVSModel::calculate() — the same point bin/rescore.php merges
 * peer_bucket_override (bin/rescore.php:204-207).
 */
final class FundamentalOverrideMerger
{
    /**
     * @param  array<string, mixed>                                                              $financials
     * @param  array<string, array{value: ?string, status: string, source: string, validated_at: string}> $overrideRows
     *         from FundamentalOverrideRepository::findByTicker()
     * @return array<string, mixed>
     */
    public static function merge(array $financials, array $overrideRows): array
    {
        foreach ($overrideRows as $field => $row) {
            // checked_no_data rows exist for UI/history only — never merged.
            // A missing type in the registry means this field was never meant
            // to be overridable; skip rather than guess a cast.
            if ($row['status'] !== 'validated' || $row['value'] === null) {
                continue;
            }
            $type = FundamentalFieldRegistry::FIELD_TYPES[$field] ?? null;
            if ($type === null) {
                continue;
            }

            $financials[$field] = $type === 'int' ? (int) $row['value'] : (float) $row['value'];
        }

        return $financials;
    }
}
