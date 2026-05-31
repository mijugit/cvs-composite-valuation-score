<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use PHPUnit\Framework\TestCase;

/**
 * Verifies config/ai.php returns a well-formed config array (phase 1).
 *
 * Tests run fully offline — no API calls.
 */
class AiConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/ai.php';

        return $config;
    }

    public function test_returns_array_with_required_keys(): void
    {
        $config = $this->loadConfig();

        foreach (['api_key', 'base_url', 'model', 'anthropic_version', 'max_tokens', 'timeout', 'max_retries'] as $key) {
            $this->assertArrayHasKey($key, $config, "config/ai.php must define '$key'");
        }
    }

    public function test_numeric_settings_are_ints_and_sane_defaults(): void
    {
        $config = $this->loadConfig();

        $this->assertIsInt($config['max_tokens']);
        $this->assertIsInt($config['timeout']);
        $this->assertIsInt($config['max_retries']);
        $this->assertGreaterThan(0, $config['max_tokens']);
        $this->assertGreaterThanOrEqual(0, $config['max_retries']);
    }

    public function test_stable_defaults_present(): void
    {
        $config = $this->loadConfig();

        $this->assertSame('2023-06-01', $config['anthropic_version']);
        $this->assertStringContainsString('api.anthropic.com', (string) $config['base_url']);
    }
}
