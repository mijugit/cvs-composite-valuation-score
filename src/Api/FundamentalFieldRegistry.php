<?php

declare(strict_types=1);

namespace CVS\Api;

/**
 * Single source of truth for which FinancialDataFetcher fields the
 * fundamentals-validation feature is allowed to touch, and their types.
 *
 * Referenced by SuspectFieldDetector (what to flag), FundamentalOverrideMerger
 * (how to cast a stored override back into $financials), and
 * FundamentalsValidationService (what to ask Gemini for) — one whitelist so
 * the three can never drift apart (context/foundation/lessons.md, "Dwie
 * implementacje jednej reguły zawsze się rozjadą").
 */
final class FundamentalFieldRegistry
{
    /**
     * Fields that feed CVSModel scoring — the full set "Sprawdź wszystkie dane"
     * sends. Deliberately excludes fields with no scoring effect (beta,
     * employees, website, short_pct_float, institutional_ownership, ...).
     *
     * @var list<string>
     */
    public const SCORING_FIELDS = [
        'revenue',
        'gross_profit',
        'ebitda',
        'free_cash_flow',
        'operating_cash_flow',
        'total_debt',
        'cash',
        'total_equity',
        'current_assets',
        'current_liabilities',
        'price_to_book',
        'book_value_per_share',
        'return_on_equity',
        'return_on_assets',
        'trailing_pe',
        'forward_pe',
        'ps_ratio',
        'ev_ebitda',
        'peg_ratio',
        'dividend_yield',
        'payout_ratio',
        'gross_margins',
        'operating_margin',
        'profit_margin',
        'revenue_growth',
        'forward_eps',
        'trailing_eps',
        'shares_outstanding',
    ];

    /**
     * Fields flagged suspect when NULL — companies large enough to be tracked
     * here are expected to have these; a NULL is a Yahoo gap, not an
     * expected absence. Deliberately excludes fields that are legitimately
     * NULL under normal conditions (e.g. trailing_pe on negative EPS) — those
     * must never be flagged just because they're empty.
     *
     * @var list<string>
     */
    public const EXPECTED_NON_NULL = [
        'gross_profit',
        'total_equity',
        'current_assets',
        'current_liabilities',
        'ps_ratio',
        'moving_average_200',
    ];

    /**
     * Fields resolved by local computation, never sent to Gemini.
     *
     * @var list<string>
     */
    public const LOCALLY_COMPUTED = [
        'moving_average_200',
    ];

    /**
     * Gemini is asked for dates, never day-counts (it would have to silently
     * assume "today"). Maps the date field name in the prompt/response to the
     * day-count field actually stored as an override — the worker computes
     * the difference against the current date at write time, mirroring how
     * FinancialDataFetcher's own EarningsTiming calculation works.
     *
     * @var array<string, string>
     */
    public const EARNINGS_DATE_FIELDS = [
        'last_reported_fiscal_quarter_end' => 'days_since_earnings',
        'next_earnings_date'               => 'days_to_earnings',
    ];

    /**
     * Storage/casting type for every field this feature may write into
     * $financials — covers SCORING_FIELDS, LOCALLY_COMPUTED, and the derived
     * day-count fields from EARNINGS_DATE_FIELDS.
     *
     * @var array<string, 'int'|'float'>
     */
    public const FIELD_TYPES = [
        'revenue'              => 'float',
        'gross_profit'         => 'float',
        'ebitda'               => 'float',
        'free_cash_flow'       => 'float',
        'operating_cash_flow'  => 'float',
        'total_debt'           => 'float',
        'cash'                 => 'float',
        'total_equity'         => 'float',
        'current_assets'       => 'float',
        'current_liabilities'  => 'float',
        'price_to_book'        => 'float',
        'book_value_per_share' => 'float',
        'return_on_equity'     => 'float',
        'return_on_assets'     => 'float',
        'trailing_pe'          => 'float',
        'forward_pe'           => 'float',
        'ps_ratio'             => 'float',
        'ev_ebitda'            => 'float',
        'peg_ratio'            => 'float',
        'dividend_yield'       => 'float',
        'payout_ratio'         => 'float',
        'gross_margins'        => 'float',
        'operating_margin'     => 'float',
        'profit_margin'        => 'float',
        'revenue_growth'       => 'float',
        'forward_eps'          => 'float',
        'trailing_eps'         => 'float',
        'shares_outstanding'   => 'int',
        'moving_average_200'   => 'float',
        'days_since_earnings'  => 'int',
        'days_to_earnings'     => 'int',
    ];
}
