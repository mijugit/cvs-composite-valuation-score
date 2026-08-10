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
    // NOTE: 'close_time' below is 17:00, not the real NYSE close (still
    // 16:00 ET always) — it's the outer bound of the rebalance window's
    // deliberately widened practical execution deadline. See the window
    // comment below for why.
    'market' => [
        'open_time'  => '09:30',
        'close_time' => '17:00',
        'timezone'   => 'America/New_York',
    ],

    // --- Rebalance window ---
    // Operator's chosen design (2026-08-10): two cron entries only —
    // 21:50 Warsaw (primary) and 22:50 Warsaw (backup, catches it if the
    // primary doesn't fire or fails) — instead of three DST-offset-covering
    // entries. This trades full automatic DST coverage for simplicity; the
    // operator watches the EU/US DST transition weeks (mid-March,
    // late-Oct/early-Nov) and adjusts the cron times by hand if needed.
    // 90 minutes = [15:30, 17:00) ET, chosen so BOTH entries land inside the
    // window at the nominal 6h offset (21:50→15:50 ET ideal target,
    // 22:50→16:50 ET dormant backup) and at least one still lands in-window
    // during either DST mismatch (5h or 7h offset) — see bin/llm-free-wallet-
    // rebalance.php's docblock for the full walk-through per offset.
    'rebalance_window_minutes' => 90,

    // --- Memory / context knobs unique to this module ---
    'legend_context_count' => 10,   // N last legend entries read back as context (questioning round 1)
    'context_search_cap'   => 3,    // max fresh web-search sub-calls per cycle (cost-bounding lever)
    'legend_max_chars'     => 4000, // max legend length enforced by LlmFreeDecisionParser

    // Bounded, sorted (CVS Swing desc) slice of the full screener shown in the
    // decision prompt's candidate table. The sibling wallet never needs an
    // explicit cap — its prompt only ever includes golden=strong signals
    // (typically a few dozen rows out of the full screener). This module has
    // no such pre-filter by design (full interpretive freedom), so without an
    // explicit bound the prompt scales with the whole screener (300+ rows on
    // a real run) — observed live on 2026-08-07 to blow past the ~$0.50/cycle
    // cost guardrail and hang/crash the cron. 0 = unbounded (not recommended).
    'max_candidates' => 40,

    // --- LLM configuration for the decision + legend call ---
    // Overrides config/ai.php for this module's behaviour. 'model' here is a
    // deliberate override of the global AI_MODEL env var — this wallet runs
    // on a different model than the baseline "LLM Bazowy" portfolio and the
    // stage-1/stage-2 AI analysis features, which all inherit AI_MODEL
    // unmodified. Changing AI_MODEL itself would have silently switched the
    // baseline wallet's model too, breaking the "everything else equal"
    // comparison the whole experiment depends on (caught before deploy,
    // 2026-08-10). NOTE: LlmFreeContextGatherer's web-search sub-calls are
    // NOT covered by this override — they're constructed from the raw,
    // unmerged config/ai.php in bin/llm-free-wallet-rebalance.php, so they
    // still run on whatever AI_MODEL is set globally. That's intentional:
    // context pre-fetch is a cheaper, secondary concern, not the flagship
    // decision call this override exists for.
    'llm' => [
        'model'               => 'claude-sonnet-5',
        'max_retries'         => 0,     // service-level retry owns the policy (LlmFreeDecisionService)
        'max_tokens'          => 8192,  // headroom for adaptive thinking (on by default on Sonnet 5) + legend text
        'timeout'             => 45,
        'total_timeout'       => 55,
        'retry_base_delay_ms' => 0,     // irrelevant at max_retries=0
        'retry_delay_seconds' => 2,     // flat delay between service-level attempts
        'system_prompt_ttl'   => '5m',  // CacheableSystem TTL
    ],

];
