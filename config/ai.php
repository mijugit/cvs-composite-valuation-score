<?php

declare(strict_types=1);

/**
 * Claude API client configuration.
 *
 * Mirrors config/cvs-weights.php: returns a plain array, read from $_ENV.
 * The API key and model ID are NEVER hardcoded — they come from .env.
 * (Model IDs change over time; keep the current ID in .env, not in code.)
 *
 * Consumed by CVS\Ai\ClaudeClientFactory::fromConfig().
 */
return [
    // Secret — only ever sent as the x-api-key header; never logged.
    'api_key'           => (string) ($_ENV['ANTHROPIC_API_KEY'] ?? ''),

    // Messages API endpoint.
    'base_url'          => (string) ($_ENV['AI_BASE_URL'] ?? 'https://api.anthropic.com/v1/messages'),

    // Current model ID (e.g. a Sonnet or Haiku build) — set in .env at deploy time.
    'model'             => (string) ($_ENV['AI_MODEL'] ?? ''),

    // Stable API version header.
    'anthropic_version' => (string) ($_ENV['AI_ANTHROPIC_VERSION'] ?? '2023-06-01'),

    // Generation cap.
    'max_tokens'        => (int) ($_ENV['AI_MAX_TOKENS'] ?? 2048),

    // Per-attempt timeout (seconds). Retry logic keeps total wall-time < ~25s.
    'timeout'           => (int) ($_ENV['AI_TIMEOUT'] ?? 20),

    // Retries on transient failures (429 / 529 / 5xx / timeout / network), budget-capped.
    'max_retries'       => (int) ($_ENV['AI_MAX_RETRIES'] ?? 2),

    // Total wall-clock budget (seconds) across attempts+backoff — keeps total < NFR 30s.
    'total_timeout'     => (int) ($_ENV['AI_TOTAL_TIMEOUT'] ?? 25),

    // Exponential backoff base (ms): delay = base * 2^attempt. Set 0 in tests.
    'retry_base_delay_ms' => (int) ($_ENV['AI_RETRY_BASE_DELAY_MS'] ?? 500),

    // PRO access limits — number of AI generation calls per user.
    'pro' => [
        'daily_limit'   => (int) ($_ENV['AI_PRO_DAILY_LIMIT']   ?? 10),
        'monthly_limit' => (int) ($_ENV['AI_PRO_MONTHLY_LIMIT'] ?? 100),
    ],
];
