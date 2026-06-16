ALTER TABLE users
    ADD COLUMN email_verify_token      VARCHAR(64)  NULL DEFAULT NULL,
    ADD COLUMN email_verify_expires_at DATETIME     NULL DEFAULT NULL,
    ADD COLUMN email_verified_at       DATETIME     NULL DEFAULT NULL;

UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;
