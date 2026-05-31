<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Typed taxonomy of Claude API call failures.
 *
 * Lets callers (and UI) distinguish failure modes — show a specific Polish
 * message and decide whether a retry makes sense — instead of a single opaque
 * "error". Mapped from HTTP status / transport error in ClaudeClient.
 */
enum AiFailureKind: string
{
    /** Request exceeded the per-attempt timeout. */
    case Timeout = 'timeout';

    /** HTTP 429 — rate limit hit. */
    case RateLimited = 'rate_limited';

    /** HTTP 529 — service temporarily overloaded. */
    case Overloaded = 'overloaded';

    /** HTTP 401/403 — invalid or missing API key / forbidden. */
    case Auth = 'auth';

    /** Billing / quota exhausted. */
    case Quota = 'quota';

    /** 2xx body that could not be parsed, or missing expected content. */
    case BadResponse = 'bad_response';

    /** Connection-level failure (DNS, TLS, no route) with no HTTP status. */
    case Network = 'network';
}
