<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * A system prompt the caller wants prompt-cached.
 *
 * Carrying it as a value object (rather than a bare string) lets ClaudeClient
 * attach a `cache_control` breakpoint and pick the TTL. 5m is GA; 1h requires
 * the `extended-cache-ttl-2025-04-11` beta header (set by the client).
 *
 * Cost control (CLAUDE.md): a stable cached system prompt cuts repeat-call cost.
 */
readonly class CacheableSystem
{
    public const TTL_5M = '5m';
    public const TTL_1H = '1h';

    public function __construct(
        public string $text,
        public string $ttl = self::TTL_5M,
    ) {}
}
