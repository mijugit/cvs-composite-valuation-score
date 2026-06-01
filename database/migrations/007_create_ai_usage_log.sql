-- F-05: pro-access
-- Log każdego wywołania AI — podstawa do limitów dziennych i miesięcznych.
-- Indeks idx_user_date pokrywa zapytania COUNT(*) per user per dzień/miesiąc.

CREATE TABLE IF NOT EXISTS ai_usage_log (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED  NOT NULL,
    pro_code      VARCHAR(64)   NOT NULL,
    tokens_input  INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_output INT UNSIGNED  NOT NULL DEFAULT 0,
    generated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_user_date (user_id, generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
