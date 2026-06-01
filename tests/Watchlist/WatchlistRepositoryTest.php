<?php

declare(strict_types=1);

namespace CVS\Tests\Watchlist;

use CVS\Watchlist\WatchlistRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WatchlistRepository using SQLite in-memory.
 *
 * Each test creates a fresh PDO connection + schema so there is
 * no state leakage between cases.
 */
class WatchlistRepositoryTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeRepo(): WatchlistRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQLite equivalent of the MySQL migration (no FK enforcement needed)
        $pdo->exec('
            CREATE TABLE watchlist (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id  INTEGER NOT NULL,
                ticker   TEXT    NOT NULL,
                added_at TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (user_id, ticker)
            )
        ');

        return new WatchlistRepository($pdo);
    }

    // ------------------------------------------------------------------
    // add()
    // ------------------------------------------------------------------

    public function test_add_inserts_ticker(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');

        $this->assertSame(['AAPL'], $repo->findByUser(1));
    }

    public function test_add_duplicate_does_not_throw(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');

        // Second call must be silently ignored
        $repo->add(1, 'AAPL');

        $this->assertSame(['AAPL'], $repo->findByUser(1));
    }

    public function test_add_is_scoped_to_user(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');
        $repo->add(2, 'MSFT');

        $this->assertSame(['AAPL'], $repo->findByUser(1));
        $this->assertSame(['MSFT'], $repo->findByUser(2));
    }

    // ------------------------------------------------------------------
    // remove()
    // ------------------------------------------------------------------

    public function test_remove_deletes_ticker(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');
        $repo->remove(1, 'AAPL');

        $this->assertSame([], $repo->findByUser(1));
    }

    public function test_remove_nonexistent_does_not_throw(): void
    {
        $repo = $this->makeRepo();

        // Should silently succeed with no exception
        $repo->remove(1, 'AAPL');

        $this->assertSame([], $repo->findByUser(1));
    }

    public function test_remove_only_affects_target_ticker(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');
        $repo->add(1, 'MSFT');
        $repo->remove(1, 'AAPL');

        $this->assertSame(['MSFT'], $repo->findByUser(1));
    }

    // ------------------------------------------------------------------
    // toggle()
    // ------------------------------------------------------------------

    public function test_toggle_adds_when_not_present(): void
    {
        $repo   = $this->makeRepo();
        $action = $repo->toggle(1, 'AAPL');

        $this->assertSame('added', $action);
        $this->assertTrue($repo->isWatched(1, 'AAPL'));
    }

    public function test_toggle_removes_when_present(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');
        $action = $repo->toggle(1, 'AAPL');

        $this->assertSame('removed', $action);
        $this->assertFalse($repo->isWatched(1, 'AAPL'));
    }

    public function test_toggle_add_then_remove_roundtrip(): void
    {
        $repo = $this->makeRepo();

        $this->assertSame('added',   $repo->toggle(1, 'AAPL'));
        $this->assertSame('removed', $repo->toggle(1, 'AAPL'));
        $this->assertSame('added',   $repo->toggle(1, 'AAPL'));
        $this->assertTrue($repo->isWatched(1, 'AAPL'));
    }

    // ------------------------------------------------------------------
    // findByUser()
    // ------------------------------------------------------------------

    public function test_find_by_user_returns_empty_array_when_none(): void
    {
        $repo = $this->makeRepo();

        $this->assertSame([], $repo->findByUser(42));
    }

    public function test_find_by_user_preserves_insertion_order(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'MSFT');
        $repo->add(1, 'AAPL');
        $repo->add(1, 'GOOG');

        // Must come back in insertion order (added_at ASC, id ASC)
        $this->assertSame(['MSFT', 'AAPL', 'GOOG'], $repo->findByUser(1));
    }

    // ------------------------------------------------------------------
    // isWatched()
    // ------------------------------------------------------------------

    public function test_is_watched_returns_true_when_present(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');

        $this->assertTrue($repo->isWatched(1, 'AAPL'));
    }

    public function test_is_watched_returns_false_when_absent(): void
    {
        $repo = $this->makeRepo();

        $this->assertFalse($repo->isWatched(1, 'AAPL'));
    }

    public function test_is_watched_is_scoped_to_user(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');

        // User 2 has NOT added AAPL
        $this->assertFalse($repo->isWatched(2, 'AAPL'));
    }

    // ------------------------------------------------------------------
    // countByUser()
    // ------------------------------------------------------------------

    public function test_count_returns_zero_for_new_user(): void
    {
        $repo = $this->makeRepo();

        $this->assertSame(0, $repo->countByUser(99));
    }

    public function test_count_reflects_adds_and_removes(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');
        $repo->add(1, 'MSFT');
        $this->assertSame(2, $repo->countByUser(1));

        $repo->remove(1, 'AAPL');
        $this->assertSame(1, $repo->countByUser(1));
    }

    // ------------------------------------------------------------------
    // findAllDistinctTickers()
    // ------------------------------------------------------------------

    public function test_find_all_distinct_returns_empty_when_no_watchlist(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->findAllDistinctTickers());
    }

    public function test_find_all_distinct_deduplicates_across_users(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'AAPL');
        $repo->add(2, 'AAPL'); // same ticker, different user
        $repo->add(2, 'MSFT');

        $tickers = $repo->findAllDistinctTickers();
        $this->assertCount(2, $tickers);
        $this->assertContains('AAPL', $tickers);
        $this->assertContains('MSFT', $tickers);
    }

    public function test_find_all_distinct_returns_sorted(): void
    {
        $repo = $this->makeRepo();
        $repo->add(1, 'MSFT');
        $repo->add(1, 'AAPL');
        $repo->add(1, 'NVDA');

        $tickers = $repo->findAllDistinctTickers();
        $this->assertSame(['AAPL', 'MSFT', 'NVDA'], $tickers);
    }
}
