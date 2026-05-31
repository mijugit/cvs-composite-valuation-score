<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Token usage reported by the Claude Messages API `usage` block.
 *
 * Exposed on a successful AiResult so a later cost-tracking layer (FR-004,
 * per-user API usage) can read it without changing the client. The client
 * never persists usage — it only surfaces it.
 */
readonly class AiUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheCreationInputTokens,
        public int $cacheReadInputTokens,
    ) {}

    /**
     * Build from the API `usage` object, defaulting absent counters to 0.
     *
     * @param array<string, mixed> $usage
     */
    public static function fromApi(array $usage): self
    {
        return new self(
            inputTokens:              (int) ($usage['input_tokens'] ?? 0),
            outputTokens:             (int) ($usage['output_tokens'] ?? 0),
            cacheCreationInputTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheReadInputTokens:     (int) ($usage['cache_read_input_tokens'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_tokens'                => $this->inputTokens,
            'output_tokens'               => $this->outputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
            'cache_read_input_tokens'     => $this->cacheReadInputTokens,
        ];
    }
}
