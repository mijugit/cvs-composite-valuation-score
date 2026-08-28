<?php

declare(strict_types=1);

namespace CVS\Lab;

use CVS\Core\Database;
use CVS\TrackRecord\CvsSnapshotRepository;
use PDO;
use PDOException;
use Throwable;

/**
 * Persistence for the Lab experimental portfolios (change: cvs-experimental-portfolios).
 *
 * Tables: lab_portfolio (registry/cash), lab_position (holdings), lab_trade
 * (immutable trade log, filled + pending), lab_nav (daily NAV series).
 * DDL: database/migrations/029_create_lab_tables.sql
 *
 * Accepts optional PDO injection for test isolation (SQLite in-memory), same
 * convention as PriceAlertRepository / PortfolioRepository. Upserts follow the
 * insert-then-catch-duplicate pattern used across the codebase (portable across
 * MySQL's "Duplicate" and SQLite's "UNIQUE constraint" error messages).
 */
class LabRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Portfolio registry
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function getPortfolio(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM lab_portfolio WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Registers a portfolio variant if it does not already exist. Idempotent —
     * a duplicate call (e.g. every tick re-declaring the 7 variants) is a no-op.
     */
    public function initPortfolio(string $code, string $name, string $experimentVersion, float $initialCashUsd): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO lab_portfolio (code, name, experiment_version, started_at, cash)
                 VALUES (?, ?, ?, NULL, ?)'
            )->execute([$code, $name, $experimentVersion, $initialCashUsd]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log(sprintf('LabRepository::initPortfolio failed for %s: %s', $code, $msg));
            }
            // Duplicate = already registered — no-op (idempotent).
        }
    }

    /** Stamps the seed date the first time a portfolio is rebalanced; a no-op afterwards. */
    public function markStarted(string $code, string $date): void
    {
        $this->db->prepare('UPDATE lab_portfolio SET started_at = ? WHERE code = ? AND started_at IS NULL')
            ->execute([$date, $code]);
    }

    // ------------------------------------------------------------------
    // Candidate selection (reads cvs_snapshots)
    // ------------------------------------------------------------------

    /**
     * Candidates for one rebalance date: live model_version, user-facing origin
     * only (lessons.md: filter shadow rows), quality-gate passed, US tickers only
     * (no '.' in the symbol — excludes .WA/.KS etc., per koncepcja.md's benchmark
     * choice), with a usable price.
     *
     * @return array<int, array{ticker: string, cvs_swing: float, cvs_fund: float|null, price: float, sector: string|null}>
     */
    public function findCandidatesForDate(string $date, string $modelVersion): array
    {
        $stmt = $this->db->prepare(
            "SELECT ticker, cvs_swing, cvs_fund, price_at_snapshot AS price, sector
             FROM cvs_snapshots
             WHERE score_date = ? AND model_version = ? AND origin = ?
               AND quality_gate = 1
               AND cvs_swing IS NOT NULL
               AND price_at_snapshot IS NOT NULL
               AND ticker NOT LIKE '%.%'"
        );
        $stmt->execute([$date, $modelVersion, CvsSnapshotRepository::ORIGIN_RESCORE]);

        return array_map(static function (array $r): array {
            return [
                'ticker'    => strtoupper((string) $r['ticker']),
                'cvs_swing' => (float) $r['cvs_swing'],
                'cvs_fund'  => $r['cvs_fund'] !== null ? (float) $r['cvs_fund'] : null,
                'price'     => (float) $r['price'],
                'sector'    => $r['sector'] !== null ? (string) $r['sector'] : null,
            ];
        }, $stmt->fetchAll() ?: []);
    }

    /**
     * True when a trade with this (portfolio, date, reason) already exists —
     * regardless of filled/pending status. Guards LabTickService::doRebalance
     * against re-deciding the same rebalance twice if the tick re-runs same-day:
     * for 'filled' (close-execution) trades this is naturally idempotent anyway
     * (positions already converged), but for 'pending' (P2 open-execution)
     * trades nothing mutates state until the fill the next day, so without this
     * guard a same-day re-run would queue a duplicate pending trade.
     */
    public function hasTradeToday(string $code, string $date, string $reason): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM lab_trade WHERE portfolio_code = ? AND trade_date = ? AND reason = ? LIMIT 1'
        );
        $stmt->execute([$code, $date, $reason]);
        return $stmt->fetchColumn() !== false;
    }

    // ------------------------------------------------------------------
    // Positions
    // ------------------------------------------------------------------

    /** @return array<string, array{quantity: float, avg_entry_price: float, entry_date: string}> ticker => position */
    public function getPositions(string $code): array
    {
        $stmt = $this->db->prepare(
            'SELECT ticker, quantity, avg_entry_price, entry_date FROM lab_position WHERE portfolio_code = ?'
        );
        $stmt->execute([$code]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[strtoupper((string) $r['ticker'])] = [
                'quantity'        => (float) $r['quantity'],
                'avg_entry_price' => (float) $r['avg_entry_price'],
                'entry_date'      => (string) $r['entry_date'],
            ];
        }
        return $out;
    }

    /** @return array{quantity: float, avg_entry_price: float, entry_date: string}|null */
    private function getPosition(string $code, string $ticker): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT quantity, avg_entry_price, entry_date FROM lab_position WHERE portfolio_code = ? AND ticker = ?'
        );
        $stmt->execute([$code, $ticker]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'quantity'        => (float) $row['quantity'],
            'avg_entry_price' => (float) $row['avg_entry_price'],
            'entry_date'      => (string) $row['entry_date'],
        ];
    }

    private function upsertPosition(string $code, string $ticker, float $quantity, float $avgEntryPrice, string $entryDate): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO lab_position (portfolio_code, ticker, quantity, avg_entry_price, entry_date)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$code, $ticker, $quantity, $avgEntryPrice, $entryDate]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log(sprintf('LabRepository::upsertPosition failed for %s/%s: %s', $code, $ticker, $msg));
                return;
            }
            $this->db->prepare(
                'UPDATE lab_position SET quantity = ?, avg_entry_price = ? WHERE portfolio_code = ? AND ticker = ?'
            )->execute([$quantity, $avgEntryPrice, $code, $ticker]);
        }
    }

    private function deletePosition(string $code, string $ticker): void
    {
        $this->db->prepare('DELETE FROM lab_position WHERE portfolio_code = ? AND ticker = ?')
            ->execute([$code, $ticker]);
    }

    private function adjustCash(string $code, float $delta): void
    {
        $this->db->prepare('UPDATE lab_portfolio SET cash = cash + ? WHERE code = ?')
            ->execute([$delta, $code]);
    }

    // ------------------------------------------------------------------
    // Trades
    // ------------------------------------------------------------------

    /**
     * Persists one trade from LabEngine::planRebalance()/applyStops() and, when
     * $status is 'filled', atomically applies it to lab_position + lab_portfolio.cash.
     *
     * When $status is 'pending' (P2 open-execution, decided today, filled at
     * tomorrow's open), the trade row is stored with price = NULL regardless of
     * the estimate LabEngine used for sizing, and NO position/cash mutation
     * happens yet — that is LabTickService's job the following day, via
     * fillPendingTrade(), once the real open price is known.
     *
     * @param array{ticker: string, action: string, quantity: float, price: float, fee: float, reason: string} $trade
     */
    public function applyTrade(string $code, string $tradeDate, array $trade, string $status = 'filled'): void
    {
        $ticker   = strtoupper((string) $trade['ticker']);
        $action   = strtoupper((string) $trade['action']);
        $quantity = (float) $trade['quantity'];
        $price    = (float) $trade['price'];
        $fee      = (float) $trade['fee'];
        $reason   = $trade['reason'];

        $storedPrice = $status === 'pending' ? null : $price;

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO lab_trade (portfolio_code, trade_date, ticker, action, quantity, price, fee, reason, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$code, $tradeDate, $ticker, $action, $quantity, $storedPrice, $fee, $reason, $status, date('Y-m-d H:i:s')]);

            if ($status === 'filled') {
                $this->applyFilledTradeToState($code, $ticker, $action, $quantity, $price, $fee, $tradeDate);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log(sprintf('LabRepository::applyTrade failed for %s/%s: %s', $code, $ticker, $e->getMessage()));
        }
    }

    private function applyFilledTradeToState(string $code, string $ticker, string $action, float $quantity, float $price, float $fee, string $tradeDate): void
    {
        $pos = $this->getPosition($code, $ticker);

        if ($action === 'BUY') {
            $cost        = $quantity * $price + $fee;
            $existingQty = $pos['quantity']        ?? 0.0;
            $existingAvg = $pos['avg_entry_price']  ?? 0.0;
            $newQty      = $existingQty + $quantity;
            $newAvg      = $newQty > 0.0 ? (($existingQty * $existingAvg) + ($quantity * $price)) / $newQty : $price;

            $this->upsertPosition($code, $ticker, $newQty, $newAvg, $pos['entry_date'] ?? $tradeDate);
            $this->adjustCash($code, -$cost);
            return;
        }

        if ($action === 'SELL') {
            $proceeds    = $quantity * $price - $fee;
            $existingQty = $pos['quantity'] ?? 0.0;
            $newQty      = max(0.0, $existingQty - $quantity);

            if ($newQty <= 1e-9) {
                $this->deletePosition($code, $ticker);
            } else {
                $this->upsertPosition($code, $ticker, $newQty, $pos['avg_entry_price'] ?? $price, $pos['entry_date'] ?? $tradeDate);
            }
            $this->adjustCash($code, $proceeds);
        }
    }

    /**
     * Resolves a pending (P2 open-execution) trade the day after it was decided:
     * stamps the real fill price + recomputed fee, flips status to 'filled', and
     * applies it to the position/cash — all atomically. Idempotent: a trade
     * already filled (or a nonexistent id) is a silent no-op, so LabTickService
     * can retry a tick without double-applying.
     */
    public function fillPendingTrade(int $tradeId, float $price, float $fee): void
    {
        $stmt = $this->db->prepare("SELECT * FROM lab_trade WHERE id = ? AND status = 'pending'");
        $stmt->execute([$tradeId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE lab_trade SET price = ?, fee = ?, status = ? WHERE id = ?')
                ->execute([$price, $fee, 'filled', $tradeId]);

            $this->applyFilledTradeToState(
                (string) $row['portfolio_code'],
                strtoupper((string) $row['ticker']),
                strtoupper((string) $row['action']),
                (float) $row['quantity'],
                $price,
                $fee,
                (string) $row['trade_date']
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log(sprintf('LabRepository::fillPendingTrade failed for trade #%d: %s', $tradeId, $e->getMessage()));
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function findPendingTrades(string $code): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM lab_trade WHERE portfolio_code = ? AND status = 'pending' ORDER BY id ASC"
        );
        $stmt->execute([$code]);
        return $stmt->fetchAll() ?: [];
    }

    // ------------------------------------------------------------------
    // NAV
    // ------------------------------------------------------------------

    /** Idempotent — a second call for the same (portfolio, date) updates the row in place. */
    public function upsertNav(string $code, string $date, float $nav, float $cash, float $positionsValue): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO lab_nav (portfolio_code, nav_date, nav, cash, positions_value) VALUES (?, ?, ?, ?, ?)'
            )->execute([$code, $date, $nav, $cash, $positionsValue]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log(sprintf('LabRepository::upsertNav failed for %s/%s: %s', $code, $date, $msg));
                return;
            }
            $this->db->prepare(
                'UPDATE lab_nav SET nav = ?, cash = ?, positions_value = ? WHERE portfolio_code = ? AND nav_date = ?'
            )->execute([$nav, $cash, $positionsValue, $code, $date]);
        }
    }

    /**
     * Every portfolio's NAV series, oldest first — feeds the /lab chart.
     *
     * @return array<string, list<array{date: string, nav: float}>> portfolio_code => series
     */
    public function getNavSeries(): array
    {
        $stmt = $this->db->query('SELECT portfolio_code, nav_date, nav FROM lab_nav ORDER BY portfolio_code ASC, nav_date ASC');
        $rows = $stmt !== false ? ($stmt->fetchAll() ?: []) : [];

        $out = [];
        foreach ($rows as $r) {
            $code = (string) $r['portfolio_code'];
            $out[$code][] = ['date' => (string) $r['nav_date'], 'nav' => (float) $r['nav']];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // /lab view support
    // ------------------------------------------------------------------

    /**
     * All registered Lab portfolios, keyed by code — feeds the /lab cards.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllPortfolios(): array
    {
        $stmt = $this->db->query('SELECT * FROM lab_portfolio ORDER BY code ASC');
        $rows = $stmt !== false ? ($stmt->fetchAll() ?: []) : [];

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['code']] = $r;
        }
        return $out;
    }

    /**
     * Fee total and filled-trade count per portfolio — feeds the /lab metrics table.
     *
     * @return array<string, array{fee_total: float, tx_count: int}>
     */
    public function getTradeStats(): array
    {
        $stmt = $this->db->query(
            "SELECT portfolio_code, COALESCE(SUM(fee), 0) AS fee_total, COUNT(*) AS tx_count
             FROM lab_trade WHERE status = 'filled' GROUP BY portfolio_code"
        );
        $rows = $stmt !== false ? ($stmt->fetchAll() ?: []) : [];

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['portfolio_code']] = [
                'fee_total' => (float) $r['fee_total'],
                'tx_count'  => (int) $r['tx_count'],
            ];
        }
        return $out;
    }

    /**
     * Read-only view into the (separate, Portfolio-module) rebalance_cycle table —
     * the existing LLM-driven portfolio's value series, shown on /lab purely as a
     * "for reference" line. The Lab module never writes to this table and never
     * touches CVS\Portfolio\* classes; this is the one sanctioned read.
     *
     * @return list<array{date: string, value: float}>
     */
    public function getLlmValueSeries(string $sinceDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT cycle_date, portfolio_value_usd FROM rebalance_cycle
             WHERE status = 'completed' AND portfolio_value_usd IS NOT NULL AND cycle_date >= ?
             ORDER BY cycle_date ASC"
        );
        $stmt->execute([$sinceDate]);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['date' => (string) $r['cycle_date'], 'value' => (float) $r['portfolio_value_usd']];
        }
        return $out;
    }

    /**
     * Same read-only, for-reference pattern as getLlmValueSeries(), against the
     * (separate) LLM Free wallet's llm_free_cycle table (change: llm-lab-wallets —
     * literal structural clone, identical columns, no touch of CVS\LlmFree\*).
     *
     * @return list<array{date: string, value: float}>
     */
    public function getLlmFreeValueSeries(string $sinceDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT cycle_date, portfolio_value_usd FROM llm_free_cycle
             WHERE status = 'completed' AND portfolio_value_usd IS NOT NULL AND cycle_date >= ?
             ORDER BY cycle_date ASC"
        );
        $stmt->execute([$sinceDate]);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['date' => (string) $r['cycle_date'], 'value' => (float) $r['portfolio_value_usd']];
        }
        return $out;
    }

    /**
     * Same read-only, for-reference pattern as getLlmValueSeries(), against the
     * (separate) LLM Gemini wallet's llm_gemini_cycle table (change: llm-lab-wallets —
     * literal structural clone, identical columns, no touch of CVS\LlmGemini\*).
     *
     * @return list<array{date: string, value: float}>
     */
    public function getLlmGeminiValueSeries(string $sinceDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT cycle_date, portfolio_value_usd FROM llm_gemini_cycle
             WHERE status = 'completed' AND portfolio_value_usd IS NOT NULL AND cycle_date >= ?
             ORDER BY cycle_date ASC"
        );
        $stmt->execute([$sinceDate]);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['date' => (string) $r['cycle_date'], 'value' => (float) $r['portfolio_value_usd']];
        }
        return $out;
    }

    /**
     * Same read-only, for-reference pattern as getLlmValueSeries(), against the
     * (separate) LLM GPT Luna wallet's llm_gpt_luna_cycle table (change:
     * llm-lab-wallets — literal structural clone, identical columns, no touch of
     * CVS\LlmGptLuna\*).
     *
     * @return list<array{date: string, value: float}>
     */
    public function getLlmGptLunaValueSeries(string $sinceDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT cycle_date, portfolio_value_usd FROM llm_gpt_luna_cycle
             WHERE status = 'completed' AND portfolio_value_usd IS NOT NULL AND cycle_date >= ?
             ORDER BY cycle_date ASC"
        );
        $stmt->execute([$sinceDate]);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['date' => (string) $r['cycle_date'], 'value' => (float) $r['portfolio_value_usd']];
        }
        return $out;
    }
}
