<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * Alert preferences and deduplication persistence.
 *
 * Tables: user_alert_settings, user_alert_ticker, alert_sent
 * DDL: database/migrations/011_create_alert_tables.sql
 */
class AlertRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    // ------------------------------------------------------------------
    // Global preference
    // ------------------------------------------------------------------

    /**
     * Whether the user has globally enabled alerts (default false = OFF).
     */
    public function isGlobalEnabled(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT enabled FROM user_alert_settings WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row !== false && (bool) $row['enabled'];
    }

    /**
     * Set (upsert) the global alert enabled flag for a user.
     */
    public function setGlobalEnabled(int $userId, bool $enabled): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO user_alert_settings (user_id, enabled, updated_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$userId, $enabled ? 1 : 0, $now]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');
            if (!$isDup) {
                error_log('AlertRepository::setGlobalEnabled failed: ' . $msg);
                return;
            }
            $upd = $this->db->prepare(
                'UPDATE user_alert_settings SET enabled = ?, updated_at = ? WHERE user_id = ?'
            );
            $upd->execute([$enabled ? 1 : 0, $now, $userId]);
        }
    }

    // ------------------------------------------------------------------
    // Per-ticker preference
    // ------------------------------------------------------------------

    /**
     * Whether alerts for a specific ticker are disabled (absence = enabled).
     */
    public function isTickerDisabled(int $userId, string $ticker): bool
    {
        $stmt = $this->db->prepare(
            'SELECT disabled FROM user_alert_ticker WHERE user_id = ? AND ticker = ? LIMIT 1'
        );
        $stmt->execute([$userId, strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false && (bool) $row['disabled'];
    }

    /**
     * Enable or disable alerts for a specific ticker.
     * disabled=true  → add/update row (ticker silenced)
     * disabled=false → remove row (ticker re-enabled)
     */
    public function setTickerDisabled(int $userId, string $ticker, bool $disabled): void
    {
        $ticker = strtoupper($ticker);
        if (!$disabled) {
            $this->db->prepare(
                'DELETE FROM user_alert_ticker WHERE user_id = ? AND ticker = ?'
            )->execute([$userId, $ticker]);
            return;
        }
        try {
            $this->db->prepare(
                'INSERT INTO user_alert_ticker (user_id, ticker, disabled) VALUES (?, ?, 1)'
            )->execute([$userId, $ticker]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint')) {
                return; // Already disabled
            }
            error_log('AlertRepository::setTickerDisabled failed: ' . $msg);
        }
    }

    // ------------------------------------------------------------------
    // Deduplication
    // ------------------------------------------------------------------

    /**
     * Last alert state for a (user, ticker) pair, or null if never alerted.
     *
     * @return array{last_reco: string|null, last_signal: string|null}|null
     */
    public function getLastSent(int $userId, string $ticker): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT last_reco, last_signal FROM alert_sent WHERE user_id = ? AND ticker = ? LIMIT 1'
        );
        $stmt->execute([$userId, strtoupper($ticker)]);
        $row = $stmt->fetch();
        return $row !== false ? [
            'last_reco'   => $row['last_reco']   !== false ? $row['last_reco']   : null,
            'last_signal' => $row['last_signal']  !== false ? $row['last_signal'] : null,
        ] : null;
    }

    /**
     * Upsert the last sent state after an alert is dispatched.
     */
    public function upsertSent(int $userId, string $ticker, ?string $reco, ?string $signal): void
    {
        $ticker = strtoupper($ticker);
        $now    = date('Y-m-d H:i:s');
        try {
            $this->db->prepare(
                'INSERT INTO alert_sent (user_id, ticker, last_reco, last_signal, sent_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$userId, $ticker, $reco, $signal, $now]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');
            if (!$isDup) {
                error_log('AlertRepository::upsertSent failed: ' . $msg);
                return;
            }
            $this->db->prepare(
                'UPDATE alert_sent SET last_reco = ?, last_signal = ?, sent_at = ?
                 WHERE user_id = ? AND ticker = ?'
            )->execute([$reco, $signal, $now, $userId, $ticker]);
        }
    }

    // ------------------------------------------------------------------
    // User discovery
    // ------------------------------------------------------------------

    /**
     * User IDs who have global alerts enabled AND are watching this ticker.
     *
     * @return int[]
     */
    public function findUsersWatchingTicker(string $ticker): array
    {
        $stmt = $this->db->prepare('
            SELECT DISTINCT w.user_id
            FROM watchlist w
            INNER JOIN user_alert_settings s ON s.user_id = w.user_id AND s.enabled = 1
            WHERE w.ticker = ?
        ');
        $stmt->execute([strtoupper($ticker)]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
