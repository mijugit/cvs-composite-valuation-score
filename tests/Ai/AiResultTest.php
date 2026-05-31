<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiResult;
use CVS\Ai\AiUsage;
use CVS\Ai\AiFailureKind;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AI typed-result value objects (phase 1).
 *
 * Tests run fully offline — no API calls.
 */
class AiResultTest extends TestCase
{
    public function test_success_carries_text_usage_and_metadata(): void
    {
        $usage  = new AiUsage(120, 340, 0, 0);
        $result = AiResult::success('analiza', $usage, 'end_turn', 'claude-test');

        $this->assertTrue($result->ok);
        $this->assertSame('analiza', $result->text);
        $this->assertSame($usage, $result->usage);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertSame('claude-test', $result->model);
        $this->assertNull($result->failureKind);
        $this->assertNull($result->failureMessage);
    }

    public function test_failure_carries_kind_and_message_and_nulls_success_fields(): void
    {
        $result = AiResult::failure(AiFailureKind::Overloaded, 'Model przeciążony');

        $this->assertFalse($result->ok);
        $this->assertSame(AiFailureKind::Overloaded, $result->failureKind);
        $this->assertSame('Model przeciążony', $result->failureMessage);
        $this->assertNull($result->text);
        $this->assertNull($result->usage);
        $this->assertNull($result->stopReason);
        $this->assertNull($result->model);
    }

    public function test_success_to_array_shape(): void
    {
        $result = AiResult::success('x', new AiUsage(1, 2, 3, 4), 'end_turn', 'm');
        $arr    = $result->toArray();

        $this->assertTrue($arr['ok']);
        $this->assertSame('x', $arr['text']);
        $this->assertSame(
            ['input_tokens' => 1, 'output_tokens' => 2, 'cache_creation_input_tokens' => 3, 'cache_read_input_tokens' => 4],
            $arr['usage']
        );
        $this->assertNull($arr['failure_kind']);
    }

    public function test_failure_to_array_uses_enum_value(): void
    {
        $arr = AiResult::failure(AiFailureKind::Timeout, 'Przekroczono czas')->toArray();

        $this->assertFalse($arr['ok']);
        $this->assertSame('timeout', $arr['failure_kind']);
        $this->assertSame('Przekroczono czas', $arr['failure_message']);
        $this->assertNull($arr['usage']);
    }

    public function test_usage_from_api_defaults_missing_counters_to_zero(): void
    {
        $usage = AiUsage::fromApi(['input_tokens' => 10, 'output_tokens' => 20]);

        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(0, $usage->cacheCreationInputTokens);
        $this->assertSame(0, $usage->cacheReadInputTokens);
    }
}
