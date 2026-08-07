<?php

declare(strict_types=1);

/**
 * LLM_Free_Wallet Configuration.
 *
 * Mirrors config/portfolio.php's shape for the pieces that carry over
 * unchanged (starting capital, market hours, rebalance window, LLM call
 * budget) and adds the knobs unique to this module: legend memory depth and
 * the cost-bounding search cap. There is deliberately no 'strategy' block —
 * this wallet has no target positions, weight caps, or stop-loss/take-profit
 * thresholds (PRD FR-004: no DecisionEnforcer-equivalent).
 */
return [

    // --- Starting capital ---
    // Same as the baseline wallet — a different amount would break the
    // comparison the whole experiment exists to make.
    'initial_capital_usd' => 10000.0,

    // --- NYSE market hours (America/New_York timezone) ---
    'market' => [
        'open_time'  => '09:30',
        'close_time' => '16:00',
        'timezone'   => 'America/New_York',
    ],

    // --- Rebalance window ---
    // Full NYSE session — same window shape as the baseline wallet; the cron
    // schedule in the CF panel controls the exact fire time (~10 min before
    // close), this just prevents accidental off-hours execution.
    'rebalance_window_minutes' => 390,

    // --- Memory / context knobs unique to this module ---
    'legend_context_count' => 10,   // N last legend entries read back as context (questioning round 1)
    'context_search_cap'   => 3,    // max fresh web-search sub-calls per cycle (cost-bounding lever)
    'legend_max_chars'     => 4000, // max legend length enforced by LlmFreeDecisionParser

    // --- LLM configuration for the decision + legend call ---
    // Overrides config/ai.php for this module's behaviour.
    'llm' => [
        'max_retries'         => 0,     // service-level retry owns the policy (LlmFreeDecisionService)
        'max_tokens'          => 6144,  // higher than the baseline wallet's — affords the legend text
        'timeout'             => 45,
        'total_timeout'       => 55,
        'retry_base_delay_ms' => 0,     // irrelevant at max_retries=0
        'retry_delay_seconds' => 2,     // flat delay between service-level attempts
        'system_prompt_ttl'   => '5m',  // CacheableSystem TTL
    ],

];
