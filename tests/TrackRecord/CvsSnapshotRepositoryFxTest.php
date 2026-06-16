<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrackRecordRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 (multi-currency-fx): persistence and read-path tests for FX columns.
 *
 * 3.3 — save() persists fx_rate_to_usd / native_currency / native_price;
 *        findLatestByTicker() returns them.
 * 3.4 — TrackRecordRepository::getEvaluations() with a live model_version
 *        never mixes rows from a different version (anti-garbage-return guard).
 *
 * All tests use SQLite in-memory — no live DB needed.
 */
class CvsSnapshotRepositoryFxTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createSqliteDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('
            CREATE TABLE cvs_snapshots (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker                TEXT NOT NULL,
                company_name          TEXT NULL,
                sector                TEXT NULL,
                industry              TEXT NULL,
                model_version         TEXT NULL,
                origin                TEXT NOT NULL DEFAULT \'rescore\',
                score_date            TEXT NOT NULL,
                scored_at             TEXT NOT NULL,
                price_at_snapshot     REAL NULL,
                cvs_swing             REAL NULL,
                cvs_fund              REAL NULL,
                reco_swing            TEXT NULL,
                reco_fund             TEXT NULL,
                golden_signal         TEXT NULL,
                quality_gate          INTEGER NOT NULL DEFAULT 0,
                gate_failures         TEXT NULL,
                pillar_scores         TEXT NULL,
                signals               TEXT NULL,
                days_since_earnings   INTEGER NULL,
                days_to_earnings      INTEGER NULL,
                earnings_state        TEXT NULL,
                earnings_guard_active INTEGER NULL,
                fx_rate_to_usd        REAL NULL,
                native_currency       TEXT NULL,
                native_price          REAL NULL
            )
        ');

        return $pdo;
    }

    /** @return array<string, mixed> */
    private function baseResult(): array
    {
        return [
            'swing'         => ['cvs' => 65.0, 'recommendation' => 'AKUMULUJ'],
            'fundamental'   => ['cvs' => 70.0, 'recommendation' => 'AKUMULUJ'],
            'golden_signal' => 'strong',
            'quality_gate'  => true,
            'gate_failures' => [],
            'pillar_scores' => null,
            'signals'       => null,
            'earnings_timing' => [],
        ];
    }

    private function insertRaw(
        PDO    $pdo,
        string $ticker,
        string $modelVersion,
        string $scoreDate,
        int    $qualityGate,
        float  $price,
        string $origin = 'rescore'
    ): void {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo->prepare('
            INSERT INTO cvs_snapshots
                (ticker, model_version, origin, score_date, scored_at,
                 price_at_snapshot, quality_gate)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$ticker, $modelVersion, $origin, $scoreDate, $now, $price, $qualityGate]);
    }

    // ------------------------------------------------------------------
    // 3.3 — save() persists FX fields; findLatestByTicker() returns them
    // ------------------------------------------------------------------

    public function testSavePersistsFxFieldsAndReaderReturnsThem(): void
    {
        $pdo  = $this->createSqliteDb();
        $repo = new CvsSnapshotRepository($pdo);

        $repo->save(
            '000660.KS',
            $this->baseResult(),
            58.84,         // price_at_snapshot (already USD after Phase 2)
            'Technology',
            'Semiconductors',
            '4.0',
            CvsSnapshotRepository::ORIGIN_RESCORE,
            'SK Hynix',
            1.0 / 1350.0, // fx_rate_to_usd (KRW=X close ~1350)
            'KRW',
            79_500.0       // native_price in KRW
        );

        $row = $repo->findLatestByTicker('000660.KS');

        $this->assertNotNull($row, 'findLatestByTicker must return the saved row');
        $this->assertSame('000660.KS', $row['ticker']);
        $this->assertSame('4.0', $row['model_version']);

        $this->assertEqualsWithDelta(1.0 / 1350.0, (float) $row['fx_rate_to_usd'], 1e-10,
            'fx_rate_to_usd must be persisted and returned correctly');

        $this->assertSame('KRW', $row['native_currency'],
            'native_currency must be persisted as KRW');

        $this->assertEqualsWithDelta(79_500.0, (float) $row['native_price'], 0.01,
            'native_price must be the KRW amount before conversion');
    }

    public function testSaveUsdTickerHasNullFxFields(): void
    {
        $pdo  = $this->createSqliteDb();
        $repo = new CvsSnapshotRepository($pdo);

        $repo->save(
            'AAPL',
            $this->baseResult(),
            185.0,
            'Technology',
            'Consumer Electronics',
            '4.0',
            CvsSnapshotRepository::ORIGIN_RESCORE,
            'Apple Inc',
            null, // fxRateToUsd — USD tickers omit this
            null, // nativeCurrency
            null  // nativePrice
        );

        $row = $repo->findLatestByTicker('AAPL');

        $this->assertNotNull($row);
        $this->assertNull($row['fx_rate_to_usd'],   'USD ticker must have NULL fx_rate_to_usd');
        $this->assertNull($row['native_currency'],   'USD ticker must have NULL native_currency');
        $this->assertNull($row['native_price'],      'USD ticker must have NULL native_price');
    }

    // ------------------------------------------------------------------
    // 3.4 — version filter prevents mixing native-price (3.0) with USD (4.0) rows
    // ------------------------------------------------------------------

    public function testGetEvaluationsWithVersionFilterExcludesOtherVersions(): void
    {
        $pdo   = $this->createSqliteDb();
        $repo  = new TrackRecordRepository($pdo);

        // Dates that satisfy the 30-day horizon cutoffs:
        //   old snapshot: score_date ≤ today-30 days  → use today-40
        //   recent snapshot: score_date ≥ today-7 days → use today (index)
        $dateOld40   = (new DateTimeImmutable('-40 days'))->format('Y-m-d');
        $dateToday   = (new DateTimeImmutable())->format('Y-m-d');

        // Insert a valid 4.0 pair for ticker TST4:
        //   old row (quality_gate=1, price) + recent row with a price → pair is formed
        $this->insertRaw($pdo, 'TST4', '4.0', $dateOld40, 1, 100.0);
        $this->insertRaw($pdo, 'TST4', '4.0', $dateToday,  1, 120.0);

        // Insert a 3.0 pair for ticker TST3 (native-price era):
        $this->insertRaw($pdo, 'TST3', '3.0', $dateOld40, 1, 79_500.0); // KRW native price
        $this->insertRaw($pdo, 'TST3', '3.0', $dateToday,  1, 81_000.0);

        // Filter to 4.0 → only TST4 pair should appear
        $v4 = $repo->getEvaluations(30, '4.0');
        $tickers4 = array_column($v4, 'ticker');

        $this->assertContains('TST4', $tickers4, 'version 4.0 pair must appear with filter 4.0');
        $this->assertNotContains('TST3', $tickers4, 'version 3.0 pair must NOT appear with filter 4.0');

        // Filter to 3.0 → only TST3 pair should appear
        $v3 = $repo->getEvaluations(30, '3.0');
        $tickers3 = array_column($v3, 'ticker');

        $this->assertContains('TST3', $tickers3, 'version 3.0 pair must appear with filter 3.0');
        $this->assertNotContains('TST4', $tickers3, 'version 4.0 pair must NOT appear with filter 3.0');
    }

    public function testGetForTickerWithVersionFilterExcludesOtherVersions(): void
    {
        $pdo  = $this->createSqliteDb();
        $repo = new TrackRecordRepository($pdo);

        $dateOld40 = (new DateTimeImmutable('-40 days'))->format('Y-m-d');
        $dateToday = (new DateTimeImmutable())->format('Y-m-d');

        // Both 3.0 and 4.0 old rows for the same ticker
        $this->insertRaw($pdo, 'TST', '4.0', $dateOld40, 1, 100.0);
        $this->insertRaw($pdo, 'TST', '4.0', $dateToday,  1, 120.0);
        $this->insertRaw($pdo, 'TST', '3.0', $dateOld40, 1, 79_500.0);
        $this->insertRaw($pdo, 'TST', '3.0', $dateToday,  1, 81_000.0);

        $v4 = $repo->getForTicker('TST', 30, '4.0');
        $this->assertCount(1, $v4, 'getForTicker with version 4.0 must return exactly 1 evaluation pair');
        $this->assertSame('4.0', $v4[0]['model_version']);

        $v3 = $repo->getForTicker('TST', 30, '3.0');
        $this->assertCount(1, $v3, 'getForTicker with version 3.0 must return exactly 1 evaluation pair');
        $this->assertSame('3.0', $v3[0]['model_version']);
    }
}
