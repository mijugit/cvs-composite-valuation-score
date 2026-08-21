<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\FundamentalsValidationRunRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class FundamentalsValidationRunRepositoryTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE fundamental_validation_runs (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker           TEXT    NOT NULL UNIQUE,
                status           TEXT    NOT NULL DEFAULT \'pending\',
                mode             TEXT    NOT NULL,
                requested_fields TEXT    NULL,
                diff             TEXT    NULL,
                notes            TEXT    NULL,
                error_message    TEXT    NULL,
                model            TEXT    NULL,
                requested_by     INTEGER NULL,
                requested_at     TEXT    NOT NULL,
                completed_at     TEXT    NULL
            )
        ');
        return $pdo;
    }

    public function test_find_by_ticker_returns_null_when_no_row_exists(): void
    {
        $repo = new FundamentalsValidationRunRepository($this->makePdo());
        $this->assertNull($repo->findByTicker('GIS'));
    }

    public function test_mark_pending_then_find_by_ticker_returns_pending_row(): void
    {
        $repo = new FundamentalsValidationRunRepository($this->makePdo());
        $repo->markPending('gis', 'missing', ['free_cash_flow', 'days_since_earnings'], 7);

        $row = $repo->findByTicker('GIS');
        $this->assertNotNull($row);
        $this->assertSame('GIS', $row['ticker']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('missing', $row['mode']);
        $this->assertSame(['free_cash_flow', 'days_since_earnings'], json_decode((string) $row['requested_fields'], true));
    }

    public function test_is_pending_true_only_while_status_is_pending(): void
    {
        $pdo  = $this->makePdo();
        $repo = new FundamentalsValidationRunRepository($pdo);

        $this->assertFalse($repo->isPending('GIS'));

        $repo->markPending('gis', 'missing', ['free_cash_flow'], 7);
        $this->assertTrue($repo->isPending('GIS'));

        $repo->markCompleted('gis', ['free_cash_flow' => ['old' => 1, 'new' => 2, 'status' => 'validated']], 'ok', 'gemini-test');
        $this->assertFalse($repo->isPending('GIS'));
    }

    public function test_mark_pending_twice_updates_in_place_without_duplicate_error(): void
    {
        $repo = new FundamentalsValidationRunRepository($this->makePdo());
        $repo->markPending('gis', 'missing', ['a'], 7);
        $repo->markPending('gis', 'all', ['a', 'b'], 9);

        $row = $repo->findByTicker('GIS');
        $this->assertSame('all', $row['mode']);
        $this->assertSame('9', (string) $row['requested_by']);
    }

    public function test_mark_completed_stores_diff_notes_and_model(): void
    {
        $repo = new FundamentalsValidationRunRepository($this->makePdo());
        $repo->markPending('gis', 'missing', ['free_cash_flow'], 7);

        $diff = ['free_cash_flow' => ['old' => 2_300_000_000, 'new' => 1_600_000_000, 'status' => 'validated']];
        $repo->markCompleted('gis', $diff, 'FCF różni się od Yahoo.', 'gemini-3.7-flash');

        $row = $repo->findByTicker('GIS');
        $this->assertSame('completed', $row['status']);
        $this->assertSame($diff, json_decode((string) $row['diff'], true));
        $this->assertSame('FCF różni się od Yahoo.', $row['notes']);
        $this->assertSame('gemini-3.7-flash', $row['model']);
        $this->assertNull($row['error_message']);
    }

    public function test_mark_failed_stores_error_message(): void
    {
        $repo = new FundamentalsValidationRunRepository($this->makePdo());
        $repo->markPending('gis', 'missing', ['free_cash_flow'], 7);
        $repo->markFailed('gis', 'Przekroczono czas oczekiwania.');

        $row = $repo->findByTicker('GIS');
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Przekroczono czas oczekiwania.', $row['error_message']);
    }
}
