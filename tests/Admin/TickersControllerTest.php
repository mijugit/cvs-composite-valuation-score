<?php

declare(strict_types=1);

namespace CVS\Tests\Admin;

use CVS\Admin\TickersController;
use PHPUnit\Framework\TestCase;

class TickersControllerTest extends TestCase
{
    public function test_extract_symbol_from_yahoo_url(): void
    {
        $this->assertSame('SPCX', TickersController::extractSymbol('https://finance.yahoo.com/quote/SPCX/'));
        $this->assertSame('PKN.WA', TickersController::extractSymbol('https://finance.yahoo.com/quote/PKN.WA/'));
        $this->assertSame('ALE.WA', TickersController::extractSymbol('https://finance.yahoo.com/quote/ALE.WA?p=ALE.WA'));
    }

    public function test_extract_symbol_from_bare_ticker(): void
    {
        $this->assertSame('AAPL', TickersController::extractSymbol('aapl'));
        $this->assertSame('PKN.WA', TickersController::extractSymbol('pkn.wa'));
    }

    public function test_extract_symbol_rejects_garbage(): void
    {
        $this->assertNull(TickersController::extractSymbol(''));
        $this->assertNull(TickersController::extractSymbol('https://example.com/foo'));
        $this->assertNull(TickersController::extractSymbol('not a ticker at all'));
    }

    public function test_append_ticker_keeps_list_sorted(): void
    {
        $tickers = [
            ['symbol' => 'AAPL', 'name' => 'Apple Inc.'],
            ['symbol' => 'MSFT', 'name' => 'Microsoft Corp.'],
        ];

        $result = TickersController::appendTicker($tickers, 'ABBV', 'AbbVie Inc.');

        $this->assertSame(['AAPL', 'ABBV', 'MSFT'], array_column($result, 'symbol'));
    }

    public function test_append_ticker_with_polish_company(): void
    {
        $result = TickersController::appendTicker([], 'PKN.WA', 'Polski Koncern Naftowy ORLEN');

        $this->assertSame([['symbol' => 'PKN.WA', 'name' => 'Polski Koncern Naftowy ORLEN']], $result);
    }

    public function test_format_added_flash_names_the_resolved_market(): void
    {
        $this->assertSame(
            'Dodano PKN.WA (PKN Orlen) do listy — rynek: GPW (Warszawa).',
            TickersController::formatAddedFlash('PKN.WA', 'PKN Orlen', 'GPW (Warszawa)')
        );
    }

    public function test_format_added_flash_for_a_plain_us_ticker(): void
    {
        $this->assertSame(
            'Dodano AAPL (Apple Inc.) do listy — rynek: USA (NYSE/NASDAQ).',
            TickersController::formatAddedFlash('AAPL', 'Apple Inc.', 'USA (NYSE/NASDAQ)')
        );
    }
}
