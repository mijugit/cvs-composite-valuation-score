-- Cache tłumaczeń opisów spółek (Chrome Translator API / Built-in AI, on-device).
-- Jeden wiersz per (ticker, lang) — gdy jednemu użytkownikowi uda się przetłumaczyć
-- opis lokalnie w przeglądarce, wynik jest zapisywany i serwowany innym userom,
-- którzy nie mają dostępu do Translator API.

CREATE TABLE IF NOT EXISTS company_translations (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ticker      VARCHAR(20)   NOT NULL,
    lang        VARCHAR(5)    NOT NULL,
    field       VARCHAR(40)   NOT NULL,
    translation TEXT          NOT NULL,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticker_lang_field (ticker, lang, field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
