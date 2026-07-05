-- Migration 029: Create Lab (experimental portfolios) tables.
--
-- Deterministic, paper-only portfolios testing documented execution-policy
-- research (see context/changes/cvs-experimental-portfolios/koncepcja.md).
-- Kept fully separate from the LLM-driven `/portfolio` module (portfolio_*,
-- rebalance_cycle, src/Portfolio/*) — different namespace (CVS\Lab), different
-- tables (lab_*). The Lab module only ever READS rebalance_cycle.portfolio_value_usd
-- (no FK, no writes) to draw a read-only comparison line on the /lab chart.
--
-- Four additive tables:
--   lab_portfolio — registry/state of each of the 7 (P0-P6) portfolio variants
--   lab_position  — current holdings per portfolio
--   lab_trade     — immutable trade log (filled + pending, P2 open-execution)
--   lab_nav       — daily NAV series per portfolio (drives the /lab chart)

CREATE TABLE IF NOT EXISTS lab_portfolio (
    code                VARCHAR(8)     NOT NULL,
    name                VARCHAR(80)    NOT NULL,
    experiment_version  VARCHAR(10)    NOT NULL,
    started_at          DATE           NULL COMMENT 'Set on first seed trade; NULL = not yet seeded',
    cash                DECIMAL(14,4)  NOT NULL,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_position (
    portfolio_code   VARCHAR(8)     NOT NULL,
    ticker           VARCHAR(20)    NOT NULL,
    quantity         DECIMAL(16,6)  NOT NULL COMMENT 'Fractional shares allowed (paper portfolio)',
    avg_entry_price  DECIMAL(12,4)  NOT NULL,
    entry_date       DATE           NOT NULL,
    PRIMARY KEY (portfolio_code, ticker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_trade (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    portfolio_code  VARCHAR(8)     NOT NULL,
    trade_date      DATE           NOT NULL,
    ticker          VARCHAR(20)    NOT NULL,
    action          VARCHAR(4)     NOT NULL COMMENT 'BUY | SELL',
    quantity        DECIMAL(16,6)  NOT NULL,
    price           DECIMAL(12,4)  NULL COMMENT 'NULL while status=pending (P2 open-execution, filled next day)',
    fee             DECIMAL(12,4)  NULL,
    reason          VARCHAR(16)    NOT NULL COMMENT 'seed | rebalance | stop_loss',
    status          VARCHAR(8)     NOT NULL COMMENT 'filled | pending',
    created_at      DATETIME       NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_portfolio_date (portfolio_code, trade_date),
    INDEX idx_portfolio_status (portfolio_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_nav (
    portfolio_code    VARCHAR(8)     NOT NULL,
    nav_date          DATE           NOT NULL,
    nav               DECIMAL(14,4)  NOT NULL,
    cash              DECIMAL(14,4)  NOT NULL,
    positions_value   DECIMAL(14,4)  NOT NULL,
    PRIMARY KEY (portfolio_code, nav_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
