-- Migration 001 — create users table
-- Run once against cvs_db before first deploy.

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255)     NOT NULL,
    password_hash VARCHAR(255)     NOT NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
