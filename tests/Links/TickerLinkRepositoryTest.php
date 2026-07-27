<?php

declare(strict_types=1);

namespace CVS\Tests\Links;

use CVS\Links\TickerLinkRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class TickerLinkRepositoryTest extends TestCase
{
    private function makeRepo(): TickerLinkRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ticker_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL,
                label TEXT NOT NULL,
                url TEXT NOT NULL,
                created_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        return new TickerLinkRepository($pdo);
    }

    public function testCreateReturnsTheNewRow(): void
    {
        $repo = $this->makeRepo();
        $link = $repo->create('PKN.WA', 'TradingView', 'https://pl.tradingview.com/chart/A5nLjaVd/?symbol=GPW%3APKN', 1);

        $this->assertSame('TradingView', $link['label']);
        $this->assertSame('https://pl.tradingview.com/chart/A5nLjaVd/?symbol=GPW%3APKN', $link['url']);
        $this->assertGreaterThan(0, $link['id']);
    }

    public function testFindByTickerReturnsOnlyThatTickersLinksInInsertionOrder(): void
    {
        $repo = $this->makeRepo();
        $repo->create('PKN.WA', 'First', 'https://example.com/1', null);
        $repo->create('PKN.WA', 'Second', 'https://example.com/2', null);
        $repo->create('AAPL', 'Other ticker', 'https://example.com/3', null);

        $links = $repo->findByTicker('PKN.WA');
        $this->assertCount(2, $links);
        $this->assertSame(['First', 'Second'], array_column($links, 'label'));
    }

    public function testFindByTickerReturnsEmptyForUnknownTicker(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->findByTicker('NOPE'));
    }

    public function testCountByTicker(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame(0, $repo->countByTicker('PKN.WA'));

        $repo->create('PKN.WA', 'A', 'https://example.com/a', null);
        $repo->create('PKN.WA', 'B', 'https://example.com/b', null);

        $this->assertSame(2, $repo->countByTicker('PKN.WA'));
    }

    public function testDeleteRemovesTheRowAndReturnsTrue(): void
    {
        $repo = $this->makeRepo();
        $link = $repo->create('PKN.WA', 'A', 'https://example.com/a', null);

        $this->assertTrue($repo->delete($link['id']));
        $this->assertSame([], $repo->findByTicker('PKN.WA'));
    }

    public function testDeleteOfUnknownIdReturnsFalse(): void
    {
        $repo = $this->makeRepo();
        $this->assertFalse($repo->delete(999));
    }
}
