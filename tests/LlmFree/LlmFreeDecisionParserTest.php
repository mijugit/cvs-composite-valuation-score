<?php

declare(strict_types=1);

namespace CVS\Tests\LlmFree;

use CVS\LlmFree\LlmFreeDecisionParser;
use PHPUnit\Framework\TestCase;

class LlmFreeDecisionParserTest extends TestCase
{
    private LlmFreeDecisionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LlmFreeDecisionParser();
    }

    // --- Valid inputs ---

    public function testValidResponseParsesDecisionsAndLegend(): void
    {
        $json = '{"decisions":[{"action":"BUY","ticker":"AAPL","quantity":10,"reason":"Strong CVS"}],"legend":"Kupuję AAPL, marże rosną."}';
        $result = $this->parser->parse($json);

        $this->assertCount(1, $result['decisions']);
        $this->assertSame('BUY', $result['decisions'][0]['action']);
        $this->assertSame('AAPL', $result['decisions'][0]['ticker']);
        $this->assertSame('Kupuję AAPL, marże rosną.', $result['legend']);
    }

    public function testValidNoActionWithLegend(): void
    {
        $json = '{"decisions":[{"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"Rynek niepewny"}],"legend":"Czekam na jaśniejszy sygnał."}';
        $result = $this->parser->parse($json);

        $this->assertSame('NO_ACTION', $result['decisions'][0]['action']);
        $this->assertSame('Czekam na jaśniejszy sygnał.', $result['legend']);
    }

    public function testTickerUppercasedAndActionNormalised(): void
    {
        $json = '{"decisions":[{"action":"buy","ticker":"aapl","quantity":5}],"legend":"ok"}';
        $result = $this->parser->parse($json);

        $this->assertSame('AAPL', $result['decisions'][0]['ticker']);
        $this->assertSame('BUY', $result['decisions'][0]['action']);
    }

    // --- legend validation ---

    public function testMissingLegendThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"decisions":[{"action":"HOLD","ticker":"AAPL","quantity":null}]}');
    }

    public function testEmptyLegendThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"decisions":[{"action":"HOLD","ticker":"AAPL","quantity":null}],"legend":"   "}');
    }

    public function testLegendTruncatedToConfiguredMaxLength(): void
    {
        $parser = new LlmFreeDecisionParser(50);
        $longLegend = str_repeat('x', 100);
        $json = '{"decisions":[{"action":"NO_ACTION","ticker":null,"quantity":null}],"legend":"' . $longLegend . '"}';

        $result = $parser->parse($json);

        $this->assertSame(50, mb_strlen($result['legend']));
    }

    // --- decisions validation ---

    public function testMissingDecisionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"legend":"ok"}');
    }

    public function testEmptyDecisionsArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"decisions":[],"legend":"ok"}');
    }

    public function testMalformedSingleDecisionIsSkippedNotFatal(): void
    {
        $json = '{"decisions":[
            {"action":"BUY","ticker":"MU","quantity":0,"reason":"too expensive"},
            {"action":"BUY","ticker":"AMD","quantity":2,"reason":"ok"}
        ],"legend":"Kupuję AMD, pomijam MU."}';

        $result = $this->parser->parse($json);

        $this->assertCount(1, $result['decisions']);
        $this->assertSame('AMD', $result['decisions'][0]['ticker']);
        $this->assertSame('Kupuję AMD, pomijam MU.', $result['legend']);
    }

    public function testAllDecisionsInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"decisions":[{"action":"BUY","ticker":"MU","quantity":0}],"legend":"ok"}');
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('{"decisions":[{"action":"MAYBE","ticker":"AAPL","quantity":10}],"legend":"ok"}');
    }

    public function testDuplicateTickerLastEntryWins(): void
    {
        $json = '{"decisions":[
            {"action":"BUY","ticker":"AAPL","quantity":10,"reason":"first"},
            {"action":"HOLD","ticker":"AAPL","quantity":null,"reason":"second"}
        ],"legend":"ok"}';

        $result = $this->parser->parse($json);

        $this->assertCount(1, $result['decisions']);
        $this->assertSame('HOLD', $result['decisions'][0]['action']);
        $this->assertSame('second', $result['decisions'][0]['reason']);
    }

    // --- structural failures ---

    public function testNonJsonStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('not json at all');
    }

    public function testTopLevelArrayInsteadOfObjectThrows(): void
    {
        // The old bare-array shape (sibling module) is no longer valid here.
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"action":"HOLD","ticker":"AAPL","quantity":null}]');
    }

    public function testExtractsJsonObjectFromFreeTextPreamble(): void
    {
        $raw = "Najpierw przeanalizuję dane...\n\n"
             . '{"decisions":[{"action":"HOLD","ticker":"AAPL","quantity":null,"reason":"OK"}],"legend":"Trzymam AAPL."}';

        $result = $this->parser->parse($raw);

        $this->assertCount(1, $result['decisions']);
        $this->assertSame('HOLD', $result['decisions'][0]['action']);
        $this->assertSame('Trzymam AAPL.', $result['legend']);
    }
}
