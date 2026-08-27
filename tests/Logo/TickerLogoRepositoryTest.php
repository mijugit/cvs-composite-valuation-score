<?php

declare(strict_types=1);

namespace CVS\Tests\Logo;

use CVS\Logo\TickerLogoRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class TickerLogoRepositoryTest extends TestCase
{
    private function makeRepo(): TickerLogoRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE ticker_logos (
                ticker TEXT PRIMARY KEY,
                domain TEXT NULL,
                logo_path TEXT NULL,
                status TEXT NOT NULL,
                fetched_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');
        return new TickerLogoRepository($pdo);
    }

    public function testUpsertInsertsANewRow(): void
    {
        $repo = $this->makeRepo();
        $repo->upsert('AAPL', 'apple.com', '/images/logos/AAPL.webp', 'found');

        $row = $repo->findByTicker('AAPL');
        $this->assertNotNull($row);
        $this->assertSame('apple.com', $row['domain']);
        $this->assertSame('/images/logos/AAPL.webp', $row['logo_path']);
        $this->assertSame('found', $row['status']);
    }

    public function testUpsertUpdatesAnExistingRowInPlace(): void
    {
        $repo = $this->makeRepo();
        $repo->upsert('AAPL', null, null, 'not_found');
        $repo->upsert('AAPL', 'apple.com', '/images/logos/AAPL.webp', 'found');

        $row = $repo->findByTicker('AAPL');
        $this->assertSame('found', $row['status']);
        $this->assertSame('apple.com', $row['domain']);
    }

    public function testUpsertStoresNotFoundWithNullDomainAndPath(): void
    {
        $repo = $this->makeRepo();
        $repo->upsert('PKN.WA', null, null, 'not_found');

        $row = $repo->findByTicker('PKN.WA');
        $this->assertSame('not_found', $row['status']);
        $this->assertNull($row['domain']);
        $this->assertNull($row['logo_path']);
    }

    public function testFindByTickerReturnsNullForUnknownTicker(): void
    {
        $repo = $this->makeRepo();
        $this->assertNull($repo->findByTicker('NOPE'));
    }

    public function testFindByTickersReturnsOnlyRequestedTickersMappedByKey(): void
    {
        $repo = $this->makeRepo();
        $repo->upsert('AAPL', 'apple.com', '/images/logos/AAPL.webp', 'found');
        $repo->upsert('MSFT', 'microsoft.com', '/images/logos/MSFT.webp', 'found');
        $repo->upsert('PKN.WA', null, null, 'not_found');

        $map = $repo->findByTickers(['AAPL', 'PKN.WA']);

        $this->assertCount(2, $map);
        $this->assertSame('/images/logos/AAPL.webp', $map['AAPL']['logo_path']);
        $this->assertSame('not_found', $map['PKN.WA']['status']);
        $this->assertArrayNotHasKey('MSFT', $map);
    }

    public function testFindByTickersReturnsEmptyArrayForEmptyInput(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->findByTickers([]));
    }

    public function testExistingTickersListsEveryProcessedTickerRegardlessOfStatus(): void
    {
        $repo = $this->makeRepo();
        $repo->upsert('AAPL', 'apple.com', '/images/logos/AAPL.webp', 'found');
        $repo->upsert('PKN.WA', null, null, 'not_found');

        $existing = $repo->existingTickers();
        sort($existing);
        $this->assertSame(['AAPL', 'PKN.WA'], $existing);
    }

    public function testExistingTickersReturnsEmptyArrayWhenTableIsEmpty(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->existingTickers());
    }
}
