<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\CycleRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class CycleRepositoryTest extends TestCase
{
    private PDO             $db;
    private CycleRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE rebalance_cycle (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date    TEXT    NOT NULL UNIQUE,
                status        TEXT    NOT NULL DEFAULT "started",
                attempt_count INTEGER NOT NULL DEFAULT 1,
                started_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at   TEXT
            )
        ');

        $this->repo = new CycleRepository($this->db);
    }

    /** @return array<string,mixed> */
    private function fetch(string $date): array
    {
        $stmt = $this->db->prepare('SELECT * FROM rebalance_cycle WHERE cycle_date = ?');
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    public function testFirstRunInsertsFreshCycle(): void
    {
        $id = $this->repo->claimForRun('2026-06-29', 3);

        $this->assertNotNull($id);
        $row = $this->fetch('2026-06-29');
        $this->assertSame('started', $row['status']);
        $this->assertSame(1, (int) $row['attempt_count']);
    }

    public function testCompletedCycleIsNotReRun(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, attempt_count) VALUES ('2026-06-29', 'completed', 1)");

        $this->assertNull($this->repo->claimForRun('2026-06-29', 3));
    }

    public function testStartedCycleIsNotReRun(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, attempt_count) VALUES ('2026-06-29', 'started', 1)");

        $this->assertNull($this->repo->claimForRun('2026-06-29', 3));
    }

    public function testFailedCycleIsRetriedAndIncrementsAttempt(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, attempt_count, finished_at) VALUES ('2026-06-29', 'llm_failed', 1, CURRENT_TIMESTAMP)");

        $id = $this->repo->claimForRun('2026-06-29', 3);

        $this->assertNotNull($id);
        $row = $this->fetch('2026-06-29');
        $this->assertSame('started', $row['status']);
        $this->assertSame(2, (int) $row['attempt_count']);
        $this->assertNull($row['finished_at']);
    }

    public function testRetriesExhaustedReturnsNull(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, attempt_count) VALUES ('2026-06-29', 'failed', 3)");

        $this->assertNull($this->repo->claimForRun('2026-06-29', 3));
        // attempt_count must remain unchanged.
        $this->assertSame(3, (int) $this->fetch('2026-06-29')['attempt_count']);
    }

    public function testRetryAllowedAtSecondAttemptWithMaxThree(): void
    {
        $this->db->exec("INSERT INTO rebalance_cycle (cycle_date, status, attempt_count) VALUES ('2026-06-29', 'failed', 2)");

        $id = $this->repo->claimForRun('2026-06-29', 3);

        $this->assertNotNull($id);
        $this->assertSame(3, (int) $this->fetch('2026-06-29')['attempt_count']);
    }
}
