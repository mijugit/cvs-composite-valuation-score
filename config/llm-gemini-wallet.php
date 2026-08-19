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
    // close_time here is 16:30, not the real 16:00 ET close — 30 minutes of
    // padding so a cron firing shortly after the bell still lands in-window.
    'market' => [
        'open_time'  => '09:30',
        'close_time' => '16:30',
        'timezone'   => 'America/New_York',
    ],

    // --- Rebalance window ---
    // Deliberately WIDE (change: llm-gemini-wallet, 2026-08-19) — unlike the
    // sibling LLM_Free_Wallet's narrow 90-minute near-close window, this wallet
    // covers the full trading session: [close_time - 420min, close_time) =
    // [09:30, 16:30) ET = 15:30–22:30 Warsaw at the nominal 6h summer offset.
    // The operator controls exactly when the cron fires (typically near
    // session close) and wanted flexibility to trigger test runs at any point
    // during the session without recomputing a DST-safe two-entry schedule —
    // MarketCalendar::isInRebalanceWindow() only rejects requests outside real
    // trading hours, it does not dictate when within them to run.
    'rebalance_window_minutes' => 420,

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
