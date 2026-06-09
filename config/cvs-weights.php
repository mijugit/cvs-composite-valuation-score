<?php

declare(strict_types=1);

/**
 * CVS Model Configuration — weights and thresholds.
 *
 * Weights must sum to 1.0.
 * Modify here to recalibrate the model without touching business logic.
 * FR-010: config change requires no code modification.
 */
return [

    // --- Model versioning (Phase 3) ---
    // Bump model_version whenever the scoring methodology changes so that
    // track-record rows from different methodologies are never mixed.
    // FR-010: never hardcode this in business logic — always read from here.
    'model_version' => '3.0',

    // --- Overlay penalties (Phase 5, slice 1) ---
    // Two deterministic post-aggregation penalties applied on top of the base CVS,
    // computed in SHADOW mode under shadow_version (displayed recommendation stays at
    // model_version until the recalibration slice — guardrail FR-016).
    //
    //   Overlay A (revision): trap = clamp((valScore-50)/50, 0, 1);
    //                         penalty = max(-cap, slope * eps_revision_pct * trap)   [eps_revision_pct < 0 only]
    //   Overlay B (target):   penalty = max(-cap, analyst_target_upside * slope)     [upside < 0 only]
    //
    // Default slope/cap values are illustrative (from sim_overlay.php) — finalised in
    // the recalibration slice. FR-010: never hardcode in business logic; always read here.
    'overlays' => [
        'enabled'        => true,
        'shadow_version' => '3.1',
        'revision'       => ['slope' => 120.0, 'cap' => 18.0],
        'target_gate'    => ['slope' => 60.0,  'cap' => 18.0],
    ],

    // --- Earnings-proximity guard (Phase 5, slice 2) ---
    // Deterministic tempering penalty applied near a company's earnings date —
    // momentum-driven conversion is less trustworthy in the ~K-session window
    // around an earnings event (volatility spikes, gap risk). Computed from
    // days_since_earnings/days_to_earnings (injected at fetch-time — FR-015
    // determinism seam, see EarningsCalendarParser) and added to the shadow
    // overlay's penalties.total alongside revision/target (Phase 5, slice 1).
    //
    //   proximity = max(0, (window_sessions - nearest_in_window_days) / window_sessions) ∈ [0, 1]
    //   penalty   = round(max(-cap, -slope * proximity), 1)
    //
    // window_sessions (K): symmetric before/after window in sessions (OQ-1 decision: K=5).
    // Default slope/cap are illustrative — finalised in the recalibration slice,
    // deliberately gentler than overlays.{revision,target_gate} (this tempers, it
    // doesn't punish). FR-010: never hardcode in business logic; always read here.
    //
    // Also drives the always-present `earnings_timing` badge in CVSResult (FR-010) —
    // `enabled` here gates ONLY the shadow penalty, never the badge (FR-017: the
    // badge must work for every user regardless of overlay/shadow-mode flags).
    'earnings_guard' => [
        'enabled'         => true,
        'window_sessions' => 5,
        'penalty'         => ['slope' => 10.0, 'cap' => 10.0],
    ],

    // --- Valuation pillar — FCF normalization (Phase 5 plaster 3, FR-011) ---
    // Controls the forward-FCF-estimate path in ValuationPillar.
    //
    // use_forward_fcf_estimate: when true, ValuationPillar uses
    //   forward_fcf_est = forward_eps × (trailing_fcf / trailing_eps)
    //   as the EV/FCF denominator instead of trailing_fcf × (1+g)².
    //   This corrects trough-capex distortions (e.g. MU during HBM build-out)
    //   where trailing FCF is depressed but analyst EPS estimates show recovery.
    //   Set false to fall back to the pre-normalization formula everywhere.
    //
    // fcf_to_eps_ratio_min/max: bounds on the trailing FCF/EPS conversion ratio.
    //   If free_cash_flow / trailing_eps falls outside [min, max], the estimate
    //   is discarded and the formula falls back to trailing_fcf × (1+g)².
    //   Prevents pathological cases (near-zero EPS, outlier capex ratios).
    //   FR-010: never hardcode these bounds in business logic; always read here.
    'valuation' => [
        'use_forward_fcf_estimate' => true,
        'fcf_to_eps_ratio_min'     => 0.3,   // below → ratio too small, skip estimate
        'fcf_to_eps_ratio_max'     => 3.0,   // above → ratio too large, skip estimate
    ],

    // --- Peer-group configuration (Phase 3) ---
    // Controls empirical subsector median lookups in MedianResolver.
    //
    // min_sample_count (N): minimum number of tickers in a bucket before the
    //   subsector median is trusted. Below N → fall back to sector median.
    //   Set conservatively: a median from 3 tickers is noisy.
    //
    // anchor_blend: rule used to combine subsector score with sector anchor.
    //   'min'      — final score = min(subsectorScore, sectorScore).
    //                Kotwica can only pull the score DOWN, never up.
    //                Default safe start — tune on real data in Phase 3 manual verification.
    //   'weighted' — reserved for future tuning; MedianResolver will read
    //                anchor_weight when this mode is active.
    //
    // anchor_weight: weight of the sector anchor when anchor_blend='weighted'.
    //   0.0 = pure subsector, 1.0 = pure sector anchor.
    //
    // enabled: master switch. false = Phase 3 code loaded but resolver always
    //   falls back to legacy benchmarks (safe rollout / kill-switch).
    'peer_group' => [
        'enabled'          => true,
        'min_sample_count' => 5,
        'anchor_blend'     => 'min',
        'anchor_weight'    => 0.3,
    ],

    // --- Batch schedule (Phase 3) ---
    // Maps day-of-week (1=Mon…7=Sun) to the list of sectors refreshed that day.
    // This spreads the ~477-ticker population crawl across the week to stay
    // well within Yahoo Finance's unofficial rate limits.
    // Sector names must match values returned by Yahoo Finance assetProfile.sector.
    'batch_schedule' => [
        1 => ['Technology', 'Communication Services'],
        2 => ['Healthcare', 'Financial Services'],
        3 => ['Consumer Cyclical', 'Consumer Defensive'],
        4 => ['Industrials', 'Basic Materials'],
        5 => ['Energy', 'Utilities', 'Real Estate'],
        6 => [],
        7 => [],
    ],

    // --- Dual-mode scoring profiles (S-05) ---
    // Each mode defines pillar weights and ROC composite weights for MomentumPillar.
    // Pillar raw scores (Valuation, Quality) are identical in both modes.
    // Only MomentumPillar uses roc_weights — it returns different composites per mode.
    // FR-010: weights must never be hardcoded in business logic; always read from here.
    'modes' => [
        'swing' => [
            'label'            => 'Swing (1–4 mies.)',
            'valuation_weight' => 0.40,
            'momentum_weight'  => 0.45,
            'quality_weight'   => 0.15,
            'roc_weights'      => ['1m' => 0.50, '3m' => 0.30, '6m' => 0.20],
            'sigmoid_k'        => 3.0,
            'momentum_cap_min' => 5.0,
            'momentum_cap_max' => 95.0,
            'momentum_divisor' => 40.0,
        ],
        'fundamental' => [
            'label'            => 'Fundamentalny (6–12 mies.)',
            'valuation_weight' => 0.65,
            'momentum_weight'  => 0.15,
            'quality_weight'   => 0.20,
            'roc_weights'      => ['3m' => 0.30, '6m' => 0.40, '12m' => 0.30],
            'sigmoid_k'        => 3.0,
            'momentum_cap_min' => 5.0,
            'momentum_cap_max' => 95.0,
            'momentum_divisor' => 40.0,
        ],
    ],

    // --- Sector benchmark medians (hardcoded from Python cvs_analyze.py v1.6 BENCHMARKS dict) ---
    // Used by SectorBenchmarkPillar to score EV/FCF or EV/Sales relative to sector norms.
    // median_ev_fcf:  sector median EV/forward FCF
    // median_ev_sales: sector median EV/forward Sales
    // median_gm:      sector median gross margin (%)
    // max_growth:     sector max growth cap applied to forward estimates (%)
    'benchmarks' => [
        'Technology'             => ['median_ev_fcf' => 32, 'median_ev_sales' =>  8.0, 'median_gm' => 55, 'max_growth' => 60],
        'Healthcare'             => ['median_ev_fcf' => 28, 'median_ev_sales' =>  5.0, 'median_gm' => 60, 'max_growth' => 30],
        'Communication Services' => ['median_ev_fcf' => 22, 'median_ev_sales' =>  4.0, 'median_gm' => 50, 'max_growth' => 25],
        'Consumer Cyclical'      => ['median_ev_fcf' => 20, 'median_ev_sales' =>  1.5, 'median_gm' => 35, 'max_growth' => 20],
        'Consumer Defensive'     => ['median_ev_fcf' => 18, 'median_ev_sales' =>  1.0, 'median_gm' => 40, 'max_growth' =>  8],
        'Industrials'            => ['median_ev_fcf' => 20, 'median_ev_sales' =>  2.0, 'median_gm' => 35, 'max_growth' => 12],
        'Energy'                 => ['median_ev_fcf' => 12, 'median_ev_sales' =>  1.5, 'median_gm' => 30, 'max_growth' => 15],
        'Basic Materials'        => ['median_ev_fcf' => 14, 'median_ev_sales' =>  2.0, 'median_gm' => 35, 'max_growth' => 12],
        'Real Estate'            => ['median_ev_fcf' => 22, 'median_ev_sales' =>  8.0, 'median_gm' => 55, 'max_growth' => 10],
        'Utilities'              => ['median_ev_fcf' => 14, 'median_ev_sales' =>  2.0, 'median_gm' => 30, 'max_growth' =>  5],
        'Financial Services'     => ['median_ev_fcf' => 18, 'median_ev_sales' =>  3.0, 'median_gm' => 70, 'max_growth' => 12],
        'DEFAULT'                => ['median_ev_fcf' => 20, 'median_ev_sales' =>  3.0, 'median_gm' => 40, 'max_growth' => 20],
    ],

    // --- Quality Gate thresholds (binary filter, applied before CVS) ---
    'quality_gate' => [
        'min_gross_margin'      => 0.10,  // < 10% gross margin → FAIL
        'max_debt_to_equity'    => 5.0,   // > 5x D/E → FAIL
        'min_current_ratio'     => 0.5,   // < 0.5 current ratio → FAIL
        'require_positive_revenue' => true, // Revenue must be > 0
    ],

    // --- CVS recommendation thresholds ---
    'thresholds' => [
        'strong_buy'  => 72, // ⬆⬆ SILNE KUPUJ
        'accumulate'  => 58, // ⬆  AKUMULUJ
        'neutral'     => 42, // →  NEUTRALNIE
        'reduce'      => 28, // ⬇  REDUKUJ
        // below 28   → ⬇⬇ UNIKAJ
    ],

    // --- Analyst consensus label thresholds (S-09) ---
    // Maps Yahoo Finance recommendationMean (1 = Strong Buy … 5 = Strong Sell) to a
    // Polish label. Inclusive upper bounds; mean > 'sell' → "Silna Sprzedaż".
    // FR-010: thresholds live in config, never hardcoded in business logic.
    'analyst_consensus' => [
        'strong_buy' => 1.5, // mean ≤ 1.5 → Silne Kupuj
        'buy'        => 2.5, // mean ≤ 2.5 → Kupuj
        'hold'       => 3.5, // mean ≤ 3.5 → Trzymaj
        'sell'       => 4.5, // mean ≤ 4.5 → Sprzedaj
    ],

    // --- Data source ---
    'data_source' => [
        'provider'        => 'yahoo_finance',
        'max_tickers'     => 10,    // soft cap; enforced by response-time guardrail
        'timeout_seconds' => 25,    // API call timeout per ticker
        'cache_ttl'       => 3600,  // seconds; cache raw API response per ticker
        'max_watchlist'   => 50,    // max watchlist entries per user (S-06)
        'max_history'     => 20,    // max analysis-history entries shown on dashboard (S-08)
    ],

];
