<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Single construction point for GPTClient — change: critical-review-openai.
 *
 * Mirrors ClaudeClientFactory/GeminiClientFactory, extended with a $flavor
 * argument: Terra and Luna are the identical Responses API shape, differing
 * only in model ID and API key (see config/gpt.php), so GPTClient itself
 * stays flavor-agnostic — this factory flattens the chosen flavor's
 * ('api_key', 'model') into the shared settings before constructing it.
 */
final class GPTClientFactory
{
    /**
     * @param array<string, mixed> $config  config/gpt.php contents
     * @param string                $flavor  'terra' | 'luna'
     */
    public static function fromConfig(array $config, string $flavor, ?HttpTransport $transport = null): GPTClient
    {
        $flavorConfig = is_array($config[$flavor] ?? null) ? $config[$flavor] : [];

        $flat = array_merge($config, $flavorConfig);
        unset($flat['terra'], $flat['luna']);

        return new GPTClient($flat, $transport ?? new CurlTransport());
    }
}
