<?php

declare(strict_types=1);

namespace CVS\CVS;

/**
 * Quality Gate — binary pre-filter applied before any CVS calculation.
 *
 * A company that fails the Quality Gate receives no CVS score.
 * The gate thresholds are read from config/cvs-weights.php so they can
 * be tuned without touching business logic (FR-010).
 */
class QualityGate
{
    /** @param array<string, mixed> $thresholds  The 'quality_gate' section from cvs-weights.php */
    public function __construct(private readonly array $thresholds) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Evaluate financials against all gate criteria.
     *
     * @param array<string, mixed> $financials  Normalised financial data (see FinancialDataFetcher)
     * @return QualityGateResult
     */
    public function evaluate(array $financials): QualityGateResult
    {
        $failures = [];

        // (1) Revenue must be > 0.
        // A MISSING revenue is "unknown", not "zero" — the old `?? 0` coerced an
        // absent field into a hard business rejection, so an upstream data gap
        // read exactly like a company with no sales. Yahoo returns an empty
        // incomeStatementHistory array often enough (observed on MU across
        // 2026-08-13/14, a ~$40bn-revenue company) that the distinction matters.
        // Checks (2)-(4) below have always skipped on null; this one now agrees.
        // Callers that must not score a data-starved payload at all should reject
        // it before this point — see PayloadCompleteness::missingEssentialFields().
        $revenue = $financials['revenue'] ?? null;
        if ($this->thresholds['require_positive_revenue'] && $revenue !== null && (float) $revenue <= 0) {
            $failures[] = 'Brak przychodów (revenue ≤ 0)';
        }

        // (2) Gross margin ≥ min_gross_margin
        // Prefer financialData.grossMargins (most reliable Yahoo Finance field).
        // Fall back to gross_profit / revenue from the income statement —
        // Yahoo's incomeStatementHistory.grossProfit occasionally returns 0
        // as a data artefact even for high-margin companies (e.g. AAPL).
        // Sectors that do not report gross profit at all (banks) are exempt —
        // Yahoo returns 0, which would otherwise reject every constituent on a
        // metric that never applied to them. See quality_gate.skip_gross_margin_sectors.
        $skipMarginSectors = $this->thresholds['skip_gross_margin_sectors'] ?? [];
        $sector            = (string) ($financials['sector'] ?? '');
        $marginApplies     = !in_array($sector, $skipMarginSectors, true);

        $grossMargin = ($financials['gross_margins'] ?? null)
            ?? $this->safeDiv(
                ($financials['gross_profit'] ?? null),
                ($financials['revenue']     ?? null)
            );
        if ($marginApplies && $grossMargin !== null && $grossMargin < $this->thresholds['min_gross_margin']) {
            $failures[] = sprintf(
                'Marża brutto %.1f%% < minimalnej %.1f%%',
                $grossMargin * 100,
                $this->thresholds['min_gross_margin'] * 100
            );
        }

        // (3) Debt-to-equity ≤ max_debt_to_equity
        $dte = $this->safeDiv(
            ($financials['total_debt']   ?? null),
            ($financials['total_equity'] ?? null)
        );
        if ($dte !== null && $dte > $this->thresholds['max_debt_to_equity']) {
            $failures[] = sprintf(
                'Dług/Kapitał %.2fx > maksymalnego %.1fx',
                $dte,
                $this->thresholds['max_debt_to_equity']
            );
        }

        // (4) Current ratio ≥ min_current_ratio
        $currentRatio = $this->safeDiv(
            ($financials['current_assets']      ?? null),
            ($financials['current_liabilities'] ?? null)
        );
        if ($currentRatio !== null && $currentRatio < $this->thresholds['min_current_ratio']) {
            $failures[] = sprintf(
                'Wskaźnik płynności %.2f < minimalnego %.1f',
                $currentRatio,
                $this->thresholds['min_current_ratio']
            );
        }

        return new QualityGateResult(
            passed:   empty($failures),
            failures: $failures
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function safeDiv(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }
        return $numerator / $denominator;
    }
}
