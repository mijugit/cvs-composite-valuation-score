<?php

declare(strict_types=1);

/**
 * logo.dev API client configuration.
 *
 * Mirrors config/gemini.php's shape: returns a plain array, read from $_ENV.
 * Both keys are ALREADY on the server (change: ticker-logo-cache) under
 * mixed-case names — `CVS_Logo_Dev` / `CVS_Logo_Dev_Public`, not the
 * UPPER_SNAKE convention used elsewhere — because that is how the user
 * created them there. $_ENV keys are case-sensitive; the code reads them
 * as-is rather than requiring a server-side rename (same precedent as
 * `Gemini_CVS` in config/gemini.php).
 *
 * Consumed by CVS\Logo\LogoDevClient (constructed directly with this array,
 * mirroring ClaudeClient's constructor — no factory needed at this scale).
 */
return [
    // Secret — Search API (Bearer auth). Only ever sent as the Authorization
    // header; never logged.
    'search_api_key' => (string) ($_ENV['CVS_Logo_Dev'] ?? ''),

    // Publishable — image endpoint (query-string token, safe to be non-secret
    // by logo.dev's own design, but kept server-side here since the app never
    // exposes a live img.logo.dev URL to the browser).
    'img_api_key' => (string) ($_ENV['CVS_Logo_Dev_Public'] ?? ''),

    // Search API base (company name -> best-match domain).
    'search_base_url' => (string) ($_ENV['LOGO_DEV_SEARCH_BASE_URL'] ?? 'https://api.logo.dev'),

    // Image API base (domain -> logo bytes).
    'img_base_url' => (string) ($_ENV['LOGO_DEV_IMG_BASE_URL'] ?? 'https://img.logo.dev'),

    // Fetched-image format/size/density — one canonical asset per ticker,
    // scaled down via CSS wherever a smaller box is needed (no multi-size
    // variants, decision: ticker-logo-cache plan).
    'image_format' => (string) ($_ENV['LOGO_DEV_IMAGE_FORMAT'] ?? 'webp'),
    'image_size'   => (int) ($_ENV['LOGO_DEV_IMAGE_SIZE'] ?? 128),
    'retina'       => filter_var($_ENV['LOGO_DEV_RETINA'] ?? true, FILTER_VALIDATE_BOOLEAN),

    // Per-attempt timeout (seconds). Only ever called from bin/fetch_logos.php
    // (a cron entrypoint), so no request-lifecycle time pressure.
    'timeout' => (int) ($_ENV['LOGO_DEV_TIMEOUT'] ?? 20),

    // Retries on transient failures (429 / 5xx / timeout / network), budget-capped.
    'max_retries' => (int) ($_ENV['LOGO_DEV_MAX_RETRIES'] ?? 2),

    // Total wall-clock budget (seconds) across attempts+backoff, per single
    // searchDomain()/fetchImageBytes() call.
    'total_timeout' => (int) ($_ENV['LOGO_DEV_TOTAL_TIMEOUT'] ?? 25),

    // Exponential backoff base (ms): delay = base * 2^attempt. Set 0 in tests.
    'retry_base_delay_ms' => (int) ($_ENV['LOGO_DEV_RETRY_BASE_DELAY_MS'] ?? 500),
];
