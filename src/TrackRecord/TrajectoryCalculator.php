<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use DateTimeImmutable;

/**
 * Pure trajectory logic — no DB access (Phase 8, slice 1).
 *
 * Turns a CVS Swing snapshot series (from CvsSnapshotRepository::findTrajectory)
 * into chart points plus direction deltas (day-over-day and week-over-week).
 * Deterministic and offline-testable; mirrors the pure-calculator pattern of
 * TrackRecordCalculator.
 *
 * Rows with a null cvs_swing (quality-gate failures) are skipped — they are not
 * points on the trajectory.
 */
class TrajectoryCalculator
{
    /**
     * Summarise a trajectory series.
     *
     * @param array<int, array<string, mixed>> $rows Each row needs score_date + cvs_swing
     *                                               (oldest first, as findTrajectory returns).
     * @param int $minPoints Minimum points required to call it a trajectory.
     * @return array{
     *   points: array<int, array{date: string, cvs: float}>,
     *   latest: float|null,
     *   delta_daily: float|null,
     *   delta_weekly: float|null,
     *   has_trajectory: bool
     * }
     */
    public static function summarise(array $rows, int $minPoints = 2): array
    {
        $points = [];
        foreach ($rows as $row) {
            $cvs = $row['cvs_swing'] ?? null;
            if ($cvs === null) {
                continue; // gate-failed snapshot — not a point
            }
            $date = (string) ($row['score_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $points[] = ['date' => $date, 'cvs' => (float) $cvs];
        }

        $count  = count($points);
        $latest = $count > 0 ? $points[$count - 1]['cvs'] : null;

        $deltaDaily = $count >= 2
            ? round($latest - $points[$count - 2]['cvs'], 1)
            : null;

        $deltaWeekly = self::weekOverWeek($points);

        return [
            'points'         => $points,
            'latest'         => $latest,
            'delta_daily'    => $deltaDaily,
            'delta_weekly'   => $deltaWeekly,
            'has_trajectory' => $count >= $minPoints,
        ];
    }

    /**
     * latest CVS minus the CVS of the most recent point at least 7 days older than
     * the latest point. null when no such earlier point exists.
     *
     * @param array<int, array{date: string, cvs: float}> $points oldest first
     */
    private static function weekOverWeek(array $points): ?float
    {
        $count = count($points);
        if ($count < 2) {
            return null;
        }

        $latest     = $points[$count - 1];
        $latestDate = new DateTimeImmutable($latest['date']);
        $cutoff     = $latestDate->modify('-7 days');

        // Walk backwards to the most recent point on or before the cutoff.
        for ($i = $count - 2; $i >= 0; $i--) {
            if (new DateTimeImmutable($points[$i]['date']) <= $cutoff) {
                return round($latest['cvs'] - $points[$i]['cvs'], 1);
            }
        }

        return null;
    }
}
