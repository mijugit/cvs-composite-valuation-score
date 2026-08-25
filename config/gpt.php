<?php

declare(strict_types=1);

/**
 * OpenAI (GPT) Responses API client configuration — change: critical-review-openai.
 *
 * Mirrors config/gemini.php's shape (plain array, env-sourced, secrets never
 * hardcoded), extended with per-flavor sub-arrays: Terra and Luna are the
 * identical Responses API shape, differing only in model ID and (per the
 * user's own .env setup) a SEPARATE API key each. GPTClientFactory::fromConfig()
 * flattens the chosen flavor's sub-array into the shared settings below before
 * constructing GPTClient — GPTClient itself never sees "terra"/"luna", only a
 * flat config array, same as ClaudeClient/GeminiClient.
 */
return [
    // generateContent-equivalent endpoint for the Responses API.
    'base_url' => (string) ($_ENV['GPT_BASE_URL'] ?? 'https://api.openai.com/v1/responses'),

    // Generation cap — user-configured 8000 in .env; NOT overridden by a
    // hardcoded service-level constant (see GPTCriticalReviewService).
    'max_tokens' => (int) ($_ENV['GPT_MAX_TOKENS'] ?? 8000),

    // Web-search-enabled calls run detached in a background worker (same
    // pattern as config/gemini.php) — no request-lifecycle time pressure.
    'timeout'       => (int) ($_ENV['GPT_TIMEOUT'] ?? 180),
    'max_retries'   => (int) ($_ENV['GPT_MAX_RETRIES'] ?? 2),
    'total_timeout' => (int) ($_ENV['GPT_TOTAL_TIMEOUT'] ?? 200),

    // Exponential backoff base (ms): delay = base * 2^attempt. Set 0 in tests.
    'retry_base_delay_ms' => (int) ($_ENV['GPT_RETRY_BASE_DELAY_MS'] ?? 500),

    // 'low' | 'medium' | 'high' (verified against current Responses API docs).
    // Same value for both flavors — varying it per flavor would confound a
    // Terra-vs-Luna comparison with a second variable besides model choice.
    'reasoning_effort' => (string) ($_ENV['GPT_REASONING_EFFORT'] ?? 'medium'),

    // Per-flavor secrets/model — separate API keys, per the user's own .env.
    'terra' => [
        'api_key' => (string) ($_ENV['GPT_Terra_CVS'] ?? ''),
        'model'   => (string) ($_ENV['GPT_MODEL_Terra'] ?? 'gpt-5.6-terra'),
    ],
    'luna' => [
        'api_key' => (string) ($_ENV['GPT_Luna_CVS'] ?? ''),
        'model'   => (string) ($_ENV['GPT_MODEL_Luna'] ?? 'gpt-5.6-luna'),
    ],
];
