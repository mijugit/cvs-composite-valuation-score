<?php

declare(strict_types=1);

/**
 * LLM_GPT_Luna_Wallet Configuration.
 *
 * Mirrors config/llm-gemini-wallet.php's shape 1:1 (starting capital, market
 * hours, rebalance window, memory/context knobs, candidate cap) — same
 * mechanism, same starting parameters, different executing LLM (GPT "Luna"
 * flavor instead of Gemini). No 'strategy' block, same as both siblings: no
 * target positions, weight caps, or stop-loss/take-profit thresholds (no
 * DecisionEnforcer-equivalent).
 *
 * Unlike llm-free-wallet.php, there is no 'llm.model' key here — the model ID
 * is env-driven in config/gpt.php (GPT_MODEL_Luna), not hardcoded. There is
 * also no 'llm.reasoning_effort' override — this wallet deliberately inherits
 * config/gpt.php's shared default ('medium'), the same value
 * GPTCriticalReviewService already uses (decision: llm-gpt-luna-wallet).
 */
return [

    // --- Starting capital ---
    // Same as the other three wallets — a different amount would break the
    // comparison the whole experiment exists to make.
    'initial_capital_usd' => 10000.0,

    // --- NYSE market hours (America/New_York timezone) ---
    // close_time here is 16:30, not the real 16:00 ET close — 30 minutes of
    // padding so a cron firing shortly after the bell still lands in-window.
    'market' => [
        'open_time'  => '09:30',
        'close_time' => '16:30',
        'timezone'   => 'America/New_York',
    ],

    // --- Rebalance window ---
    // Deliberately WIDE (change: llm-gpt-luna-wallet, mirrors llm-gemini-wallet's
    // 2026-08-19 decision) — unlike LLM_Free_Wallet's narrow 90-minute
    // near-close window, this wallet covers the full trading session:
    // [close_time - 420min, close_time) = [09:30, 16:30) ET. The operator
    // controls exactly when the cron fires within that window.
    'rebalance_window_minutes' => 420,

    // --- Memory / context knobs unique to this module ---
    'legend_context_count' => 10,   // N last legend entries read back as context
    'context_search_cap'   => 3,    // max fresh web_search sub-calls per cycle (identical to llm-gemini-wallet, decision: llm-gpt-luna-wallet)
    'legend_max_chars'     => 4000, // max legend length enforced by LlmFreeDecisionParser (reused as-is)

    // Bounded, sorted (CVS Swing desc) slice of the full screener shown in the
    // decision prompt's candidate table — same guardrail and same value as
    // both sibling wallets (see llm-free-wallet.php's comment for the
    // incident this cap prevents).
    'max_candidates' => 40,

    // --- LLM configuration for the decision + legend call ---
    // Overrides config/gpt.php for this module's behaviour. No 'model' or
    // 'reasoning_effort' key here (both env-driven / shared-default — see
    // class docblock above).
    'llm' => [
        'max_retries'         => 0,     // service-level retry owns the policy (LlmGptLunaDecisionService)
        'max_tokens'          => 8192,  // headroom for legend text + decisions
        'timeout'             => 180,   // no request-lifecycle time pressure — cron-only entrypoint
        'total_timeout'       => 200,
        'retry_base_delay_ms' => 0,     // irrelevant at max_retries=0
        'retry_delay_seconds' => 2,     // flat delay between service-level attempts
        'system_prompt_ttl'   => '5m',  // accepted for signature parity with CacheableSystem; ignored by GPTClient
    ],

];
