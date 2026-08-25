<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\CriticalReviewPrompt;
use PHPUnit\Framework\TestCase;

/**
 * Both AiCriticalReviewService (Claude) and GeminiCriticalReviewService
 * (Gemini) depend on this single shared builder — these tests guard against
 * prompt content silently drifting between the two providers, since there is
 * no per-provider prompt test to duplicate (change: critical-review-models).
 */
class CriticalReviewPromptTest extends TestCase
{
    public function test_system_prompt_contains_all_four_narrative_sections(): void
    {
        $system = CriticalReviewPrompt::buildSystemPrompt();

        $this->assertStringContainsString('## 1. Świeże katalizatory', $system->text);
        $this->assertStringContainsString('## 2. Czego model nie widzi', $system->text);
        $this->assertStringContainsString('## 3. Krytyka naszej analizy', $system->text);
        $this->assertStringContainsString('## 4. Dwa scenariusze', $system->text);
    }

    public function test_system_prompt_contains_guardrails(): void
    {
        $system = CriticalReviewPrompt::buildSystemPrompt();

        $this->assertStringContainsString('ANCHOR RULE', $system->text);
        $this->assertStringContainsString('NUMBER FIDELITY', $system->text);
        $this->assertStringContainsString('NO NEWS IS ALSO INFORMATION', $system->text);
        $this->assertStringContainsString('DATE DISCIPLINE', $system->text);
        $this->assertStringContainsString('NO META-COMMENTARY', $system->text);
    }

    public function test_system_prompt_mandates_trailing_probability_json_block(): void
    {
        $system = CriticalReviewPrompt::buildSystemPrompt();

        $this->assertStringContainsString('PROBABILITY BLOCK', $system->text);
        $this->assertStringContainsString('bull_probability', $system->text);
        $this->assertStringContainsString('bear_probability', $system->text);
        $this->assertStringContainsString('rationale', $system->text);
        $this->assertStringContainsString('```json', $system->text);
    }

    public function test_system_prompt_mandates_sources_field_in_the_same_json_block(): void
    {
        $system = CriticalReviewPrompt::buildSystemPrompt();

        $this->assertStringContainsString('SOURCES FIELD', $system->text);
        $this->assertStringContainsString('"sources"', $system->text);
        $this->assertStringContainsString('invent or guess', $system->text);
    }

    public function test_system_prompt_ends_narrative_with_fixed_disclaimer(): void
    {
        $system = CriticalReviewPrompt::buildSystemPrompt();

        $this->assertStringContainsString(
            'Powyższa analiza to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.',
            $system->text
        );
    }

    public function test_user_message_includes_todays_date_ticker_and_stage1_analysis(): void
    {
        $message = CriticalReviewPrompt::buildUserMessage('MU', 'DATA BLOCK CONTENT', 'Stage-1 unikalny fragment XYZ.');

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->assertStringContainsString("TODAY'S DATE: {$today}", $message);
        $this->assertStringContainsString('COMPANY UNDER REVIEW: MU', $message);
        $this->assertStringContainsString('DATA BLOCK CONTENT', $message);
        $this->assertStringContainsString('Stage-1 unikalny fragment XYZ.', $message);
    }
}
