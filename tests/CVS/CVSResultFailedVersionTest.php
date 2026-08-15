<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\CVSModel;
use CVS\CVS\CVSResult;
use CVS\CVS\Valuation\PeerMedianRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A gate rejection must still carry the live model_version.
 *
 * SnapshotWriter maps an empty version to a NULL `model_version` column. MySQL
 * treats each NULL in a UNIQUE index as distinct, so version-less rows escaped
 * uq_ticker_day_version (one new duplicate per run — MU accumulated five a day)
 * and, being the newest rows for their ticker, masked the last good snapshot
 * from ScreenerRepository::findAllLatest()'s version-agnostic MAX(score_date).
 */
class CVSResultFailedVersionTest extends TestCase
{
    public function testFailedCarriesTheVersionItIsGiven(): void
    {
        $result = CVSResult::failed('MU', ['Brak przychodów (revenue ≤ 0)'], '4.0');

        $this->assertFalse($result->qualityGatePassed);
        $this->assertSame('4.0', $result->modelVersion);
        $this->assertSame('4.0', $result->toArray()['model_version']);
    }

    public function testModelStampsTheLiveVersionOnAGateRejection(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';

        // In-memory peer-median repo so the suite stays fully offline — same
        // pattern as CVSModelTest::setUp(). The gate rejects before any pillar
        // runs, but CVSModel builds the resolver in its constructor.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE peer_medians (
            id INTEGER PRIMARY KEY,
            level TEXT NOT NULL,
            bucket_key TEXT NOT NULL,
            parent_sector TEXT NULL,
            model_version TEXT NOT NULL,
            metric_type TEXT NOT NULL,
            median_value REAL NULL,
            sample_count INTEGER NOT NULL DEFAULT 0,
            computed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(level, bucket_key, model_version, metric_type)
        )');

        // Revenue of exactly 0 is a genuine business rejection (not a data gap),
        // so it reaches CVSResult::failed().
        $result = (new CVSModel($config, new PeerMedianRepository($pdo)))->calculate('ZERO', ['revenue' => 0]);

        $this->assertFalse($result->qualityGatePassed);
        $this->assertSame(
            (string) $config['model_version'],
            $result->modelVersion,
            'A rejected ticker-day is still a versioned observation — a NULL model_version corrupts the snapshot table.'
        );
    }
}
