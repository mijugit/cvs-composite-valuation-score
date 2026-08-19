<?php

declare(strict_types=1);

namespace CVS\Tests\LlmGemini;

use CVS\LlmGemini\LlmGeminiCycleRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class LlmGeminiCycleRepositoryTest extends TestCase
{
    private PDO                      $db;
    private LlmGeminiCycleRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE llm_gemini_cycle (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                cycle_date          TEXT    NOT NULL UNIQUE,
                status              TEXT    NOT NULL DEFAULT "started",
                attempt_count       INTEGER NOT NULL DEFAULT 1,
                started_at          TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at         TEXT,
                cash_before         REAL,
                cash_after          REAL,
                portfolio_value_usd REAL,
                executed_count      INTEGER NOT NULL DEFAULT 0,
                skipped_count       INTEGER NOT NULL DEFAULT 0,
                notes               TEXT,
                retry_count         INTEGER NOT NULL DEFAULT 0,
                llm_raw_response    TEXT,
                llm_failure_kind    TEXT,
                llm_decision_json   TEXT,
                legend              TEXT,
                tokens_input        INTEGER NOT NULL DEFAULT 0,
                tokens_output       INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->repo = new LlmGeminiCycleRepository($this->db);
    }

    /** @return array<string,mixed> */
    private function fetch(string $date): array
    {
        $stmt = $this->db->prepare('SELECT * FROM llm_gemini_cycle WHERE cycle_date = ?');
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    public function testFirstRunInsertsFreshCycle(): void
    {
        $id = $this->repo->claimForRun('2026-08-19', 3);

        $this->assertNotNull($id);
        $row = $this->fetch('2026-08-19');
        $this->assertSame('started', $row['status']);
        $this->assertSame(1, (int) $row['attempt_count']);
    }

    public function testCompletedCycleIsNotReRun(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'completed', 1)");

        $this->assertNull($this->repo->claimForRun('2026-08-19', 3));
    }

    public function testStartedCycleIsNotReRun(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'started', 1)");

        $this->assertNull($this->repo->claimForRun('2026-08-19', 3));
    }

    public function testFailedCycleIsRetriedAndIncrementsAttempt(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count, finished_at) VALUES ('2026-08-19', 'llm_failed', 1, CURRENT_TIMESTAMP)");

        $id = $this->repo->claimForRun('2026-08-19', 3);

        $this->assertNotNull($id);
        $row = $this->fetch('2026-08-19');
        $this->assertSame('started', $row['status']);
        $this->assertSame(2, (int) $row['attempt_count']);
        $this->assertNull($row['finished_at']);
    }

    public function testRetriesExhaustedReturnsNull(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'failed', 3)");

        $this->assertNull($this->repo->claimForRun('2026-08-19', 3));
        $this->assertSame(3, (int) $this->fetch('2026-08-19')['attempt_count']);
    }

    public function testRetryAllowedAtSecondAttemptWithMaxThree(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'failed', 2)");

        $id = $this->repo->claimForRun('2026-08-19', 3);

        $this->assertNotNull($id);
        $this->assertSame(3, (int) $this->fetch('2026-08-19')['attempt_count']);
    }

    public function testUpdateLlmRecordWritesLegendAndTokenUsage(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'started', 1)");
        $id = (int) $this->db->lastInsertId();

        $this->repo->updateLlmRecord(
            $id,
            1,
            '{"decisions":[],"legend":"Trzymam kurs."}',
            null,
            '[]',
            'Trzymam kurs — teza bez zmian, ale rozważyłem osłabienie marż.',
            1200,
            340
        );

        $row = $this->fetch('2026-08-19');
        $this->assertSame(1, (int) $row['retry_count']);
        $this->assertSame('Trzymam kurs — teza bez zmian, ale rozważyłem osłabienie marż.', $row['legend']);
        $this->assertSame(1200, (int) $row['tokens_input']);
        $this->assertSame(340, (int) $row['tokens_output']);
        $this->assertNull($row['llm_failure_kind']);
    }

    public function testUpdateCycleSummaryWritesFinancials(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'started', 1)");
        $id = (int) $this->db->lastInsertId();

        $this->repo->updateCycleSummary($id, 10000.0, 8500.5, 10123.45, 2, 1, 'ok');

        $row = $this->fetch('2026-08-19');
        $this->assertEqualsWithDelta(10000.0, (float) $row['cash_before'], 0.001);
        $this->assertEqualsWithDelta(8500.5, (float) $row['cash_after'], 0.001);
        $this->assertEqualsWithDelta(10123.45, (float) $row['portfolio_value_usd'], 0.001);
        $this->assertSame(2, (int) $row['executed_count']);
        $this->assertSame(1, (int) $row['skipped_count']);
    }

    public function testUpdateStatusSetsFinishedAt(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-19', 'started', 1)");
        $id = (int) $this->db->lastInsertId();

        $this->repo->updateStatus($id, 'completed');

        $row = $this->fetch('2026-08-19');
        $this->assertSame('completed', $row['status']);
        $this->assertNotNull($row['finished_at']);
    }

    public function testGetValueSeriesReturnsCompletedCyclesOldestFirst(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count, portfolio_value_usd) VALUES ('2026-08-19', 'completed', 1, 10123.45)");
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count, portfolio_value_usd) VALUES ('2026-08-17', 'completed', 1, 10000.00)");
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-18', 'llm_failed', 1)");

        $series = $this->repo->getValueSeries();

        $this->assertCount(2, $series);
        $this->assertSame('2026-08-17', $series[0]['date']);
        $this->assertEqualsWithDelta(10000.00, $series[0]['value'], 0.001);
        $this->assertSame('2026-08-19', $series[1]['date']);
        $this->assertEqualsWithDelta(10123.45, $series[1]['value'], 0.001);
    }

    public function testGetValueSeriesReturnsEmptyWhenNoCompletedCycles(): void
    {
        $this->db->exec("INSERT INTO llm_gemini_cycle (cycle_date, status, attempt_count) VALUES ('2026-08-18', 'llm_failed', 1)");

        $this->assertSame([], $this->repo->getValueSeries());
    }
}
