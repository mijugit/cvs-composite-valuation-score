<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\CriticalReviewProbabilityParser;
use PHPUnit\Framework\TestCase;

class CriticalReviewProbabilityParserTest extends TestCase
{
    public function test_parses_well_formed_fenced_json_block(): void
    {
        $raw = <<<TEXT
## 1. Świeże katalizatory
Treść narracji.

⚠️ Powyższa analiza to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.

```json
{"bull_probability": 62, "bear_probability": 38, "rationale": "Silny momentum i rosnące marże."}
```
TEXT;

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame(62, $result['bull_probability']);
        $this->assertSame(38, $result['bear_probability']);
        $this->assertSame('Silny momentum i rosnące marże.', $result['rationale']);
        $this->assertStringContainsString('## 1. Świeże katalizatory', $result['narrative']);
        $this->assertStringNotContainsString('bull_probability', $result['narrative']);
    }

    public function test_parses_bare_trailing_json_without_fence(): void
    {
        $raw = "Narracja bez fence'a.\n\n"
            . '{"bull_probability": 55, "bear_probability": 45, "rationale": "Neutralnie."}';

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame(55, $result['bull_probability']);
        $this->assertSame(45, $result['bear_probability']);
        $this->assertSame('Neutralnie.', $result['rationale']);
        $this->assertStringContainsString("Narracja bez fence'a.", $result['narrative']);
    }

    public function test_missing_block_degrades_to_whole_text_as_narrative(): void
    {
        $raw = 'Tylko narracja, model zapomniał o bloku JSON.';

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame($raw, $result['narrative']);
        $this->assertNull($result['bull_probability']);
        $this->assertNull($result['bear_probability']);
        $this->assertNull($result['rationale']);
    }

    public function test_malformed_json_degrades_gracefully(): void
    {
        $raw = <<<TEXT
Narracja poprawna.

```json
{"bull_probability": 62, "bear_probability": 38, "rationale": "urwane...
```
TEXT;

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame($raw, $result['narrative']);
        $this->assertNull($result['bull_probability']);
        $this->assertNull($result['bear_probability']);
        $this->assertNull($result['rationale']);
    }

    public function test_out_of_range_percentages_are_clamped(): void
    {
        $raw = 'Narracja.' . "\n\n"
            . '```json' . "\n"
            . '{"bull_probability": 140, "bear_probability": -20, "rationale": "Ekstremalne wartości."}' . "\n"
            . '```';

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame(100, $result['bull_probability']);
        $this->assertSame(0, $result['bear_probability']);
    }

    public function test_bull_and_bear_are_independent_and_need_not_sum_to_100(): void
    {
        $raw = 'Narracja.' . "\n\n"
            . '```json' . "\n"
            . '{"bull_probability": 70, "bear_probability": 60, "rationale": "Niezależne szacunki."}' . "\n"
            . '```';

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame(70, $result['bull_probability']);
        $this->assertSame(60, $result['bear_probability']);
    }

    public function test_empty_rationale_string_is_treated_as_null(): void
    {
        $raw = 'Narracja.' . "\n\n"
            . '```json' . "\n"
            . '{"bull_probability": 50, "bear_probability": 50, "rationale": ""}' . "\n"
            . '```';

        $result = CriticalReviewProbabilityParser::parse($raw);

        $this->assertSame(50, $result['bull_probability']);
        $this->assertNull($result['rationale']);
    }
}
