<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\CVS\CVSResult;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\SnapshotWriter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SnapshotWriter (Phase 7, slice 1 — FR-002).
 *
 * The writer fans a CVSResult out into one row per model version the result
 * carries (base + optional overlay shadow), stamping the caller's origin on
 * every row. SQLite in-memory, post-migration-016 schema.
 */
class SnapshotWriterTest extends TestCase
{
    /** @return array{0: SnapshotWriter, 1: PDO} */
    private function makeWriter(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker             TEXT    NOT NULL,
                sector             TEXT    NULL,
                industry           TEXT    NULL,
                model_version      TEXT    NULL,
                origin             TEXT    NOT NULL DEFAULT \'rescore\',
                days_since_earnings   INTEGER NULL,
                days_to_earnings      INTEGER NULL,
                earnings_state        TEXT    NULL,
                earnings_guard_active INTEGER NULL,
                score_date         TEXT    NOT NULL,
                scored_at          TEXT    NOT NULL,
                price_at_snapshot  REAL    NULL,
                cvs_swing          REAL    NULL,
                cvs_fund           REAL    NULL,
                reco_swing         TEXT    NULL,
                reco_fund          TEXT    NULL,
                golden_signal      TEXT    NULL,
                quality_gate       INTEGER NOT NULL DEFAULT 0,
                gate_failures      TEXT    NULL,
                pillar_scores      TEXT    NULL,
                signals            TEXT    NULL,
                UNIQUE (ticker, score_date, model_version, origin)
            )
        ');

        return [new SnapshotWriter(new CvsSnapshotRepository($pdo)), $pdo];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(PDO $pdo, string $ticker): array
    {
        $stmt = $pdo->prepare(
            'SELECT model_version, origin, cvs_swing, cvs_fund, reco_swing, quality_gate, signals
             FROM cvs_snapshots WHERE ticker = ? ORDER BY model_version ASC'
        );
        $stmt->execute([$ticker]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed> 3.2 shadow block shaped like CVSModel::computeShadow32() output */
    private function shadow32Block(): array
    {
        return [
            'shadow_version' => '3.2',
            'swing'          => 65.0,
            'fund'           => 60.0,
            'swing_reco'     => 'AKUMULUJ',
            'fund_reco'      => 'NEUTRALNIE',
            'penalties'      => ['revision' => -8.0, 'target' => -4.5, 'earnings_guard' => 0.0, 'total' => -8.5],
            'signals'        => [
                'surprise_pct'       => 0.05,
                'breadth'            => 0.6,
                'high_52w_proximity' => 0.9,
                'beat_count_4q'      => 3,
                'adjustments'        => ['pead_guard' => 0.0, 'breadth' => 2.4, 'high_52w' => 4.0, 'consistency' => 0.0, 'total' => 4.0],
            ],
            'coverage' => [
                'missing_eps_trend' => false, 'missing_target' => false, 'missing_earnings_calendar' => false,
                'missing_surprise' => false, 'missing_breadth' => false, 'missing_52w' => false, 'missing_consistency' => false,
            ],
        ];
    }

    /** @return array<string, mixed> Overlay block shaped like CVSModel::computeOverlay() output */
    private function overlayBlock(): array
    {
        return [
            'shadow_version' => '3.1',
            'swing'          => 61.5,
            'fund'           => 57.0,
            'swing_reco'     => 'AKUMULUJ',
            'fund_reco'      => 'NEUTRALNIE',
            'penalties'      => ['revision' => -8.0, 'target' => -4.5, 'earnings_guard' => 0.0, 'total' => -12.5],
            'coverage'       => ['missing_eps_trend' => false, 'missing_target' => false, 'missing_earnings_calendar' => false],
        ];
    }

    private function passedResult(string $ticker, ?array $overlay): CVSResult
    {
        return CVSResult::passed(
            ticker:                    $ticker,
            swingCvs:                  74.0,
            fundamentalCvs:            68.0,
            pillarScores:              ['valuation' => 70.0, 'momentum_swing' => 80.0, 'quality' => 60.0],
            swingRecommendation:       'SILNE KUPUJ',
            fundamentalRecommendation: 'AKUMULUJ',
            modelVersion:              '3.0',
            overlay:                   $overlay,
        );
    }

    /** @param array<int, array<string, mixed>> $shadows */
    private function passedResultWithShadows(string $ticker, array $shadows): CVSResult
    {
        return CVSResult::passed(
            ticker:                    $ticker,
            swingCvs:                  74.0,
            fundamentalCvs:            68.0,
            pillarScores:              ['valuation' => 70.0, 'momentum_swing' => 80.0, 'quality' => 60.0],
            swingRecommendation:       'SILNE KUPUJ',
            fundamentalRecommendation: 'AKUMULUJ',
            modelVersion:              '3.0',
            shadows:                   $shadows,
        );
    }

    public function test_persist_writes_three_rows_for_31_and_32_shadows_with_signals(): void
    {
        [$writer, $pdo] = $this->makeWriter();

        $written = $writer->persist(
            $this->passedResultWithShadows('MU', [$this->overlayBlock(), $this->shadow32Block()]),
            100.0,
            'Technology',
            'Semiconductors',
            CvsSnapshotRepository::ORIGIN_RESCORE
        );

        $this->assertSame(3, $written);

        $rows      = $this->rows($pdo, 'MU');
        $byVersion = array_column($rows, null, 'model_version');

        $this->assertCount(3, $rows);
        $this->assertArrayHasKey('3.0', $byVersion);
        $this->assertArrayHasKey('3.1', $byVersion);
        $this->assertArrayHasKey('3.2', $byVersion);

        $this->assertEquals(65.0, $byVersion['3.2']['cvs_swing']);
        $this->assertEquals(60.0, $byVersion['3.2']['cvs_fund']);

        foreach (['3.0', '3.1', '3.2'] as $version) {
            $this->assertSame(
                CvsSnapshotRepository::ORIGIN_RESCORE,
                $byVersion[$version]['origin']
            );
            $this->assertNotNull($byVersion[$version]['signals'], "version $version must carry the shared signals JSON");

            $signals = json_decode((string) $byVersion[$version]['signals'], true);
            $this->assertSame(0.05, $signals['surprise_pct']);
            $this->assertSame(0.6, $signals['breadth']);
            $this->assertArrayNotHasKey('adjustments', $signals, 'raw signals strip the 3.2-specific adjustments');
        }
    }

    public function test_persist_writes_base_and_shadow_rows_when_overlay_present(): void
    {
        [$writer, $pdo] = $this->makeWriter();

        $written = $writer->persist(
            $this->passedResult('AAPL', $this->overlayBlock()),
            185.50,
            'Technology',
            'Consumer Electronics',
            CvsSnapshotRepository::ORIGIN_RESCORE
        );

        $this->assertSame(2, $written);

        $rows = $this->rows($pdo, 'AAPL');
        $this->assertCount(2, $rows);

        $byVersion = array_column($rows, null, 'model_version');
        $this->assertEquals(74.0, $byVersion['3.0']['cvs_swing'], 'base row carries the unpenalised score');
        $this->assertEquals(61.5, $byVersion['3.1']['cvs_swing'], 'shadow row carries the overlay-penalised score');
        $this->assertEquals(57.0, $byVersion['3.1']['cvs_fund']);
        $this->assertSame('AKUMULUJ', $byVersion['3.1']['reco_swing']);
        $this->assertSame(1, (int) $byVersion['3.1']['quality_gate'], 'gate verdict carries over to the shadow row');
    }

    public function test_persist_writes_only_base_row_when_overlay_null(): void
    {
        [$writer, $pdo] = $this->makeWriter();

        $written = $writer->persist(
            $this->passedResult('MSFT', null),
            400.0,
            'Technology',
            null,
            CvsSnapshotRepository::ORIGIN_RESCORE
        );

        $this->assertSame(1, $written);

        $rows = $this->rows($pdo, 'MSFT');
        $this->assertCount(1, $rows);
        $this->assertSame('3.0', $rows[0]['model_version']);
    }

    public function test_persist_stamps_origin_on_every_row(): void
    {
        [$writer, $pdo] = $this->makeWriter();

        $writer->persist(
            $this->passedResult('NVDA', $this->overlayBlock()),
            900.0,
            'Technology',
            'Semiconductors',
            CvsSnapshotRepository::ORIGIN_CORPUS
        );

        $rows = $this->rows($pdo, 'NVDA');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                CvsSnapshotRepository::ORIGIN_CORPUS,
                $row['origin'],
                'both base and shadow rows must carry the caller\'s origin'
            );
        }
    }

    public function test_persist_ignores_overlay_with_empty_shadow_version(): void
    {
        // Defensive parity with the former rescore.php guard: an overlay block
        // with a blank shadow_version must not produce a phantom versioned row.
        [$writer, $pdo] = $this->makeWriter();

        $overlay                   = $this->overlayBlock();
        $overlay['shadow_version'] = '';

        $written = $writer->persist(
            $this->passedResult('GOOG', $overlay),
            170.0,
            'Technology',
            null,
            CvsSnapshotRepository::ORIGIN_RESCORE
        );

        $this->assertSame(1, $written);
        $this->assertCount(1, $this->rows($pdo, 'GOOG'));
    }
}
