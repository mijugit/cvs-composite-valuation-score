-- F-04: daily-rescore-engine
-- Dzienne snapshoty CVS dla całego zbioru obserwowanych tickerów.
-- Źródło dla S-02 (track record), S-03 (screener), S-04 (alerty).
-- Idempotencja: UNIQUE(ticker, score_date) + ON DUPLICATE KEY UPDATE w PHP.

CREATE TABLE IF NOT EXISTS cvs_snapshots (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ticker        VARCHAR(20)   NOT NULL,
    score_date    DATE          NOT NULL,
    scored_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cvs_swing     DECIMAL(5,2)  NULL,
    cvs_fund      DECIMAL(5,2)  NULL,
    reco_swing    VARCHAR(60)   NULL,
    reco_fund     VARCHAR(60)   NULL,
    golden_signal VARCHAR(20)   NULL,
    quality_gate  TINYINT(1)    NOT NULL DEFAULT 0,
    gate_failures JSON          NULL,
    pillar_scores JSON          NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticker_day   (ticker, score_date),
    INDEX idx_score_date       (score_date),
    INDEX idx_ticker_date      (ticker, score_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
