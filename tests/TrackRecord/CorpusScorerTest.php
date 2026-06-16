<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\CVS\CVSModel;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\TrackRecord\CorpusScorer;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\SnapshotWriter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests for CorpusScorer (Phase 7 slice 1 — FR-001, plan-review F1).
 *
 * No network: CVSModel gets an empty SQLite peer-median repo (cold-start
 * fallback, same pattern as CVSModelTest) and the writer persists into an
 * in-memory post-migration-016 cvs_snapshots table.
 */
class CorpusScorerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
    }

    /** @return array{0: CorpusScorer, 1: PDO} */
    private function makeScorer(): array
    {
        // Peer-median repo: empty → MedianResolver falls back to cold-start.
        $peerPdo = new PDO('sqlite::memory:');
        $peerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $peerPdo->exec('CREATE TABLE peer_medians (
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

        // Snapshot store: post-migration-016 schema.
        $snapPdo = new PDO('sqlite::memory:');
        $snapPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $snapPdo->exec('
            CREATE TABLE cvs_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker TEXT NOT NULL,
                company_name TEXT NULL,
                sector TEXT NULL,
                industry TEXT NULL,
                model_version TEXT NULL,
                origin TEXT NOT NULL DEFAULT \'rescore\',
                days_since_earnings INTEGER NULL,
                days_to_earnings INTEGER NULL,
                earnings_state TEXT NULL,
                earnings_guard_active INTEGER NULL,
                score_date TEXT NOT NULL,
                scored_at TEXT NOT NULL,
                price_at_snapshot REAL NULL,
                cvs_swing REAL NULL, cvs_fund REAL NULL,
                reco_swing TEXT NULL, reco_fund TEXT NULL,
                golden_signal TEXT NULL,
                quality_gate INTEGER NOT NULL DEFAULT 0,
                gate_failures TEXT NULL, pillar_scores TEXT NULL, signals TEXT NULL,
                fx_rate_to_usd REAL NULL, native_currency TEXT NULL, native_price REAL NULL,
                UNIQUE (ticker, score_date, model_version, origin)
            )
        ');

        $scorer = new CorpusScorer(
            new CVSModel($this->config, new PeerMedianRepository($peerPdo)),
            new SnapshotWriter(new CvsSnapshotRepository($snapPdo))
        );

        return [$scorer, $snapPdo];
    }

    /**
     * Canonical passing fixture (mirrors CVSModelTest::baseFinancials()).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseFinancials(array $overrides = []): array
    {
        return array_merge([
            'sector'                     => 'Technology',
            'industry'                   => 'Consumer Electronics',
            'current_price'              => 150.0,
            'fifty_two_week_low'         => 100.0,
            'fifty_two_week_high'        => 200.0,
            'moving_average_200'         => 145.0,
            'revenue'                    => 10_000_000,
            'gross_profit'               =>  3_000_000,
            'ebitda'                     =>  2_000_000,
            'revenue_history'            => [7_000_000, 8_000_000, 9_000_000, 10_000_000],
            'gross_margin_history'       => [0.29, 0.30, 0.30, 0.30],
            'total_debt'                 =>  1_000_000,
            'total_equity'               =>  5_000_000,
            'cash'                       =>    500_000,
            'current_assets'             =>  3_000_000,
            'current_liabilities'        =>  1_500_000,
            'free_cash_flow'             =>  1_500_000,
            'operating_cash_flow'        =>  1_800_000,
            'return_on_equity'           => 0.18,
            'pe_ratio'                   => 22.0,
            'ps_ratio'                   =>  2.5,
            'ev_ebitda'                  => 10.0,
            'shares_outstanding'         => 15_000_000_000.0,
            'gross_margins'              => 0.45,
            'forward_eps'                => 7.0,
            'trailing_eps'               => 6.0,
            'revenue_growth'             => 0.10,
            'earnings_quarterly_growth'  => null,
            'monthly_closes'             => [140.0, 145.0, 150.0, 148.0, 155.0, 160.0, 162.0],
            'spy_closes'                 => [430.0, 432.0, 435.0, 433.0, 438.0, 440.0, 442.0],
        ], $overrides);
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT ticker, model_version, origin, cvs_swing, sector, industry, price_at_snapshot FROM cvs_snapshots');
        return $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function test_passing_ticker_persists_corpus_rows(): void
    {
        [$scorer, $pdo] = $this->makeScorer();

        $written = $scorer->scoreAndPersist('AAPL', $this->baseFinancials());

        $this->assertGreaterThanOrEqual(1, $written);
        $rows = $this->rows($pdo);
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(CvsSnapshotRepository::ORIGIN_CORPUS, $row['origin'], 'crawl rows must carry origin=corpus');
            $this->assertNotNull($row['cvs_swing'], 'corpus rows always carry a score (gate-failed are skipped)');
        }

        $base = $rows[0];
        $this->assertSame('AAPL', $base['ticker']);
        $this->assertSame('Technology', $base['sector']);
        $this->assertSame('Consumer Electronics', $base['industry']);
        $this->assertEquals(150.0, (float) $base['price_at_snapshot']);
        $this->assertNotNull($base['model_version'], 'base row is stamped with the config model_version');
    }

    public function test_gate_failed_ticker_writes_nothing(): void
    {
        // Plan-review F1: a gate-failed result has no calibration value and no
        // modelVersion stamp — persisting it would break same-day idempotency
        // (NULL model_version is NULL-distinct inside the UNIQUE index).
        [$scorer, $pdo] = $this->makeScorer();

        $written = $scorer->scoreAndPersist('FAIL', $this->baseFinancials(['revenue' => 0]));

        $this->assertSame(0, $written);
        $this->assertSame([], $this->rows($pdo), 'gate-failed tickers must leave zero corpus rows');
    }

    public function test_same_day_rerun_is_idempotent(): void
    {
        [$scorer, $pdo] = $this->makeScorer();

        $first  = $scorer->scoreAndPersist('AAPL', $this->baseFinancials());
        $second = $scorer->scoreAndPersist('AAPL', $this->baseFinancials(['current_price' => 151.0]));

        $this->assertSame($first, $second, 'rerun writes the same number of rows (in place)');
        $rows = $this->rows($pdo);
        $this->assertCount($first, $rows, 'rerun must update in place, never duplicate');
        $this->assertEquals(151.0, (float) $rows[0]['price_at_snapshot'], 'rerun refreshes the row content');
    }
}
