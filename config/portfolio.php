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
    // Number of minutes before market close when the rebalance cycle may fire.
    // Cron fires at 20:30 and 21:30 Warsaw; the script checks this window at runtime.
    'rebalance_window_minutes' => 30,

    // --- LLM configuration for portfolio rebalance calls ---
    // Overrides config/ai.php for portfolio-specific behaviour.
    // Merged via array_merge($aiConfig, $portfolioConfig['llm']) in bin/portfolio-rebalance.php.
    'llm' => [
        'max_retries'         => 0,     // service-level retry owns the policy (DecisionService)
        'max_tokens'          => 2048,
        'timeout'             => 20,    // per-attempt seconds
        'total_timeout'       => 25,
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
