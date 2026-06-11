<?php

declare(strict_types=1);

/**
 * Phase 5 (cvs-scoring-refinement): score distribution report.
 *
 * Read-only CLI report. Compares the distribution of CVS scores and
 * recommendation labels (model_version 3.0, origin=rescore) before and
 * after the peer-group median switch (Phase 3, config/cvs-weights.php
 * "peer_group.enabled"), so an expert can judge whether the recommendation
 * thresholds (config/cvs-weights.php "thresholds") still mean what they
 * meant before the switch.
 *
 * Nothing is written — this is a measurement-only report (FR-010: any
 * threshold change based on this report is made by hand in
 * config/cvs-weights.php).
 *
 * Usage:
 *   php bin/score_distribution_report.php [--cutoff=YYYY-MM-DD]
 *
 * --cutoff defaults to the date peer-group medians went live
 * (config commit 85e6ddb, 2026-06-03). Snapshots with score_date < cutoff
 * are the "old" (legacy benchmarks) bucket; >= cutoff is "new" (peer-group).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require ROOT_PATH . '/vendor/autoload.php';

// Load .env (same logic as bin/rescore.php / public/index.php).
$envFile = ROOT_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}

use CVS\Core\Database;

$config  = require ROOT_PATH . '/config/cvs-weights.php';
$cutoff  = '2026-06-03';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--cutoff=')) {
        $cutoff = substr($arg, strlen('--cutoff='));
    }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
    fwrite(STDERR, "Invalid --cutoff date: {$cutoff} (expected YYYY-MM-DD)\n");
    exit(1);
}

$thresholds = $config['thresholds'] ?? [];

printf("CVS score distribution report\n");
printf("==============================\n");
printf("model_version: 3.0, origin: rescore\n");
printf("cutoff: %s (rows < cutoff = \"old\" / legacy benchmarks, rows >= cutoff = \"new\" / peer-group)\n", $cutoff);
printf("current thresholds: strong_buy>=%d, accumulate>=%d, neutral>=%d, reduce>=%d (below reduce = avoid)\n\n",
    (int) ($thresholds['strong_buy'] ?? 72),
    (int) ($thresholds['accumulate'] ?? 58),
    (int) ($thresholds['neutral']    ?? 42),
    (int) ($thresholds['reduce']     ?? 28)
);

try {
    $db = Database::connection();
} catch (\Throwable $e) {
    fwrite(STDERR, "Database unavailable: " . $e->getMessage() . "\n");
    fwrite(STDERR, "This report needs read access to cvs_snapshots — run it where DB credentials are configured.\n");
    exit(1);
}

/**
 * @return array<int, array{cvs_swing: ?float, cvs_fund: ?float, reco_swing: ?string, reco_fund: ?string}>
 */
function fetchBucket(\PDO $db, string $op, string $cutoff): array
{
    $stmt = $db->prepare("
        SELECT cvs_swing, cvs_fund, reco_swing, reco_fund
        FROM cvs_snapshots
        WHERE model_version = '3.0'
          AND origin = 'rescore'
          AND score_date {$op} :cutoff
    ");
    $stmt->execute([':cutoff' => $cutoff]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<int, array{cvs_swing: ?float, cvs_fund: ?float, reco_swing: ?string, reco_fund: ?string}> $rows
 */
function printBucketReport(string $label, array $rows): void
{
    printf("--- %s (n=%d) ---\n", $label, count($rows));

    if (count($rows) === 0) {
        printf("  brak danych\n\n");
        return;
    }

    foreach (['cvs_swing' => 'Swing', 'cvs_fund' => 'Fundamental'] as $col => $name) {
        $values = array_values(array_filter(
            array_map(static fn(array $r) => $r[$col], $rows),
            static fn($v) => $v !== null
        ));

        printf("\n  %s score histogram (n=%d):\n", $name, count($values));
        $bins = array_fill(0, 10, 0);
        foreach ($values as $v) {
            $bin = (int) floor((float) $v / 10);
            $bin = max(0, min(9, $bin));
            $bins[$bin]++;
        }
        for ($i = 0; $i < 10; $i++) {
            $lo = $i * 10;
            $hi = $lo + 10;
            printf("    %3d-%3d: %s (%d)\n", $lo, $hi, str_repeat('#', $bins[$i]), $bins[$i]);
        }
    }

    foreach (['reco_swing' => 'Swing', 'reco_fund' => 'Fundamental'] as $col => $name) {
        printf("\n  %s recommendation counts:\n", $name);
        $counts = [];
        foreach ($rows as $r) {
            $reco = $r[$col] ?? '(brak)';
            $counts[$reco] = ($counts[$reco] ?? 0) + 1;
        }
        arsort($counts);
        foreach ($counts as $reco => $n) {
            printf("    %-20s %d\n", $reco, $n);
        }
    }

    printf("\n");
}

$old = fetchBucket($db, '<', $cutoff);
$new = fetchBucket($db, '>=', $cutoff);

printBucketReport('OLD (legacy benchmarks)', $old);
printBucketReport('NEW (peer-group medians)', $new);

printf("Decyzja o progach: udokumentuj w context/changes/cvs-scoring-refinement/plan.md (Progress 5.3).\n");

exit(0);
