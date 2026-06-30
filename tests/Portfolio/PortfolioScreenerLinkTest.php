<?php

declare(strict_types=1);

namespace CVS\Tests\Portfolio;

use CVS\Portfolio\PortfolioRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PortfolioScreenerLinkTest extends TestCase
{
    private PDO                 $db;
    private PortfolioRepository $repo;

    private const MODEL = '4.0';

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('
            CREATE TABLE cvs_snapshots (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker              TEXT    NOT NULL,
                model_version       TEXT    NOT NULL,
                origin              TEXT    NOT NULL DEFAULT "RESCORE",
                quality_gate        INTEGER NOT NULL DEFAULT 1,
                reco_swing          TEXT,
                cvs_swing           REAL,
                cvs_fund            REAL,
                golden_signal       TEXT,
                price_at_snapshot   REAL,
                score_date          TEXT    NOT NULL,
                scored_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->repo = new PortfolioRepository($this->db);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertSnapshot(string $ticker, string $reco, array $overrides = []): void
    {
        $row = array_merge([
            'ticker'            => $ticker,
            'model_version'     => self::MODEL,
            'origin'            => 'RESCORE',
            'quality_gate'      => 1,
            'reco_swing'        => $reco,
            'cvs_swing'         => 60.0,
            'cvs_fund'          => 55.0,
            'golden_signal'     => null,
            'price_at_snapshot' => 100.0,
            'score_date'        => '2026-06-30',
        ], $overrides);

        $this->db->prepare('
            INSERT INTO cvs_snapshots
                (ticker, model_version, origin, quality_gate, reco_swing,
                 cvs_swing, cvs_fund, golden_signal, price_at_snapshot, score_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $row['ticker'], $row['model_version'], $row['origin'],
            $row['quality_gate'], $row['reco_swing'], $row['cvs_swing'],
            $row['cvs_fund'], $row['golden_signal'], $row['price_at_snapshot'],
            $row['score_date'],
        ]);
    }

    // ── reco filter ──────────────────────────────────────────────────────────

    public function testReturnsOnlySilneKupujAndAkumulujRows(): void
    {
        $this->insertSnapshot('AAPL', '⬆⬆ SILNE KUPUJ');
        $this->insertSnapshot('MSFT', '⬆ AKUMULUJ');
        $this->insertSnapshot('GOOG', '→ NEUTRALNIE');
        $this->insertSnapshot('META', '⬇ REDUKUJ');
        $this->insertSnapshot('AMZN', '⬇⬇ UNIKAJ');

        $rows = $this->repo->getScreenerRecommendationsNotHeld([], self::MODEL);

        $this->assertCount(2, $rows);
        $tickers = array_column($rows, 'ticker');
        $this->assertContains('AAPL', $tickers);
        $this->assertContains('MSFT', $tickers);
        $this->assertNotContains('GOOG', $tickers);
        $this->assertNotContains('META', $tickers);
        $this->assertNotContains('AMZN', $tickers);
    }

    // ── held exclusion ───────────────────────────────────────────────────────

    public function testExcludesHeldTickers(): void
    {
        $this->insertSnapshot('NVDA', '⬆⬆ SILNE KUPUJ');
        $this->insertSnapshot('TSLA', '⬆ AKUMULUJ');
        $this->insertSnapshot('AMD',  '⬆⬆ SILNE KUPUJ');

        $rows = $this->repo->getScreenerRecommendationsNotHeld(['NVDA', 'TSLA'], self::MODEL);

        $this->assertCount(1, $rows);
        $this->assertSame('AMD', $rows[0]['ticker']);
    }

    // ── quality_gate filter ──────────────────────────────────────────────────

    public function testExcludesQualityGateFailedRows(): void
    {
        $this->insertSnapshot('GOOD', '⬆⬆ SILNE KUPUJ', ['quality_gate' => 1]);
        $this->insertSnapshot('BAD',  '⬆⬆ SILNE KUPUJ', ['quality_gate' => 0]);

        $rows = $this->repo->getScreenerRecommendationsNotHeld([], self::MODEL);

        $this->assertCount(1, $rows);
        $this->assertSame('GOOD', $rows[0]['ticker']);
    }

    // ── model_version filter ─────────────────────────────────────────────────

    public function testExcludesWrongModelVersionRows(): void
    {
        $this->insertSnapshot('LIVE',   '⬆ AKUMULUJ', ['model_version' => self::MODEL]);
        $this->insertSnapshot('SHADOW', '⬆ AKUMULUJ', ['model_version' => '3.1']);

        $rows = $this->repo->getScreenerRecommendationsNotHeld([], self::MODEL);

        $this->assertCount(1, $rows);
        $this->assertSame('LIVE', $rows[0]['ticker']);
    }

    // ── empty heldTickers ────────────────────────────────────────────────────

    public function testEmptyHeldTickersReturnsAllMatchingRows(): void
    {
        $this->insertSnapshot('AAPL', '⬆⬆ SILNE KUPUJ');
        $this->insertSnapshot('MSFT', '⬆ AKUMULUJ');

        $rows = $this->repo->getScreenerRecommendationsNotHeld([], self::MODEL);

        $this->assertCount(2, $rows);
    }
}
