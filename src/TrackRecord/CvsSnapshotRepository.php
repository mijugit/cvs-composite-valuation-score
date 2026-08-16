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
    /**
     * Snapshot origin layer (Phase 7, slice 1 — FR-003, migration 016).
     * 'rescore' = user-facing rows written by bin/rescore.php (watchlist union);
     * 'corpus'  = calibration-corpus rows piggybacked on the peer-median crawl.
     * User-facing reads MUST filter to ORIGIN_RESCORE — corpus rows are an
     * internal measurement layer and never surface in screener/track-record/UI.
     */
    public const ORIGIN_RESCORE = 'rescore';
    public const ORIGIN_CORPUS  = 'corpus';

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
     * @param string               $origin          Snapshot origin: ORIGIN_RESCORE (user-facing,
     *                                              default — backward compatible) or ORIGIN_CORPUS
     *                                              (calibration layer, Phase 7 slice 1)
     * @param string|null          $companyName     Yahoo Finance long name (FinancialDataFetcher
     *                                              'long_name'), for watchlist tooltip (migration 018)
     * @param float|null           $fairValuePrice  CVS implied fair value (FairPriceCalculator::compute()),
     *                                              same figure across every model-version row for a given
     *                                              ticker-day — for the screener FV column (migration 031)
     */
    public function save(
        string  $ticker,
        array   $result,
        ?float  $priceAtSnapshot = null,
        ?string $sector          = null,
        ?string $industry        = null,
        ?string $modelVersion    = null,
        string  $origin          = self::ORIGIN_RESCORE,
        ?string $companyName     = null,
        ?float  $fxRateToUsd    = null,
        ?string $nativeCurrency  = null,
        ?float  $nativePrice     = null,
        ?float  $fairValuePrice  = null
    ): void {
        $scoreDate = (new DateTimeImmutable())->format('Y-m-d');
        $scoredAt  = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $swing = $result['swing']        ?? [];
        $fund  = $result['fundamental']  ?? [];
        $gs    = $result['golden_signal'] ?? null;
        $gate  = (int) ($result['quality_gate'] ?? false);
        $gf    = isset($result['gate_failures']) ? json_encode($result['gate_failures']) : null;
        $ps    = isset($result['pillar_scores'])  ? json_encode($result['pillar_scores'])  : null;

        // Phase 7 (slice 2): raw predictive-signal inputs (FR-022), shared across
        // base/3.1/3.2 rows for the same ticker-day — see migration 017.
        $sig   = isset($result['signals']) ? json_encode($result['signals']) : null;

        // Phase 5 (slice 2): earnings-timing markers (FR-008) — additive, mirrors the
        // always-present CVSResult::$earningsTiming block (null when the ticker has
        // no `calendarEvents` coverage at all, or quality gate failed; see EarningsGuard).
        $et = $result['earnings_timing'] ?? [];

        // Which tier of the peer-group ladder the Valuation pillar landed on.
        // Persisted (migration 036) because it is the authoritative answer to
        // "did this company get a real peer comparison?" — downstream code was
        // previously reconstructing it from peer_medians.sample_count for
        // ev_fcf alone, which mislabels every variant-B (EV/Sales) company.
        $vr = is_array($result['valuation_reference'] ?? null) ? $result['valuation_reference'] : [];

        $params = [
            ':ticker'                => $ticker,
            ':company_name'          => $companyName,
            ':sector'                => $sector,
            ':industry'              => $industry,
            ':model_version'         => $modelVersion,
            ':origin'                => $origin,
            ':score_date'            => $scoreDate,
            ':scored_at'             => $scoredAt,
            ':price_at_snapshot'     => $priceAtSnapshot,
            ':cvs_swing'             => isset($swing['cvs']) ? (float) $swing['cvs'] : null,
            ':cvs_fund'              => isset($fund['cvs'])  ? (float) $fund['cvs']  : null,
            ':reco_swing'            => $swing['recommendation'] ?? null,
            ':reco_fund'             => $fund['recommendation']  ?? null,
            ':golden_signal'         => $gs !== '' ? $gs : null,
            ':quality_gate'          => $gate,
            ':gate_failures'         => $gf,
            ':pillar_scores'         => $ps,
            ':days_since_earnings'   => isset($et['days_since']) ? (int) $et['days_since'] : null,
            ':days_to_earnings'      => isset($et['days_to'])    ? (int) $et['days_to']    : null,
            ':earnings_state'        => $et['state'] ?? null,
            ':earnings_guard_active' => isset($et['guard_active']) ? (int) $et['guard_active'] : null,
            ':signals'               => $sig,
            ':fx_rate_to_usd'        => $fxRateToUsd,
            ':native_currency'       => $nativeCurrency,
            ':native_price'          => $nativePrice,
            ':fair_value_price'      => $fairValuePrice,
            ':valuation_source'      => isset($vr['source'])  && $vr['source']  !== '' ? (string) $vr['source']  : null,
            ':valuation_bucket'      => isset($vr['bucket'])  && $vr['bucket']  !== '' ? (string) $vr['bucket']  : null,
            ':valuation_variant'     => isset($vr['variant']) && $vr['variant'] !== '' ? (string) $vr['variant'] : null,
        ];

        try {
            $stmt = $this->db->prepare('
                INSERT INTO cvs_snapshots
                    (ticker, company_name, sector, industry, model_version, origin, score_date, scored_at,
                     price_at_snapshot, cvs_swing, cvs_fund, reco_swing, reco_fund,
                     golden_signal, quality_gate, gate_failures, pillar_scores, signals,
                     days_since_earnings, days_to_earnings, earnings_state, earnings_guard_active,
                     fx_rate_to_usd, native_currency, native_price, fair_value_price,
                     valuation_source, valuation_bucket, valuation_variant)
                VALUES
                    (:ticker, :company_name, :sector, :industry, :model_version, :origin, :score_date, :scored_at,
                     :price_at_snapshot, :cvs_swing, :cvs_fund, :reco_swing, :reco_fund,
                     :golden_signal, :quality_gate, :gate_failures, :pillar_scores, :signals,
                     :days_since_earnings, :days_to_earnings, :earnings_state, :earnings_guard_active,
                     :fx_rate_to_usd, :native_currency, :native_price, :fair_value_price,
                     :valuation_source, :valuation_bucket, :valuation_variant)
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
                    SET company_name          = COALESCE(:company_name, company_name),
                        sector                = :sector,
                        industry              = :industry,
                        model_version         = :model_version,
                        origin                = :origin,
                        scored_at             = :scored_at,
                        price_at_snapshot     = :price_at_snapshot,
                        cvs_swing             = :cvs_swing,
                        cvs_fund              = :cvs_fund,
                        reco_swing            = :reco_swing,
                        reco_fund             = :reco_fund,
                        golden_signal         = :golden_signal,
                        quality_gate          = :quality_gate,
                        gate_failures         = :gate_failures,
                        pillar_scores         = :pillar_scores,
                        signals               = :signals,
                        days_since_earnings   = :days_since_earnings,
                        days_to_earnings      = :days_to_earnings,
                        earnings_state        = :earnings_state,
                        earnings_guard_active = :earnings_guard_active,
                        fx_rate_to_usd        = :fx_rate_to_usd,
                        native_currency       = :native_currency,
                        native_price          = :native_price,
                        fair_value_price      = :fair_value_price,
                        valuation_source      = :valuation_source,
                        valuation_bucket      = :valuation_bucket,
                        valuation_variant     = :valuation_variant
                    WHERE ticker = :ticker AND score_date = :score_date
                      AND (model_version = :model_version_match
                           OR (model_version IS NULL AND :model_version_match_null IS NULL))
                      AND origin = :origin_match
                ');
                // Two distinct placeholder names for :model_version_match because the
                // name appears twice in the WHERE clause (equality check + NULL-safe
                // fallback).  PDO MySQL with emulated prepares OFF converts named
                // params to positional `?`; a duplicated name produces two `?`s but
                // only one bound value → SQLSTATE[HY093].  Using a separate name for
                // each occurrence is the portable fix (works on MySQL and SQLite).
                // :origin_match gets its own name for the same reason (origin already
                // appears in SET as :origin); origin is NOT NULL so plain equality —
                // no NULL-safe fallback needed. Matching origin here keeps a same-day
                // corpus re-run from overwriting the rescore row (and vice versa) —
                // origin is part of a snapshot's identity since migration 016.
                $upd->execute($params + [
                    ':model_version_match'      => $modelVersion,
                    ':model_version_match_null' => $modelVersion,
                    ':origin_match'             => $origin,
                ]);
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
     * Phase 7 (slice 1, FR-003): hard-filtered to ORIGIN_RESCORE — corpus rows
     * are a calibration-only measurement layer and never feed user-facing reads.
     * The calibration pipeline (later slice) reads corpus rows via its own queries.
     *
     * $liveModelVersion pins the row to the production-facing model (mirrors
     * findAllLatest) — since a single (ticker, score_date) can carry several
     * shadow rows (3.1/3.2 alongside the live version), an unfiltered query is
     * ambiguous about which one comes back. Nullable for backward compatibility.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Latest known sector/industry per ticker, across every origin.
     *
     * Deliberately NOT filtered to ORIGIN_RESCORE: the admin ticker list covers
     * the whole ~600-name crawl population, and most of those are only ever
     * touched by the corpus crawl. Filtering to rescore would leave the column
     * blank for the majority — and the whole point of showing it is to let an
     * operator see which Yahoo bucket a company currently sits in before
     * deciding whether to override it.
     *
     * @return array<string, array{sector: ?string, industry: ?string, score_date: string}>
     */
    public function findClassificationMap(): array
    {
        $stmt = $this->db->query('
            SELECT s.ticker, s.sector, s.industry, s.score_date
            FROM cvs_snapshots s
            INNER JOIN (
                SELECT ticker, MAX(score_date) AS max_date
                FROM cvs_snapshots
                GROUP BY ticker
            ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
        ');
        $rows = $stmt !== false ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $out = [];
        foreach ($rows as $r) {
            $t = strtoupper((string) $r['ticker']);
            // A ticker-day can carry several version rows; any of them answers
            // the classification question, so first one wins.
            if (isset($out[$t])) {
                continue;
            }
            $out[$t] = [
                'sector'     => $r['sector']   !== null ? (string) $r['sector']   : null,
                'industry'   => $r['industry'] !== null ? (string) $r['industry'] : null,
                'score_date' => (string) $r['score_date'],
            ];
        }
        return $out;
    }

    /**
     * Most recent snapshot for one ticker.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestByTicker(string $ticker, ?string $liveModelVersion = null): ?array
    {
        if ($liveModelVersion !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM cvs_snapshots WHERE ticker = ? AND origin = ? AND model_version = ?
                 ORDER BY score_date DESC LIMIT 1'
            );
            $stmt->execute([$ticker, self::ORIGIN_RESCORE, $liveModelVersion]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM cvs_snapshots WHERE ticker = ? AND origin = ? ORDER BY score_date DESC LIMIT 1'
            );
            $stmt->execute([$ticker, self::ORIGIN_RESCORE]);
        }
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Most recent snapshot per ticker across all tickers (for screener S-03).
     *
     * Hotfix (2026-06-08): since `cvs-overlay-penalties` (p4, shadow persistence,
     * SHA 9530a10) started writing a second "shadow" row per (ticker, score_date)
     * under model_version 3.1 — alongside the live 3.0 row, both sharing the same
     * score_date — the unfiltered MAX(score_date) join returns BOTH rows for the
     * same day, doubling results downstream (dashboard chip map, screener listing).
     * Pass the live `model_version` (config/cvs-weights.php → model_version) to
     * restrict "latest" to the production-facing model only; shadow rows are an
     * internal preview and must never surface in user-facing "latest" reads.
     * Optional + nullable for backward compatibility (existing tests / callers
     * that don't care about shadow rows, e.g. pre-shadow-persistence snapshots).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllLatest(?string $liveModelVersion = null): array
    {
        // Phase 7 (slice 1, FR-003): both branches hard-filter to ORIGIN_RESCORE —
        // corpus rows share the same model_version values (3.0/3.1/…), so the
        // version filter alone does NOT exclude them; without the origin filter
        // the corpus would leak into user-facing "latest" reads (dashboard chips,
        // screener) exactly like the 2026-06-08 shadow-row duplication bug.
        if ($liveModelVersion !== null) {
            $stmt = $this->db->prepare('
                SELECT s.*
                FROM cvs_snapshots s
                INNER JOIN (
                    SELECT ticker, MAX(score_date) AS max_date
                    FROM cvs_snapshots
                    WHERE model_version = :live_version
                      AND origin = :origin
                    GROUP BY ticker
                ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
                WHERE s.model_version = :live_version_join
                  AND s.origin = :origin_join
                ORDER BY s.ticker ASC
            ');
            $stmt->execute([
                ':live_version'      => $liveModelVersion,
                ':live_version_join' => $liveModelVersion,
                ':origin'            => self::ORIGIN_RESCORE,
                ':origin_join'       => self::ORIGIN_RESCORE,
            ]);
            return $stmt->fetchAll() ?: [];
        }

        $stmt = $this->db->prepare('
            SELECT s.*
            FROM cvs_snapshots s
            INNER JOIN (
                SELECT ticker, MAX(score_date) AS max_date
                FROM cvs_snapshots
                WHERE origin = :origin
                GROUP BY ticker
            ) latest ON s.ticker = latest.ticker AND s.score_date = latest.max_date
            WHERE s.origin = :origin_join
            ORDER BY s.ticker ASC
        ');
        $stmt->execute([
            ':origin'      => self::ORIGIN_RESCORE,
            ':origin_join' => self::ORIGIN_RESCORE,
        ]);
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
             WHERE ticker = ? AND score_date >= ? AND origin = ?
             ORDER BY score_date ASC'
        );
        $stmt->execute([$ticker, $since->format('Y-m-d'), self::ORIGIN_RESCORE]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * CVS Swing trajectory series for a ticker since a given date (Phase 8, slice 1).
     *
     * Returns one clean headline line: filtered to ORIGIN_RESCORE AND the live
     * model_version. The version filter is load-bearing — without it the JOIN-free
     * read would return the shadow rows (3.1/3.2) that coexist for the same
     * (ticker, score_date), producing multiple points per day (lessons.md: "Filtruj
     * shadow model_version przy każdym odczycie"). origin filter keeps the
     * calibration corpus out (corpus rows share live model_version values).
     *
     * @return array<int, array<string, mixed>> rows with score_date, cvs_swing — oldest first
     */
    public function findTrajectory(string $ticker, DateTimeImmutable $since, string $modelVersion): array
    {
        $stmt = $this->db->prepare(
            'SELECT score_date, cvs_swing FROM cvs_snapshots
             WHERE ticker = ? AND score_date >= ? AND origin = ? AND model_version = ?
             ORDER BY score_date ASC'
        );
        $stmt->execute([strtoupper($ticker), $since->format('Y-m-d'), self::ORIGIN_RESCORE, $modelVersion]);
        return $stmt->fetchAll() ?: [];
    }
}
