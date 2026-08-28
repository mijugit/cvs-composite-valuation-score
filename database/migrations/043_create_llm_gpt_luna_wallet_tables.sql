-- Migration 043: Create LLM_GPT_Luna_Wallet tables
--
-- Fourth autonomous LLM-driven paper portfolio (change: llm-gpt-luna-wallet).
-- Literal structural clone of migration 038 (LLM_Gemini_Wallet) — same
-- mechanism, same starting parameters, different executing LLM (GPT "Luna"
-- flavor instead of Gemini). Kept fully separate from all sibling modules —
-- different namespace (CVS\LlmGptLuna), different tables (llm_gpt_luna_*).
-- No foreign key to any portfolio_*/rebalance_cycle/llm_free_*/llm_gemini_*
-- table — read access to those is through their existing repository classes,
-- never a join (same isolation rule as migrations 029/035/038).
--
-- Four additive tables (identical shape to 035/038, prefix llm_gpt_luna_
-- instead of llm_free_/llm_gemini_):
--   llm_gpt_luna_cycle       — one row per daily rebalance attempt
--   llm_gpt_luna_state       — global wallet cash singleton
--   llm_gpt_luna_holdings    — current positions (one row per ticker)
--   llm_gpt_luna_transactions — immutable per-cycle decision audit log

-- ---------------------------------------------------------------------------
-- llm_gpt_luna_cycle: one row per daily rebalance attempt
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_gpt_luna_cycle (
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
    tokens_output        INT UNSIGNED    NOT NULL DEFAULT 0,
    UNIQUE KEY uq_cycle_date (cycle_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- llm_gpt_luna_state: global wallet cash singleton
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_gpt_luna_state (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cash            DECIMAL(12,2)   NOT NULL COMMENT 'Available cash after all executed transactions',
    initial_capital DECIMAL(12,2)   NOT NULL COMMENT 'Starting capital — never mutated after seed',
    updated_at      DATETIME        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: one row only, same starting capital as the other three wallets — a
-- different amount would break the comparison the whole experiment exists to
-- make. INSERT IGNORE makes re-running this migration on an already-seeded
-- database safe.
INSERT IGNORE INTO llm_gpt_luna_state (id, cash, initial_capital, updated_at)
VALUES (1, 10000.00, 10000.00, NOW());

-- ---------------------------------------------------------------------------
-- llm_gpt_luna_holdings: current positions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_gpt_luna_holdings (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    ticker          VARCHAR(20)     NOT NULL,
    quantity        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Whole shares only',
    avg_entry_price DECIMAL(12,4)   NOT NULL COMMENT 'Weighted average across all BUYs for this ticker',
    updated_at      DATETIME        NOT NULL,
    UNIQUE KEY uq_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- llm_gpt_luna_transactions: immutable per-cycle decision audit log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS llm_gpt_luna_transactions (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cycle_id    INT UNSIGNED    NOT NULL COMMENT 'FK to llm_gpt_luna_cycle.id',
    ticker      VARCHAR(20)     NOT NULL,
    action      VARCHAR(20)     NOT NULL COMMENT 'BUY | SELL | HOLD | NO_ACTION | SKIP_INSUFFICIENT_CASH | SKIP_INSUFFICIENT_QUANTITY',
    quantity    INT UNSIGNED    NULL     COMMENT 'NULL for HOLD / NO_ACTION / SKIP',
    price_usd   DECIMAL(12,4)   NULL     COMMENT 'NULL for HOLD / NO_ACTION / SKIP',
    cash_before DECIMAL(12,2)   NULL     COMMENT 'Cash immediately before this transaction',
    cash_after  DECIMAL(12,2)   NULL     COMMENT 'Cash immediately after this transaction',
    status      VARCHAR(32)     NOT NULL COMMENT 'executed | skipped_insufficient_cash | skipped_insufficient_quantity | no_action | hold',
    reason      TEXT            NULL     COMMENT 'Model-stated reasoning for this specific decision',
    executed_at DATETIME        NOT NULL,
    CONSTRAINT fk_llm_gpt_luna_tx_cycle FOREIGN KEY (cycle_id) REFERENCES llm_gpt_luna_cycle (id),
    INDEX idx_llm_gpt_luna_tx_cycle_id (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
