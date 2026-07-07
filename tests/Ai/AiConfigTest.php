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

    /**
     * change: cvs-ai-critical-review — the background worker needs a much longer
     * budget than the synchronous stage-1 flow (measured 138.8s live vs. ~20-25s
     * defaults above). A production run without this override failed with a
     * transport timeout; this locks the override's shape in place.
     */
    public function test_critical_review_timeout_override_present_and_larger_than_sync_defaults(): void
    {
        $config = $this->loadConfig();

        $this->assertArrayHasKey('critical_review', $config);
        $review = $config['critical_review'];

        foreach (['timeout', 'total_timeout', 'max_retries'] as $key) {
            $this->assertArrayHasKey($key, $review, "critical_review must define '$key'");
            $this->assertIsInt($review[$key]);
        }

        $this->assertGreaterThan($config['timeout'], $review['timeout']);
        $this->assertGreaterThan($config['total_timeout'], $review['total_timeout']);
        $this->assertSame(0, $review['max_retries']);
    }
}
