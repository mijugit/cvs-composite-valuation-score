<?php

declare(strict_types=1);

/**
 * Gemini API client configuration.
 *
 * Mirrors config/ai.php's shape: returns a plain array, read from $_ENV. The API
 * key and model ID are NEVER hardcoded — they come from .env (FR-010 convention).
 *
 * The key variable name is `Gemini_CVS` (not `GEMINI_API_KEY`) — it already exists
 * on the server under that name (change: llm-gemini-wallet), so the code reads it
 * as-is rather than requiring a server-side rename. $_ENV keys are case-sensitive.
 *
 * Consumed by CVS\Ai\GeminiClientFactory::fromConfig().
 */
return [
    // Secret — only ever sent as the x-goog-api-key header; never logged.
    'api_key'   => (string) ($_ENV['Gemini_CVS'] ?? ''),

    // generateContent REST base (model + ':generateContent' appended by GeminiClient).
    'base_url'  => (string) ($_ENV['GEMINI_BASE_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta'),

    // Current model ID — env-driven (not hardcoded) because Google retires Gemini
    // model IDs faster than Anthropic retires Claude ones (2.0 Flash retired ~4
    // months after release). Default = the model chosen at launch (2026-08-19).
    'model'     => (string) ($_ENV['GEMINI_MODEL'] ?? 'gemini-3.7-flash'),

    // Generation cap.
    'max_tokens' => (int) ($_ENV['GEMINI_MAX_TOKENS'] ?? 8192),

    // Per-attempt timeout (seconds). No request-lifecycle time pressure — this
    // client is only ever called from a cron entrypoint, mirroring the wallet's
    // own 'llm' config override in config/llm-gemini-wallet.php.
    'timeout'   => (int) ($_ENV['GEMINI_TIMEOUT'] ?? 180),

    // Retries on transient failures (429 / 5xx / 504 / timeout / network), budget-capped.
    'max_retries' => (int) ($_ENV['GEMINI_MAX_RETRIES'] ?? 2),

    // Total wall-clock budget (seconds) across attempts+backoff.
    'total_timeout' => (int) ($_ENV['GEMINI_TOTAL_TIMEOUT'] ?? 200),

    // Exponential backoff base (ms): delay = base * 2^attempt. Set 0 in tests.
    'retry_base_delay_ms' => (int) ($_ENV['GEMINI_RETRY_BASE_DELAY_MS'] ?? 500),
];
