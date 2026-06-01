-- F-05: pro-access
-- Tabela kodów PRO.
-- user_id NULL  = kod globalny (każdy znający kod może generować AI).
-- user_id = N   = kod przypisany do konkretnego użytkownika (faza 2+).

CREATE TABLE IF NOT EXISTS pro_codes (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    code        VARCHAR(64)   NOT NULL,
    user_id     INT UNSIGNED  NULL,
    description VARCHAR(255)  NULL,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code       (code),
    INDEX idx_user           (user_id),
    INDEX idx_active         (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
