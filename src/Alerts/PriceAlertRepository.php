<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * Persistence for price-threshold alerts (Phase 8, slice 3).
 *
 * Tables: ticker_zone (per-ticker ATR zone cache, written by bin/rescore.php),
 *         price_alert (per-user enable flag + hysteresis state).
 * DDL: database/migrations/023_create_price_alert_tables.sql
 *
 * Kept separate from AlertRepository (reco/signal alerts) on purpose — the two
 * alert types have different models (reco state-change vs price-zone crossing).
 */
class PriceAlertRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Zone cache (per ticker)
    // ------------------------------------------------------------------

    /**
     * Upsert the latest ATR zone for a ticker (written by the daily rescore).
     *
     * @param array{zone_low: float, zone_high: float, stop_swing: float|null,
     *              stop_fund: float|null, source: string|null} $zone
     */
    public function upsertZone(string $ticker, array $zone, ?float $fxRateToUsd): void
    {
        $ticker = strtoupper($ticker);
        $now    = date('Y-m-d H:i:s');
        $params = [
            $ticker,
            $zone['zone_low'], $zone['zone_high'],
            $zone['stop_swing'] ?? null, $zone['stop_fund'] ?? null,
            $fxRateToUsd, $zone['source'] ?? null, $now,
        ];
        try {
            $this->db->prepare(
                'INSERT INTO ticker_zone
                 (ticker, zone_low, zone_high, stop_swing, stop_fund, fx_rate_to_usd, source, computed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($params);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log('PriceAlertRepository::upsertZone failed: ' . $msg);
                return;
            }
            $this->db->prepare(
                'UPDATE ticker_zone
                 SET zone_low = ?, zone_high = ?, stop_swing = ?, stop_fund = ?,
                     fx_rate_to_usd = ?, source = ?, computed_at = ?
                 WHERE ticker = ?'
            )->execute([
                $zone['zone_low'], $zone['zone_high'],
                $zone['stop_swing'] ?? null, $zone['stop_fund'] ?? null,
                $fxRateToUsd, $zone['source'] ?? null, $now, $ticker,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    public function findZone(string $ticker): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ticker_zone WHERE ticker = ? LIMIT 1');
        $stmt->execute([strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    // ------------------------------------------------------------------
    // Per-user alert enable
    // ------------------------------------------------------------------

    public function isEnabled(int $userId, string $ticker): bool
    {
        $stmt = $this->db->prepare(
            'SELECT enabled FROM price_alert WHERE user_id = ? AND ticker = ? LIMIT 1'
        );
        $stmt->execute([$userId, strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false && (bool) $row['enabled'];
    }

    /**
     * Enable or disable the price alert for a (user, ticker). Disabling keeps the
     * row (preserves hysteresis state) but flips enabled=0.
     */
    public function setEnabled(int $userId, string $ticker, bool $enabled): void
    {
        $ticker = strtoupper($ticker);
        try {
            $this->db->prepare(
                'INSERT INTO price_alert (user_id, ticker, enabled) VALUES (?, ?, ?)'
            )->execute([$userId, $ticker, $enabled ? 1 : 0]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'Duplicate') && !str_contains($msg, 'UNIQUE constraint')) {
                error_log('PriceAlertRepository::setEnabled failed: ' . $msg);
                return;
            }
            $this->db->prepare(
                'UPDATE price_alert SET enabled = ? WHERE user_id = ? AND ticker = ?'
            )->execute([$enabled ? 1 : 0, $userId, $ticker]);
        }
    }

    // ------------------------------------------------------------------
    // Cron support: active alerts + hysteresis state
    // ------------------------------------------------------------------

    /**
     * Active price alerts: enabled per-ticker AND the user's global alert switch is ON.
     *
     * @return array<int, array{user_id: int, ticker: string, last_state: string|null}>
     */
    public function findActiveAlerts(): array
    {
        $stmt = $this->db->query('
            SELECT p.user_id, p.ticker, p.last_state
            FROM price_alert p
            INNER JOIN user_alert_settings s ON s.user_id = p.user_id AND s.enabled = 1
            WHERE p.enabled = 1
        ');
        $rows = $stmt !== false ? ($stmt->fetchAll() ?: []) : [];
        return array_map(static fn(array $r): array => [
            'user_id'    => (int) $r['user_id'],
            'ticker'     => strtoupper((string) $r['ticker']),
            'last_state' => $r['last_state'] !== null ? (string) $r['last_state'] : null,
        ], $rows);
    }

    /** Update hysteresis state; pass $sent=true to also stamp last_sent_at. */
    public function updateState(int $userId, string $ticker, string $state, bool $sent): void
    {
        $ticker = strtoupper($ticker);
        if ($sent) {
            $this->db->prepare(
                'UPDATE price_alert SET last_state = ?, last_sent_at = ? WHERE user_id = ? AND ticker = ?'
            )->execute([$state, date('Y-m-d H:i:s'), $userId, $ticker]);
            return;
        }
        $this->db->prepare(
            'UPDATE price_alert SET last_state = ? WHERE user_id = ? AND ticker = ?'
        )->execute([$state, $userId, $ticker]);
    }
}
