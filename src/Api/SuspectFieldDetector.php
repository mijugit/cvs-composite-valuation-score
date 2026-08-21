<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Flags fundamental-data fields that are present but implausible, or absent
 * where a large, well-covered company is expected to have a value.
 *
 * Pure and I/O-free — same contract as PayloadCompleteness — so it is
 * unit-testable offline against a synthetic $financials array. Answers a
 * different question than PayloadCompleteness: that class asks "is this
 * payload scoreable at all" (one field, revenue); this one asks "which
 * present-or-absent fields should an admin double-check before trusting the
 * score" (context/foundation/lessons.md, "Brak danych to nie zero" — a NULL
 * check is not the same question as a plausibility check).
 */
final class SuspectFieldDetector
{
    /**
     * Companies tracked here report quarterly; a gap beyond one quarter plus
     * a generous buffer is implausible rather than merely "a bit late".
     * Calibrated against the confirmed real-world bug values (1900+ days) so
     * it never false-positives on an ordinarily late reporter.
     */
    private const SUSPECT_CADENCE_DAYS = 150;

    /**
     * @param  array<string, mixed> $financials normalised FinancialDataFetcher payload
     * @return array<string, string> field name => human-readable (Polish) reason
     */
    public static function detect(array $financials): array
    {
        $flags = [];

        // Consistency: FCF cannot exceed OCF when capex is non-negative
        // (FCF = OCF - capex). Both must be present to compare — a missing
        // value is a different question, handled by the expected-missing
        // rule below, never coalesced into this comparison.
        $fcf = $financials['free_cash_flow'] ?? null;
        $ocf = $financials['operating_cash_flow'] ?? null;
        if ($fcf !== null && $ocf !== null && (float) $fcf > (float) $ocf) {
            $flags['free_cash_flow'] = sprintf(
                'FCF (%s) przewyższa OCF (%s) — matematycznie niemożliwe przy nieujemnym capexie.',
                (string) $fcf,
                (string) $ocf
            );
        }

        // Cadence: days_since_earnings inconsistent with quarterly reporting.
        $daysSinceEarnings = $financials['days_since_earnings'] ?? null;
        if ($daysSinceEarnings !== null && (int) $daysSinceEarnings > self::SUSPECT_CADENCE_DAYS) {
            $flags['days_since_earnings'] = sprintf(
                '%d dni od ostatnich wyników — niespójne z typowym cyklem kwartalnym.',
                (int) $daysSinceEarnings
            );
        }

        // Expected-missing: fields a company this size should have, but don't.
        foreach (FundamentalFieldRegistry::EXPECTED_NON_NULL as $field) {
            if (($financials[$field] ?? null) === null) {
                $flags[$field] = 'Brak danych — spodziewana wartość dla spółki tej wielkości.';
            }
        }

        return $flags;
    }
}
