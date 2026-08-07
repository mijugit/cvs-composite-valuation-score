-- Migration 035: Create LLM_Free_Wallet tables
--
-- Second, fully autonomous LLM-driven paper portfolio (change: llm-free-wallet).
-- Kept fully separate from the existing LLM portfolio module (portfolio_*,
-- rebalance_cycle, src/Portfolio/*) — different namespace (CVS\LlmFree), different
-- tables (llm_free_*). No foreign key to any portfolio_*/rebalance_cycle/
-- ai_analyses/ai_critical_reviews table — read access to those is through their
-- existing repository classes, never a join (same isolation rule as migration 029's
-- Lab module).
--
-- Consolidates what the original Portfolio module built incrementally across
-- migrations 024-028 into one file, plus two additions this module needs from
-- day one: `legend` (the model's self-authored, persistent investment thesis —
-- one entry per cycle, 1:1 relationship, so a column not a side table, mirroring
-- how migration 027 added llm_decision_json/llm_raw_response directly onto
-- rebalance_cycle) and `tokens_input`/`tokens_output` (usage logging for the
-- ~$0.50/cycle cost guardrail).
--
-- Four additive tables:
--   llm_free_cycle       — one row per daily rebalance attempt (created first: the
--                          other tables' FK/audit trail reference it)
--   llm_free_state       — global wallet cash singleton
--   llm_free_holdings    — current positions (one row per ticker)
--   llm_free_transactions — immutable per-cycle decision audit log

-- ---------------------------------------------------------------------------
-- llm_free_cycle: one row per daily rebalance attempt
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_free_cycle (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cycle_date          DATE            NOT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'started' COMMENT 'started | completed | failed | llm_failed',
    attempt_count       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    started_at          DATETIME        NOT NULL,
    finished_at         DATETIME        NULL,
    cash_before         DECIMAL(12,2)   NULL,
    cash_after          DECIMAL(12,2)   NULL,
    portfolio_value_usd DECIMAL(12,2)   NULL COMMENT 'Mark-to-market at cycle end — never cost basis',
    executed_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes               TEXT            NULL,
    retry_count         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    llm_raw_response    TEXT            NULL,
    llm_failure_kind    VARCHAR(32)     NULL,
    llm_decision_json   TEXT            NULL,
    legend              TEXT            NULL COMMENT 'Model-authored investment thesis for this cycle — read back as context on subsequent cycles',
    tokens_input        INT UNSIGNED    NOT NULL DEFAULT 0,
    tokens_output       INT UNSIGNED    NOT NULL DEFAULT 0,
    UNIQUE KEY uq_cycle_date (cycle_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- llm_free_state: global wallet cash singleton
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_free_state (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cash            DECIMAL(12,2)   NOT NULL COMMENT 'Available cash after all executed transactions',
    initial_capital DECIMAL(12,2)   NOT NULL COMMENT 'Starting capital — never mutated after seed',
    updated_at      DATETIME        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: one row only, same starting capital as the baseline wallet — a different
-- amount would break the comparison the whole experiment exists to make.
-- INSERT IGNORE makes re-running this migration on an already-seeded database safe.
INSERT IGNORE INTO llm_free_state (id, cash, initial_capital, updated_at)
VALUES (1, 10000.00, 10000.00, NOW());

-- ---------------------------------------------------------------------------
-- llm_free_holdings: current positions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_free_holdings (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    ticker          VARCHAR(20)     NOT NULL,
    quantity        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Whole shares only',
    avg_entry_price DECIMAL(12,4)   NOT NULL COMMENT 'Weighted average across all BUYs for this ticker',
    updated_at      DATETIME        NOT NULL,
    UNIQUE KEY uq_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- llm_free_transactions: immutable per-cycle decision audit log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_free_transactions (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cycle_id    INT UNSIGNED    NOT NULL COMMENT 'FK to llm_free_cycle.id',
    ticker      VARCHAR(20)     NOT NULL,
    action      VARCHAR(20)     NOT NULL COMMENT 'BUY | SELL | HOLD | NO_ACTION | SKIP_INSUFFICIENT_CASH | SKIP_INSUFFICIENT_QUANTITY',
    quantity    INT UNSIGNED    NULL     COMMENT 'NULL for HOLD / NO_ACTION / SKIP',
    price_usd   DECIMAL(12,4)   NULL     COMMENT 'NULL for HOLD / NO_ACTION / SKIP',
    cash_before DECIMAL(12,2)   NULL     COMMENT 'Cash immediately before this transaction',
    cash_after  DECIMAL(12,2)   NULL     COMMENT 'Cash immediately after this transaction',
    status      VARCHAR(32)     NOT NULL COMMENT 'executed | skipped_insufficient_cash | skipped_insufficient_quantity | no_action | hold',
    reason      TEXT            NULL     COMMENT 'Model-stated reasoning for this specific decision',
    executed_at DATETIME        NOT NULL,
    CONSTRAINT fk_llm_free_tx_cycle FOREIGN KEY (cycle_id) REFERENCES llm_free_cycle (id),
    INDEX idx_llm_free_tx_cycle_id (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
