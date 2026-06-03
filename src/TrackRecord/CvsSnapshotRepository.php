<?php

declare(strict_types=1);

namespace CVS\TrackRecord;

use CVS\Core\Database;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Snapshot persistence for the daily-rescore engine.
 *
 * Stores one CVS snapshot per (ticker, score_date); a second run on the same
 * day overwrites the row with fresher data. Accepts optional PDO injection
 * for test isolation (SQLite in-memory).
 *
 * Table DDL: database/migrations/004_create_cvs_snapshots.sql
 */
class CvsSnapshotRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Upsert a CVS snapshot for today.
     *
     * Idempotent: a second call with the same ticker on the same day updates
     * the existing row (MySQL ON DUPLICATE KEY / SQLite UNIQUE constraint
     * handled in PHP so both engines work).
     *
     * @param array<string, mixed> $result          CVSResult::toArray()
     * @param float|null           $priceAtSnapshot Current price at scoring time (S-02)
     * @param string|null          $sector          Yahoo Finance sector (S-03 screener filter)
     * @param string|null          $industry        Yahoo Finance industry / sub-sector (Phase 3)
     * @param string|null          $modelVersion    CVS model version stamp (Phase 3)
     */
    public function save(
        string  $ticker,
        array   $result,
        ?float  $priceAtSnapshot = null,
        ?string $sector          = null,
        ?string $industry        = null,
        ?string $modelVersion    = null
    ): void {
        $scoreDate = (new DateTimeImmutable())->format('Y-m-d');
        $scoredAt  = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $swing = $result['swing']        ?? [];
        $fund  = $result['fundamental']  ?? [];
        $gs    = $result['golden_signal'] ?? null;
        $gate  = (int) ($result['quality_gate'] ?? false);
        $gf    = isset($result['gate_failures']) ? json_encode($result['gate_failures']) : null;
        $ps    = isset($result['pillar_scores'])  ? json_encode($result['pillar_scores'])  : null;

        $params = [
            ':ticker'            => $ticker,
            ':sector'            => $sector,
            ':industry'          => $industry,
            ':model_version'     => $modelVersion,
            ':score_date'        => $scoreDate,
            ':scored_at'         => $scoredAt,
            ':price_at_snapshot' => $priceAtSnapshot,
            ':cvs_swing'         => isset($swing['cvs']) ? (float) $swing['cvs'] : null,
            ':cvs_fund'          => isset($fund['cvs'])  ? (float) $fund['cvs']  : null,
            ':reco_swing'        => $swing['recommendation'] ?? null,
            ':reco_fund'         => $fund['recommendation']  ?? null,
            ':golden_signal'     => $gs !== '' ? $gs : null,
            ':quality_gate'      => $gate,
            ':gate_failures'     => $gf,
            ':pillar_scores'     => $ps,
        ];

        try {
            $stmt = $this->db->prepare('
                INSERT INTO cvs_snapshots
                    (ticker, sector, industry, model_version, score_date, scored_at,
                     price_at_snapshot, cvs_swing, cvs_fund, reco_swing, reco_fund,
                     golden_signal, quality_gate, gate_failures, pillar_scores)
                VALUES
                    (:ticker, :sector, :industry, :model_version, :score_date, :scored_at,
                     :price_at_snapshot, :cvs_swing, :cvs_fund, :reco_swing, :reco_fund,
                     :golden_signal, :quality_gate, :gate_failures, :pillar_scores)
            ');
            $stmt->execute($params);
        } catch (PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');

            if (!$isDup) {
                error_log(sprintf('CvsSnapshotRepository::save failed for %s: %s', $ticker, $msg));
                return;
            }

            // Second run today → update in place.
            try {
                $upd = $this->db->prepare('
                    UPDATE cvs_snapshots
                    SET sector            = :sector,
                        industry          = :industry,
                        model_version     = :model_version,
                        scored_at         = :scored_at,
                        price_at_snapshot = :price_at_snapshot,
                        cvs_swing         = :cvs_swing,
                        cvs_fund          = :cvs_fund,
                        reco_swing        = :reco_swing,
                        reco_fund         = :reco_fund,
                        golden_signal     = :golden_signal,
                        quality_gate      = :quality_gate,
                        gate_failures     = :gate_failures,
                        pillar_scores     = :pillar_scores
                    WHERE ticker = :ticker AND score_date = :score_date
                ');
                $upd->execute($params);
            } catch (PDOException $ue) {
                error_log(sprintf('CvsSnapshotRepository::update failed for %s: %s', $ticker, $ue->getMessage()));
            }
        }
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Latest snapshot for a ticker (for alert state detection in S-04).
     *
     * @return array<string, mixed>|null
     */
    public function findLatestByTicker(string $ticker): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM cvs_snapshots WHERE ticker = ? ORDER BY score_date DESC LIMIT 1'
        );
        $stmt->execute([$ticker]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Most recent snapshot per ticker across all tickers (for screener S-03).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllLatest(): array
    {
        $stmt = $this->db->prepare('
            SELECT s.*
            FROM cvs_snapshots s
            INNER JOIN (
                SELECT ticker, MAX(score_date) AS max_date
                FROM cvs_snapshots
                GROUP BY ticker
            ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
            ORDER BY s.ticker ASC
        ');
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * History for a ticker from a given date onward (for track record S-02).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByTickerSince(string $ticker, DateTimeImmutable $since): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM cvs_snapshots
             WHERE ticker = ? AND score_date >= ?
             ORDER BY score_date ASC'
        );
        $stmt->execute([$ticker, $since->format('Y-m-d')]);
        return $stmt->fetchAll() ?: [];
    }
}
