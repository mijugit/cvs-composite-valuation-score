<?php

declare(strict_types=1);

namespace CVS\Tests\Screener;

use CVS\Screener\SnapshotFreshness;
use PHPUnit\Framework\TestCase;

class SnapshotFreshnessTest extends TestCase
{
    public function testAgeInDays(): void
    {
        $this->assertSame(0, SnapshotFreshness::ageInDays('2026-08-15', '2026-08-15'));
        $this->assertSame(3, SnapshotFreshness::ageInDays('2026-08-12', '2026-08-15'));
        $this->assertSame(40, SnapshotFreshness::ageInDays('2026-07-06', '2026-08-15'));
    }

    public function testAgeIgnoresATimeComponent(): void
    {
        $this->assertSame(3, SnapshotFreshness::ageInDays('2026-08-12 23:59:00', '2026-08-15'));
    }

    public function testCutoffDate(): void
    {
        $this->assertSame('2026-08-08', SnapshotFreshness::cutoffDate('2026-08-15', 7));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['ticker' => 'ADBE',   'score_date' => '2026-08-14'], // fresh
            ['ticker' => 'MU',     'score_date' => '2026-08-12'], // stale-ish, but held
            ['ticker' => 'CRI.WA', 'score_date' => '2026-07-06'], // 40 days old, not held
            ['ticker' => 'NIO',    'score_date' => '2026-08-10'], // 5 days old, not held
        ];
    }

    /**
     * Real dates from the 2026-08-15 incident: MU (3 days) and NIO (5 days) are
     * still inside a 7-day window, CRI.WA at 40 days is not.
     */
    public function testStaleUnheldRowsAreWithheld(): void
    {
        $out = SnapshotFreshness::partition($this->rows(), [], '2026-08-15', 7);

        $this->assertSame(['ADBE', 'MU', 'NIO'], array_column($out['kept'], 'ticker'));
        $this->assertSame(['CRI.WA'], $out['dropped']);
    }

    public function testATighterWindowWithholdsMore(): void
    {
        $out = SnapshotFreshness::partition($this->rows(), [], '2026-08-15', 2);

        $this->assertSame(['ADBE'], array_column($out['kept'], 'ticker'));
        $this->assertSame(['MU', 'CRI.WA', 'NIO'], $out['dropped']);
    }

    /**
     * The MU trap: the executor prices trades only from these rows, so a held
     * ticker withheld for staleness becomes impossible to sell rather than
     * merely ineligible to buy. Held always wins over age.
     */
    public function testHeldTickersSurviveAnyAge(): void
    {
        $out = SnapshotFreshness::partition($this->rows(), ['CRI.WA'], '2026-08-15', 7);

        $this->assertContains('CRI.WA', array_column($out['kept'], 'ticker'));
        $this->assertSame([], $out['dropped']);
    }

    public function testHeldMatchingIsCaseInsensitive(): void
    {
        $out = SnapshotFreshness::partition($this->rows(), ['cri.wa'], '2026-08-15', 7);

        $this->assertSame([], $out['dropped']);
    }

    public function testRowExactlyOnTheCutoffIsKept(): void
    {
        $rows = [['ticker' => 'X', 'score_date' => '2026-08-08']];

        $out = SnapshotFreshness::partition($rows, [], '2026-08-15', 7);

        $this->assertCount(1, $out['kept']);
    }

    public function testRowWithoutAScoreDateIsWithheld(): void
    {
        $rows = [['ticker' => 'X']];

        $out = SnapshotFreshness::partition($rows, [], '2026-08-15', 7);

        $this->assertSame([], $out['kept']);
        $this->assertSame(['X'], $out['dropped']);
    }

    public function testEmptyInputYieldsEmptyPartition(): void
    {
        $out = SnapshotFreshness::partition([], [], '2026-08-15', 7);

        $this->assertSame([], $out['kept']);
        $this->assertSame([], $out['dropped']);
    }
}
