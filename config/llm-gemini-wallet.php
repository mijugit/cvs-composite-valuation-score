<?php

declare(strict_types=1);

/**
 * LLM_Gemini_Wallet Configuration.
 *
 * Mirrors config/llm-free-wallet.php's shape 1:1 (starting capital, market hours,
 * rebalance window, memory/context knobs, candidate cap) — same mechanism, same
 * starting parameters, different executing LLM. No 'strategy' block, same as the
 * sibling: no target positions, weight caps, or stop-loss/take-profit thresholds
 * (no DecisionEnforcer-equivalent).
 *
 * Unlike llm-free-wallet.php, there is no 'llm.model' key here — the model ID is
 * env-driven in config/gemini.php (GEMINI_MODEL), not hardcoded, because Google
 * retires Gemini model IDs faster than Anthropic retires Claude ones.
 */
return [

    // --- Starting capital ---
    // Same as the other two wallets — a different amount would break the
    // comparison the whole experiment exists to make.
    'initial_capital_usd' => 10000.0,

    // --- NYSE market hours (America/New_York timezone) ---
    // Identical to config/llm-free-wallet.php's block — same practical
    // execution-window bound (17:00, not the real 16:00 ET close).
    'market' => [
        'open_time'  => '09:30',
        'close_time' => '17:00',
        'timezone'   => 'America/New_York',
    ],

    // --- Rebalance window ---
    // Mirrors llm-free-wallet.php's 90-minute window. Cron entries chosen 10
    // minutes earlier than the sibling's (21:40/22:40 Warsaw vs 21:50/22:50) —
    // a pure time-translation of the sibling's proven DST-safe schedule, so it
    // inherits the same safety margin without recomputing it. See
    // bin/llm-gemini-wallet-rebalance.php's docblock for the full walk-through.
    'rebalance_window_minutes' => 90,

    // --- Memory / context knobs unique to this module ---
    'legend_context_count' => 10,   // N last legend entries read back as context
    'context_search_cap'   => 3,    // max fresh googleSearch sub-calls per cycle (cost-bounding lever)
    'legend_max_chars'     => 4000, // max legend length enforced by LlmFreeDecisionParser (reused as-is)

    // Bounded, sorted (CVS Swing desc) slice of the full screener shown in the
    // decision prompt's candidate table — same guardrail and same value as the
    // sibling wallet (see llm-free-wallet.php's comment for the incident this
    // cap prevents: an unbounded 300+-row prompt hung the live cron 2026-08-07).
    'max_candidates' => 40,

    // --- LLM configuration for the decision + legend call ---
    // Overrides config/gemini.php for this module's behaviour. No 'model' key
    // here (env-driven in config/gemini.php — see class docblock above).
    'llm' => [
        'max_retries'         => 0,     // service-level retry owns the policy (LlmGeminiDecisionService)
        'max_tokens'          => 8192,  // headroom for legend text + decisions
        'timeout'             => 180,   // no request-lifecycle time pressure — cron-only entrypoint
        'total_timeout'       => 200,
        'retry_base_delay_ms' => 0,     // irrelevant at max_retries=0
        'retry_delay_seconds' => 2,     // flat delay between service-level attempts
        'system_prompt_ttl'   => '5m',  // accepted for signature parity with Claude's CacheableSystem; ignored by GeminiClient
    ],

];
