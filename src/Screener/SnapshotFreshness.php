<?php

declare(strict_types=1);

namespace CVS\Screener;

use DateTimeImmutable;

/**
 * Age policy for screener snapshots (config: cvs-weights.php → snapshot_freshness).
 *
 * ScreenerRepository::findAllLatest() returns each ticker's newest snapshot with
 * no upper bound on its age. That is right for a human — a stale row is still
 * evidence, as long as its age is visible — but wrong for the autonomous wallets,
 * which cannot tell a figure computed today from one computed six weeks ago.
 *
 * Pure and clock-injected: every method takes the reference date explicitly, so
 * the suite tests it offline and the cron stays deterministic (CLAUDE.md: no
 * hidden date()/time() reads inside logic).
 */
final class SnapshotFreshness
{
    /** Oldest score_date still considered fresh, as a Y-m-d string. */
    public static function cutoffDate(string $asOfDate, int $maxAgeDays): string
    {
        return (new DateTimeImmutable($asOfDate))
            ->modify('-' . max(0, $maxAgeDays) . ' days')
            ->format('Y-m-d');
    }

    /** Whole days between a snapshot's score_date and the reference date. */
    public static function ageInDays(string $scoreDate, string $asOfDate): int
    {
        $from = new DateTimeImmutable(substr($scoreDate, 0, 10));
        $to   = new DateTimeImmutable(substr($asOfDate, 0, 10));

        return (int) $from->diff($to)->format('%r%a');
    }

    /**
     * Splits candidate rows into those an autonomous wallet may act on and the
     * stale ones it may not.
     *
     * Held tickers are ALWAYS kept regardless of age. The executor builds its
     * price map from these same rows, so withholding a held ticker does not make
     * the model cautious about it — it makes the position impossible to exit.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @param  list<string>                     $heldTickers uppercase
     * @return array{kept: array<int, array<string, mixed>>, dropped: list<string>}
     */
    public static function partition(array $rows, array $heldTickers, string $asOfDate, int $maxAgeDays): array
    {
        $cutoff  = self::cutoffDate($asOfDate, $maxAgeDays);
        $held    = array_flip(array_map('strtoupper', $heldTickers));
        $kept    = [];
        $dropped = [];

        foreach ($rows as $row) {
            $ticker    = strtoupper((string) ($row['ticker'] ?? ''));
            $scoreDate = substr((string) ($row['score_date'] ?? ''), 0, 10);

            if (isset($held[$ticker]) || ($scoreDate !== '' && $scoreDate >= $cutoff)) {
                $kept[] = $row;
                continue;
            }

            $dropped[] = $ticker;
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }
}
