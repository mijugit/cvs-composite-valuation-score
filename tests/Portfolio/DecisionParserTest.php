<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\DecisionParser;
use PHPUnit\Framework\TestCase;

class DecisionParserTest extends TestCase
{
    private DecisionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DecisionParser();
    }

    // --- Valid inputs ---

    public function testValidBuyReturnsDecisionArray(): void
    {
        $json = '[{"action":"BUY","ticker":"AAPL","quantity":10,"price_usd":150.0,"reason":"Strong CVS"}]';
        $result = $this->parser->parse($json);

        $this->assertCount(1, $result);
        $this->assertSame('BUY', $result[0]['action']);
        $this->assertSame('AAPL', $result[0]['ticker']);
        $this->assertSame(10, $result[0]['quantity']);
        $this->assertSame('Strong CVS', $result[0]['reason']);
    }

    public function testValidSellReturnsDecisionArray(): void
    {
        $json = '[{"action":"SELL","ticker":"MSFT","quantity":5,"reason":"Overvalued"}]';
        $result = $this->parser->parse($json);

        $this->assertSame('SELL', $result[0]['action']);
        $this->assertSame('MSFT', $result[0]['ticker']);
        $this->assertSame(5, $result[0]['quantity']);
    }

    public function testValidHoldAcceptsNullQuantity(): void
    {
        $json = '[{"action":"HOLD","ticker":"GOOG","quantity":null,"reason":"Watching"}]';
        $result = $this->parser->parse($json);

        $this->assertSame('HOLD', $result[0]['action']);
        $this->assertNull($result[0]['quantity']);
    }

    public function testValidNoActionSingleElement(): void
    {
        $json = '[{"action":"NO_ACTION","ticker":null,"quantity":null,"reason":"Market looks overvalued"}]';
        $result = $this->parser->parse($json);

        $this->assertCount(1, $result);
        $this->assertSame('NO_ACTION', $result[0]['action']);
        $this->assertNull($result[0]['ticker']);
    }

    public function testTickerUppercased(): void
    {
        $json = '[{"action":"BUY","ticker":"aapl","quantity":10}]';
        $result = $this->parser->parse($json);
        $this->assertSame('AAPL', $result[0]['ticker']);
    }

    public function testActionNormalisedToUppercase(): void
    {
        $json = '[{"action":"buy","ticker":"AAPL","quantity":5}]';
        $result = $this->parser->parse($json);
        $this->assertSame('BUY', $result[0]['action']);
    }

    // --- Error cases ---

    public function testEmptyArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[]');
    }

    public function testNonJsonStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('not json at all');
    }

    public function testMissingActionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"ticker":"AAPL","quantity":10}]');
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"action":"MAYBE","ticker":"AAPL","quantity":10}]');
    }

    public function testBuyWithNullQuantityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"action":"BUY","ticker":"AAPL","quantity":null}]');
    }

    public function testBuyWithZeroQuantityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"action":"BUY","ticker":"AAPL","quantity":0}]');
    }

    public function testHoldWithNonNullQuantityIsNormalisedToNull(): void
    {
        // A stray quantity on HOLD must not fail the cycle — it is silently dropped.
        $result = $this->parser->parse('[{"action":"HOLD","ticker":"AAPL","quantity":5}]');

        $this->assertSame('HOLD', $result[0]['action']);
        $this->assertNull($result[0]['quantity']);
    }

    public function testBuyWithMissingTickerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"action":"BUY","ticker":null,"quantity":10}]');
    }

    public function testInvalidItemInBatchIsSkippedKeepingValidOnes(): void
    {
        // One BUY with quantity 0 (price > budget) must not discard the whole batch.
        $json = '[
            {"action":"BUY","ticker":"MU","quantity":0,"reason":"too expensive"},
            {"action":"BUY","ticker":"AMD","quantity":2,"reason":"ok"},
            {"action":"BUY","ticker":"ABNB","quantity":6,"reason":"ok"}
        ]';
        $result = $this->parser->parse($json);

        $this->assertCount(2, $result);
        $this->assertSame('AMD', $result[0]['ticker']);
        $this->assertSame('ABNB', $result[1]['ticker']);
    }

    public function testAllItemsInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse('[{"action":"BUY","ticker":"MU","quantity":0},{"action":"SELL","ticker":"X","quantity":0}]');
    }

    // --- Normalisation ---

    public function testDuplicateTickerLastEntryWins(): void
    {
        $json = '[
            {"action":"BUY","ticker":"AAPL","quantity":10,"reason":"first"},
            {"action":"HOLD","ticker":"AAPL","quantity":null,"reason":"second"}
        ]';
        $result = $this->parser->parse($json);

        $this->assertCount(1, $result);
        $this->assertSame('HOLD', $result[0]['action']);
        $this->assertSame('second', $result[0]['reason']);
    }

    public function testReasonTruncatedTo500Chars(): void
    {
        $longReason = str_repeat('x', 600);
        $json = '[{"action":"BUY","ticker":"AAPL","quantity":10,"reason":"' . $longReason . '"}]';
        $result = $this->parser->parse($json);

        $this->assertSame(500, mb_strlen($result[0]['reason'] ?? ''));
    }

    public function testMissingReasonBecomesNull(): void
    {
        $json = '[{"action":"BUY","ticker":"AAPL","quantity":5}]';
        $result = $this->parser->parse($json);

        $this->assertNull($result[0]['reason']);
    }

    public function testMultipleDecisionsPreservedInOrder(): void
    {
        $json = '[
            {"action":"BUY","ticker":"AAPL","quantity":10},
            {"action":"SELL","ticker":"MSFT","quantity":5},
            {"action":"HOLD","ticker":"GOOG","quantity":null}
        ]';
        $result = $this->parser->parse($json);

        $this->assertCount(3, $result);
        $this->assertSame('AAPL', $result[0]['ticker']);
        $this->assertSame('MSFT', $result[1]['ticker']);
        $this->assertSame('GOOG', $result[2]['ticker']);
    }

    // --- Free-text preamble extraction ---

    public function testExtractsJsonFromFreeTextPreamble(): void
    {
        $raw = "Najpierw przeanalizuję stan portfela...\n\n**SELL MU** bo stop-loss.\n\n"
             . '[{"action":"SELL","ticker":"MU","quantity":1,"reason":"Stop-loss"},'
             . '{"action":"HOLD","ticker":"AAPL","quantity":null,"reason":"OK"}]';

        $result = $this->parser->parse($raw);

        $this->assertCount(2, $result);
        $this->assertSame('SELL', $result[0]['action']);
        $this->assertSame('MU', $result[0]['ticker']);
        $this->assertSame('HOLD', $result[1]['action']);
    }

    public function testRecoversTruncatedJsonArray(): void
    {
        $raw = '[{"action":"SELL","ticker":"MU","quantity":1,"reason":"Stop-loss"},'
             . '{"action":"HOLD","ticker":"AAPL","quantity":null,"reason":"OK"},'
             . '{"action":"HOLD","ticker":"GOOG","quantity":null,"reason":"Swing 65.20, waga 9.2';

        $result = $this->parser->parse($raw);

        $this->assertCount(2, $result);
        $this->assertSame('MU', $result[0]['ticker']);
        $this->assertSame('AAPL', $result[1]['ticker']);
    }

    public function testPreamblePlusTruncationCombined(): void
    {
        $raw = "Analiza sektorowa:\n- Tech: 40%\n\n"
             . '[{"action":"BUY","ticker":"DELL","quantity":2,"reason":"Strong"},'
             . '{"action":"HOLD","ticker":"NOW","quantity":null,"reason":"Swing 70.30, tru';

        $result = $this->parser->parse($raw);

        $this->assertCount(1, $result);
        $this->assertSame('BUY', $result[0]['action']);
        $this->assertSame('DELL', $result[0]['ticker']);
    }
}
