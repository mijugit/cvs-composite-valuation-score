<?php

declare(strict_types=1);

namespace CVS\LlmGptLuna;

use PDO;

/**
 * Read-only access to all LLM_GPT_Luna_Wallet tables.
 *
 * Pure SELECT methods — no side effects, no transactions. Structural clone of
 * CVS\LlmGemini\LlmGeminiRepository (change: llm-gpt-luna-wallet) — same
 * method surface, tables renamed llm_gpt_luna_*.
 */
class LlmGptLunaRepository
{
    public function __construct(private readonly PDO $db) {}

    /**
     * Returns the single llm_gpt_luna_state row.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when the wallet has not been initialized (no row in llm_gpt_luna_state)
     */
    public function getCurrentState(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM llm_gpt_luna_state LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('LLM_GPT_Luna_Wallet not initialized: llm_gpt_luna_state is empty.');
        }

        return $row;
    }

    /**
     * Returns all current holdings with quantity > 0, ordered by ticker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCurrentHoldings(): array
    {
        $stmt = $this->db->prepare(
            'SELECT ticker, quantity, avg_entry_price, updated_at
             FROM llm_gpt_luna_holdings
             WHERE quantity > 0
             ORDER BY ticker ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns holdings enriched with the latest snapshot price from cvs_snapshots.
     *
     * Uses LEFT JOIN so holdings without a matching snapshot still appear,
     * falling back to avg_entry_price (price_is_snapshot = false).
     *
     * IMPORTANT: filters by model_version and origin='RESCORE' to avoid shadow
     * rows (lesson: commit 442689d — unfiltered JOIN returns duplicate rows when
     * shadow scoring writes multiple rows per ticker/date).
     *
     * @return array<int, array{ticker: string, quantity: int, avg_entry_price: float, live_price: float, price_is_snapshot: bool, value_usd: float, updated_at: string, company_name: ?string, cvs_swing: ?float, cvs_fund: ?float, reco_swing: ?string, reco_fund: ?string}>
     */
    public function getCurrentHoldingsWithPrice(string $liveModelVersion): array
    {
        $sql = "
            SELECT
                h.ticker,
                h.quantity,
                h.avg_entry_price,
                h.updated_at,
                COALESCE(s.price_at_snapshot, h.avg_entry_price) AS live_price,
                (s.price_at_snapshot IS NOT NULL)                 AS price_is_snapshot,
                s.company_name,
                s.cvs_swing,
                s.cvs_fund,
                s.reco_swing,
                s.reco_fund
            FROM llm_gpt_luna_holdings h
            LEFT JOIN cvs_snapshots s
                ON  s.ticker        = h.ticker
                AND s.model_version = ?
                AND s.origin        = 'RESCORE'
                AND s.scored_at     = (
                    SELECT MAX(s2.scored_at)
                    FROM cvs_snapshots s2
                    WHERE s2.ticker        = h.ticker
                      AND s2.model_version = ?
                      AND s2.origin        = 'RESCORE'
                )
            WHERE h.quantity > 0
            ORDER BY h.ticker ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$liveModelVersion, $liveModelVersion]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $livePrice = (float) $row['live_price'];
            $quantity  = (int)   $row['quantity'];
            return [
                'ticker'           => (string) $row['ticker'],
                'quantity'         => $quantity,
                'avg_entry_price'  => (float) $row['avg_entry_price'],
                'live_price'       => $livePrice,
                'price_is_snapshot'=> (bool) $row['price_is_snapshot'],
                'value_usd'        => round($quantity * $livePrice, 2),
                'updated_at'       => (string) $row['updated_at'],
                'company_name'     => $row['company_name'] ?? null,
                'cvs_swing'        => isset($row['cvs_swing']) ? (float) $row['cvs_swing'] : null,
                'cvs_fund'         => isset($row['cvs_fund'])  ? (float) $row['cvs_fund']  : null,
                'reco_swing'       => $row['reco_swing'] ?? null,
                'reco_fund'        => $row['reco_fund']  ?? null,
            ];
        }, $rows);
    }

    /**
     * Returns the most recent llm_gpt_luna_cycle row, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestCycle(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM llm_gpt_luna_cycle ORDER BY cycle_date DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Returns the last $limit cycles that have a non-null legend, newest first —
     * the "memory" the model reads back on each subsequent cycle, and the history
     * shown on the /llm-gpt-luna page.
     *
     * @return array<int, array{cycle_date: string, legend: string}>
     */
    public function getLegendHistory(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT cycle_date, legend
             FROM llm_gpt_luna_cycle
             WHERE legend IS NOT NULL
             ORDER BY cycle_date DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'cycle_date' => (string) $row['cycle_date'],
            'legend'     => (string) $row['legend'],
        ], $rows);
    }
}
