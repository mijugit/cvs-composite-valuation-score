<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Single construction point for ClaudeClient.
 *
 * Callers (S-01, future alerts, …) build the client from config/ai.php without
 * re-wiring the transport. Pass a transport explicitly in tests (FakeTransport);
 * production defaults to CurlTransport.
 */
final class ClaudeClientFactory
{
    /**
     * @param array<string, mixed> $config  config/ai.php contents
     */
    public static function fromConfig(array $config, ?HttpTransport $transport = null): ClaudeClient
    {
        return new ClaudeClient($config, $transport ?? new CurlTransport());
    }
}
