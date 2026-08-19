<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Single construction point for GeminiClient.
 *
 * Mirrors ClaudeClientFactory — callers build the client from config/gemini.php
 * without re-wiring the transport. Pass a transport explicitly in tests
 * (FakeTransport); production defaults to CurlTransport.
 */
final class GeminiClientFactory
{
    /**
     * @param array<string, mixed> $config  config/gemini.php contents
     */
    public static function fromConfig(array $config, ?HttpTransport $transport = null): GeminiClient
    {
        return new GeminiClient($config, $transport ?? new CurlTransport());
    }
}
