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

        // (1) Revenue must be > 0
        if ($this->thresholds['require_positive_revenue'] && ($financials['revenue'] ?? 0) <= 0) {
            $failures[] = 'Brak przychodów (revenue ≤ 0)';
        }

        // (2) Gross margin ≥ min_gross_margin
        $grossMargin = $this->safeDiv(
            ($financials['gross_profit'] ?? null),
            ($financials['revenue']     ?? null)
        );
        if ($grossMargin !== null && $grossMargin < $this->thresholds['min_gross_margin']) {
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
