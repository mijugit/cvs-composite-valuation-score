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
        'history'    => 0.25, // (c) Price history percentile
        'quality'    => 0.20, // (d) Fundamental quality
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

    // --- Data source ---
    'data_source' => [
        'provider'        => 'yahoo_finance',
        'max_tickers'     => 10,    // soft cap; enforced by response-time guardrail
        'timeout_seconds' => 25,    // API call timeout per ticker
        'cache_ttl'       => 3600,  // seconds; cache raw API response per ticker
    ],

];
