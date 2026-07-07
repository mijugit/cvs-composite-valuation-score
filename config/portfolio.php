<?php

declare(strict_types=1);

/**
 * Virtual Portfolio Configuration.
 *
 * All scheduler and market-calendar parameters live here so they can be
 * changed without touching business logic (FR-010).
 *
 * NYSE holidays must be reviewed and extended each year.
 * Last updated: 2026-06-26 (covers 2025-2027 + Dec 31 2027 observed NYD 2028).
 */
return [

    // --- Starting capital ---
    'initial_capital_usd' => 10000.0,

    // --- NYSE market hours (America/New_York timezone) ---
    'market' => [
        'open_time'  => '09:30',
        'close_time' => '16:00',
        'timezone'   => 'America/New_York',
    ],

    // --- Rebalance window ---
    // Full NYSE session: 09:30–16:00 ET = 15:30–22:00 Warsaw = 390 minutes.
    // Cron schedule in CF panel controls the exact fire time — this window just
    // prevents accidental off-hours execution (weekends, before open, after close).
    'rebalance_window_minutes' => 390,

    // --- Swing strategy parameters (FR-010: tune here, never hardcode in logic) ---
    // Drives both the LLM system prompt (DecisionService::buildSystemPrompt) and the
    // candidate pre-filter / position-sizing data block (DecisionService::buildDataBlock).
    // Swing thresholds mirror config/cvs-weights.php['thresholds'] (accumulate=58,
    // strong_buy=72) so the portfolio speaks the same language as the CVS model.
    'strategy' => [
        'target_positions'       => 10,      // ideal number of holdings
        'min_positions'          => 8,       // soft floor
        'max_positions'          => 12,      // soft ceiling
        'target_weight_pct'      => 10.0,    // ~equal-weight target per position
        'max_weight_pct'         => 15.0,    // HARD cap per single stock
        'max_sector_pct'         => 40.0,    // HARD cap per sector (anti tech-pile-in)
        'min_emerging_positions' => 2,       // min positions from the "emerging" swing band

        // Buy eligibility — only this golden signal qualifies a NEW purchase.
        'buy_signal'             => 'strong', // swing>=58 AND fund>=58

        // "Emerging strong" band: strong signal but swing still in accumulate range
        // [low, high) — pretenders to SILNE KUPUJ, entered early before the move.
        'emerging_swing_low'     => 58.0,    // inclusive (= accumulate threshold)
        'emerging_swing_high'    => 72.0,    // exclusive (= strong_buy threshold)

        // Sell hysteresis: enter strong at >=58, exit when swing < this (tighter = 54
        // exits weakening positions sooner; 50 would hold longer).
        'sell_swing_below'       => 54.0,

        // P&L exit rules (percent of entry price, on unrealized gain/loss):
        //   stop_loss_pct  — HARD, server-enforced in DecisionEnforcer: a holding at
        //                    or below -stop_loss_pct is force-sold even if the model
        //                    says HOLD (capital protection must not depend on the LLM).
        //   take_profit_pct — SOFT, prompt-driven: the model sees P&L and decides when
        //                    to lock a gain (judgement on still-accelerating winners).
        'take_profit_pct'        => 25.0,
        'stop_loss_pct'          => 15.0,

        // Daily retry budget: a cycle may be (re)started this many times when the
        // previous attempt failed (llm_failed/failed). Timing of the retries is
        // driven by the cron schedule (e.g. base time + 5/10/15 min later); the
        // script only retries if the prior attempt failed and the budget remains.
        'max_daily_attempts'     => 3,
    ],

    // --- LLM configuration for portfolio rebalance calls ---
    // Overrides config/ai.php for portfolio-specific behaviour.
    // Merged via array_merge($aiConfig, $portfolioConfig['llm']) in bin/portfolio-rebalance.php.
    'llm' => [
        'max_retries'         => 0,     // service-level retry owns the policy (DecisionService)
        'max_tokens'          => 4096,
        'timeout'             => 45,    // per-attempt seconds (bumped: full-screener prompt is a long call)
        'total_timeout'       => 55,
        'retry_base_delay_ms' => 0,     // irrelevant at max_retries=0
        'retry_delay_seconds' => 2,     // flat delay between service-level attempts
        'max_candidates'      => null,  // null = all screener tickers (set to N to cap)
        'reason_max_chars'    => 500,   // max reason length enforced by DecisionParser
        'system_prompt_ttl'   => '5m',  // CacheableSystem TTL
    ],

    // --- NYSE non-trading days 2026-2027 ---
    // Format: 'YYYY-MM-DD'. Observed dates used when the holiday falls on a weekend.
    // Source: official NYSE holiday calendar. Extend each year as needed.
    'holidays' => [

        // 2026
        '2026-01-01', // New Year's Day
        '2026-01-19', // Martin Luther King Jr. Day (3rd Monday January)
        '2026-02-16', // Presidents' Day (3rd Monday February)
        '2026-04-03', // Good Friday (Easter: April 5, 2026)
        '2026-05-25', // Memorial Day (last Monday May)
        '2026-06-19', // Juneteenth National Independence Day
        '2026-07-03', // Independence Day observed (July 4 falls on Saturday)
        '2026-09-07', // Labor Day (1st Monday September)
        '2026-11-26', // Thanksgiving Day (4th Thursday November)
        '2026-12-25', // Christmas Day

        // 2027
        '2027-01-01', // New Year's Day
        '2027-01-18', // Martin Luther King Jr. Day (3rd Monday January)
        '2027-02-15', // Presidents' Day (3rd Monday February)
        '2027-03-26', // Good Friday (Easter: March 28, 2027)
        '2027-05-31', // Memorial Day (last Monday May)
        '2027-06-18', // Juneteenth observed (June 19 falls on Saturday)
        '2027-07-05', // Independence Day observed (July 4 falls on Sunday)
        '2027-09-06', // Labor Day (1st Monday September)
        '2027-11-25', // Thanksgiving Day (4th Thursday November)
        '2027-12-24', // Christmas Day observed (December 25 falls on Saturday)
        '2027-12-31', // New Year's Day 2028 observed (January 1, 2028 falls on Saturday)
    ],

];
