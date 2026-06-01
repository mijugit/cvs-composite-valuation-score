-- S-01: ai-divergence-analysis
-- Shared cache analiz AI — jeden wiersz per ticker (nadpisywany przy odświeżeniu).
-- UNIQUE(ticker) gwarantuje jeden aktywny wpis per spółka.

CREATE TABLE IF NOT EXISTS ai_analyses (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ticker          VARCHAR(20)   NOT NULL,
    content         TEXT          NOT NULL,
    model           VARCHAR(80)   NULL,
    tokens_input    INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_output   INT UNSIGNED  NOT NULL DEFAULT 0,
    generated_by    INT UNSIGNED  NULL,
    generated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticker     (ticker),
    INDEX idx_generated_at   (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
