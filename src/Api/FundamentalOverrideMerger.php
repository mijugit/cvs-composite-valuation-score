<?php

declare(strict_types=1);

namespace CVS\Api;

use DateTimeImmutable;

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
 *
 * It is also the one place that decides when an override has stopped being
 * needed (releasable()), so a page view, the daily rescore and the peer-median
 * crawl can never disagree about whether a field is still frozen.
 */
final class FundamentalOverrideMerger
{
    public const RELEASE_CONFIRMED = 'confirmed';
    public const RELEASE_STALE     = 'stale';

    /**
     * @param  array<string, mixed>                                                              $financials
     * @param  array<string, array{value: ?string, status: string, source: string, validated_at: string}> $overrideRows
     *         from FundamentalOverrideRepository::findByTicker()
     * @param  array<string, mixed>                                                              $policy
     *         config → fundamental_overrides; empty = never release (pre-release behavior)
     * @param  DateTimeImmutable|null                                                            $asOf
     *         the fetch's reference time; required for the after-earnings rule
     * @return array<string, mixed>
     */
    public static function merge(
        array $financials,
        array $overrideRows,
        array $policy = [],
        ?DateTimeImmutable $asOf = null
    ): array {
        // Decided on the LIVE payload, before any override lands on it.
        $released = $policy !== [] ? self::releasable($financials, $overrideRows, $policy, $asOf) : [];

        foreach ($overrideRows as $field => $row) {
            // checked_no_data rows exist for UI/history only — never merged,
            // and neither are released ones. A missing type in the registry
            // means this field was never meant to be overridable; skip rather
            // than guess a cast.
            if ($row['status'] !== 'validated' || $row['value'] === null || isset($released[$field])) {
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

    /**
     * Validated overrides that live Yahoo data has made unnecessary.
     *
     *   - confirmed: Yahoo now reports the same value within release_tolerance,
     *     so the correction has reached the source.
     *   - stale: the company has reported since the override was confirmed, so
     *     the override describes an older period than Yahoo does. Measured on
     *     calendar days (lessons: "Ile dni do X"), and only when Yahoo's own
     *     days_since_earnings is plausible — it is one of the fields Yahoo has
     *     returned wrong.
     *
     * @param  array<string, mixed>                                                              $financials LIVE, not merged
     * @param  array<string, array{value: ?string, status: string, source: string, validated_at: string}> $overrideRows
     * @param  array<string, mixed>                                                              $policy config → fundamental_overrides
     * @return array<string, string> field => RELEASE_CONFIRMED | RELEASE_STALE
     */
    public static function releasable(
        array $financials,
        array $overrideRows,
        array $policy,
        ?DateTimeImmutable $asOf = null
    ): array {
        $tolerance = (float) ($policy['release_tolerance'] ?? 0.0);
        $stale     = self::reportedSince($financials, $overrideRows, $policy, $asOf);

        $out = [];
        foreach ($overrideRows as $field => $row) {
            if ($row['status'] !== 'validated' || $row['value'] === null
                || !isset(FundamentalFieldRegistry::FIELD_TYPES[$field])) {
                continue;
            }

            if (isset($stale[$field])) {
                $out[$field] = self::RELEASE_STALE;
                continue;
            }

            $live = $financials[$field] ?? null;
            if ($tolerance > 0.0 && is_numeric($live) && is_numeric($row['value'])) {
                $l = (float) $live;
                $o = (float) $row['value'];
                $scale = max(abs($l), abs($o));
                if ($scale === 0.0 || abs($l - $o) <= $tolerance * $scale) {
                    $out[$field] = self::RELEASE_CONFIRMED;
                }
            }
        }

        return $out;
    }

    /**
     * Fields confirmed on a calendar day before the company's latest report.
     *
     * @param  array<string, mixed>                                                              $financials
     * @param  array<string, array{value: ?string, status: string, source: string, validated_at: string}> $overrideRows
     * @param  array<string, mixed>                                                              $policy
     * @return array<string, true>
     */
    private static function reportedSince(
        array $financials,
        array $overrideRows,
        array $policy,
        ?DateTimeImmutable $asOf
    ): array {
        if ($asOf === null || empty($policy['release_after_earnings'])) {
            return [];
        }

        $days    = $financials['days_since_earnings'] ?? null;
        $maxDays = (int) ($policy['max_days_since_earnings'] ?? 0);
        if (!is_int($days) || $days < 0 || ($maxDays > 0 && $days > $maxDays)) {
            return [];
        }

        $lastReport = $asOf->setTime(0, 0)->modify(sprintf('-%d days', $days))->format('Y-m-d');

        $out = [];
        foreach ($overrideRows as $field => $row) {
            $validatedDay = substr($row['validated_at'], 0, 10);
            if ($validatedDay !== '' && $validatedDay < $lastReport) {
                $out[$field] = true;
            }
        }

        return $out;
    }
}
