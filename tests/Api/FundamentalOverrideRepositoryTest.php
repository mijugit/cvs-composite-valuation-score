<?php

declare(strict_types=1);

namespace CVS\Tests\Api;

use CVS\Api\FundamentalOverrideRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class FundamentalOverrideRepositoryTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE fundamental_overrides (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker       TEXT NOT NULL,
                field_name   TEXT NOT NULL,
                value        TEXT NULL,
                status       TEXT NOT NULL,
                source       TEXT NOT NULL DEFAULT \'gemini_validation\',
                validated_by INTEGER NULL,
                validated_at TEXT NOT NULL,
                UNIQUE (ticker, field_name)
            )
        ');
        return $pdo;
    }

    public function test_find_by_ticker_returns_empty_when_no_rows(): void
    {
        $repo = new FundamentalOverrideRepository($this->makePdo());
        $this->assertSame([], $repo->findByTicker('GIS'));
    }

    public function test_upsert_then_find_by_ticker_round_trips(): void
    {
        $repo = new FundamentalOverrideRepository($this->makePdo());
        $repo->upsert('gis', 'total_equity', '7380600000', 'validated', 'gemini_validation', 7);

        $rows = $repo->findByTicker('GIS');
        $this->assertArrayHasKey('total_equity', $rows);
        $this->assertSame('7380600000', $rows['total_equity']['value']);
        $this->assertSame('validated', $rows['total_equity']['status']);
    }

    public function test_upsert_twice_updates_in_place(): void
    {
        $repo = new FundamentalOverrideRepository($this->makePdo());
        $repo->upsert('gis', 'total_equity', '7380600000', 'validated', 'gemini_validation', 7);
        $repo->upsert('gis', 'total_equity', '7400000000', 'validated', 'gemini_validation', 7);

        $rows = $repo->findByTicker('GIS');
        $this->assertSame('7400000000', $rows['total_equity']['value']);
    }

    public function test_upsert_with_null_value_records_checked_no_data(): void
    {
        $repo = new FundamentalOverrideRepository($this->makePdo());
        $repo->upsert('gis', 'gross_profit', null, 'checked_no_data', 'gemini_validation', 7);

        $rows = $repo->findByTicker('GIS');
        $this->assertNull($rows['gross_profit']['value']);
        $this->assertSame('checked_no_data', $rows['gross_profit']['status']);
    }

    public function test_find_all_grouped_by_ticker_groups_correctly(): void
    {
        $repo = new FundamentalOverrideRepository($this->makePdo());
        $repo->upsert('gis', 'total_equity', '7380600000', 'validated');
        $repo->upsert('gis', 'gross_profit', null, 'checked_no_data');
        $repo->upsert('dnp.wa', 'free_cash_flow', '123', 'validated');

        $grouped = $repo->findAllGroupedByTicker();

        $this->assertArrayHasKey('GIS', $grouped);
        $this->assertArrayHasKey('DNP.WA', $grouped);
        $this->assertCount(2, $grouped['GIS']);
        $this->assertCount(1, $grouped['DNP.WA']);
        $this->assertSame('7380600000', $grouped['GIS']['total_equity']['value']);
        $this->assertSame('123', $grouped['DNP.WA']['free_cash_flow']['value']);
    }

    public function test_find_all_grouped_by_ticker_empty_table_returns_empty_array(): void
    {
        $repo = new FundamentalOverrideRepository($this->makePdo());
        $this->assertSame([], $repo->findAllGroupedByTicker());
    }
}
