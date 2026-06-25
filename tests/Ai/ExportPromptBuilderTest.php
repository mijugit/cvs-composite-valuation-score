<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\ExportPromptBuilder;
use PHPUnit\Framework\TestCase;

class ExportPromptBuilderTest extends TestCase
{
    private ExportPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ExportPromptBuilder();
    }

    private function dataBlock(): string
    {
        return "COMPANY: AAPL\nSECTOR: Technology\nCVS MODEL SCORES:\n- Swing: 74.5/100\n- Fundamental: 62.0/100";
    }

    private function aiAnalysis(): string
    {
        return "## 1. Ocena modelu CVS\nWyniki są solidne.\n\n## 2. Opinia rynku\nAnalitycy pozytywni.";
    }

    // ------------------------------------------------------------------
    // (a) Output contains ticker, data block, and AI analysis
    // ------------------------------------------------------------------

    public function test_pl_output_contains_ticker(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString('AAPL', $result);
    }

    public function test_pl_output_contains_data_block(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString($this->dataBlock(), $result);
    }

    public function test_pl_output_contains_ai_analysis(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString($this->aiAnalysis(), $result);
    }

    // ------------------------------------------------------------------
    // (b) Output contains anchor rule and disclaimer
    // ------------------------------------------------------------------

    public function test_pl_output_contains_anchor_rule(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString('KOTWICA', $result);
        $this->assertStringContainsString('NIE zmieniaj', $result);
    }

    public function test_pl_output_contains_disclaimer(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString('nie rekomendacja inwestycyjna', $result);
        $this->assertStringContainsString('Inwestuj świadomie', $result);
    }

    public function test_en_output_contains_anchor_rule(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'en');
        $this->assertStringContainsString('ANCHOR', $result);
        $this->assertStringContainsString('Do NOT change', $result);
    }

    public function test_en_output_contains_disclaimer(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'en');
        $this->assertStringContainsString('not an investment recommendation', $result);
        $this->assertStringContainsString('Invest responsibly', $result);
    }

    // ------------------------------------------------------------------
    // (c) PL vs EN differ in instructions
    // ------------------------------------------------------------------

    public function test_pl_and_en_variants_differ(): void
    {
        $pl = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $en = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'en');
        $this->assertNotSame($pl, $en);
    }

    public function test_pl_variant_has_polish_instructions(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString('TWOJE ZADANIA', $result);
        $this->assertStringContainsString('ŚWIEŻE KATALIZATORY', $result);
        $this->assertStringContainsString('KRYTYKA', $result);
    }

    public function test_en_variant_has_english_instructions(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'en');
        $this->assertStringContainsString('YOUR TASKS', $result);
        $this->assertStringContainsString('FRESH CATALYSTS', $result);
        $this->assertStringContainsString('CRITIQUE', $result);
    }

    // ------------------------------------------------------------------
    // (d) NEGATIVE: output must NOT contain callback/http/base64/POST
    // ------------------------------------------------------------------

    public function test_pl_output_has_no_callback_or_http(): void
    {
        $result = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringNotContainsStringIgnoringCase('callback', $result);
        $this->assertStringNotContainsStringIgnoringCase('base64', $result);
        $lower = strtolower($result);
        // 'http' must not appear as a URL/link instruction; dataBlock and analysis are caller-controlled,
        // but the builder's own static template must not inject any URL.
        // We check that the builder template itself is clean by using a fresh empty dataBlock/analysis.
        $clean = $this->builder->build('AAPL', 'Technology', '', '', 'pl');
        $this->assertStringNotContainsStringIgnoringCase('http', $clean);
        $this->assertStringNotContainsStringIgnoringCase('callback', $clean);
        $this->assertStringNotContainsStringIgnoringCase('base64', $clean);
        $this->assertDoesNotMatchRegularExpression('/\bPOST\b/', $clean);
    }

    public function test_en_output_template_has_no_callback_or_http(): void
    {
        $clean = $this->builder->build('AAPL', 'Technology', '', '', 'en');
        $this->assertStringNotContainsStringIgnoringCase('http', $clean);
        $this->assertStringNotContainsStringIgnoringCase('callback', $clean);
        $this->assertStringNotContainsStringIgnoringCase('base64', $clean);
        $this->assertDoesNotMatchRegularExpression('/\bPOST\b/', $clean);
    }

    // ------------------------------------------------------------------
    // (e) Unknown lang falls back to PL
    // ------------------------------------------------------------------

    public function test_unknown_lang_falls_back_to_pl(): void
    {
        $unknown = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'de');
        $pl      = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertSame($pl, $unknown);
    }

    public function test_empty_lang_falls_back_to_pl(): void
    {
        $empty = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), '');
        $pl    = $this->builder->build('AAPL', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertSame($pl, $empty);
    }

    // ------------------------------------------------------------------
    // Sector appears in both variants
    // ------------------------------------------------------------------

    public function test_sector_appears_in_pl_output(): void
    {
        $result = $this->builder->build('MSFT', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'pl');
        $this->assertStringContainsString('Technology', $result);
    }

    public function test_sector_appears_in_en_output(): void
    {
        $result = $this->builder->build('MSFT', 'Technology', $this->dataBlock(), $this->aiAnalysis(), 'en');
        $this->assertStringContainsString('Technology', $result);
    }
}
