-- Migration 026: Create virtual portfolio tables and seed initial capital
--
-- Creates three tables representing the global shared portfolio (FR-017):
--   portfolio_state      — mutable singleton: current cash + initial capital reference
--   portfolio_holdings   — current positions (one row per ticker, UNIQUE on ticker)
--   portfolio_transactions — immutable audit log (one row per decision per cycle)
--
-- The seed INSERT uses INSERT IGNORE so re-running this migration on an already-seeded
-- database is safe (duplicate insert is silently skipped). This is intentional.
--
-- Depends on: migration 024 (rebalance_cycle must exist for the FK in portfolio_transactions)

-- ---------------------------------------------------------------------------
-- portfolio_state: global portfolio cash singleton
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portfolio_state (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cash            DECIMAL(12,2)   NOT NULL COMMENT 'Available cash after all executed transactions',
    initial_capital DECIMAL(12,2)   NOT NULL COMMENT 'Starting capital — never mutated after seed',
    updated_at      DATETIME        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: one row only. INSERT IGNORE makes re-runs safe.
INSERT IGNORE INTO portfolio_state (id, cash, initial_capital, updated_at)
VALUES (1, 10000.00, 10000.00, NOW());

-- ---------------------------------------------------------------------------
-- portfolio_holdings: current positions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portfolio_holdings (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    ticker          VARCHAR(20)     NOT NULL,
    quantity        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Whole shares only',
    avg_entry_price DECIMAL(12,4)   NOT NULL COMMENT 'Weighted average across all BUYs for this ticker',
    updated_at      DATETIME        NOT NULL,
    UNIQUE KEY uq_ticker (ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- portfolio_transactions: immutable decision audit log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS portfolio_transactions (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    cycle_id    INT UNSIGNED    NOT NULL COMMENT 'FK to rebalance_cycle.id',
    ticker      VARCHAR(20)     NOT NULL,
    action      VARCHAR(20)     NOT NULL COMMENT 'BUY | SELL | HOLD | NO_ACTION | SKIP_INSUFFICIENT_CASH',
    quantity    INT UNSIGNED    NULL     COMMENT 'NULL for HOLD / NO_ACTION / SKIP',
    price_usd   DECIMAL(12,4)   NULL     COMMENT 'NULL for HOLD / NO_ACTION / SKIP',
    cash_before DECIMAL(12,2)   NULL     COMMENT 'Cash immediately before this transaction',
    cash_after  DECIMAL(12,2)   NULL     COMMENT 'Cash immediately after this transaction',
    status      VARCHAR(32)     NOT NULL COMMENT 'executed | skipped_insufficient_cash | no_action | hold',
    reason      TEXT            NULL     COMMENT 'LLM reasoning — populated by F-03; NULL in F-02',
    executed_at DATETIME        NOT NULL,
    CONSTRAINT fk_tx_cycle FOREIGN KEY (cycle_id) REFERENCES rebalance_cycle (id),
    INDEX idx_cycle_id (cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
