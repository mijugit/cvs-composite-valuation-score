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

    // --- Pillar weights (must sum to 1.0) ---
    'weights' => [
        'growth'     => 0.30, // (a) Growth rate vs own trajectory
        'sector'     => 0.25, // (b) Sector benchmark comparison
        'momentum'   => 0.25, // (c) Price momentum vs market (ROC 6M+3M vs SPY)
        'quality'    => 0.20, // (d) Fundamental quality
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

    // --- MomentumPillar parameters ---
    'momentum' => [
        'normalization_divisor' => 40.0,  // excess return divisor (matches Python v1.6)
        'score_min'             =>  5.0,  // floor score
        'score_max'             => 95.0,  // ceiling score
    ],

    // --- Data source ---
    'data_source' => [
        'provider'        => 'yahoo_finance',
        'max_tickers'     => 10,    // soft cap; enforced by response-time guardrail
        'timeout_seconds' => 25,    // API call timeout per ticker
        'cache_ttl'       => 3600,  // seconds; cache raw API response per ticker
    ],

];
