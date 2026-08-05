-- Password reset (mirrors 021_add_email_verification.sql's token/expiry/cooldown
-- shape, applied to a forgot-password flow instead of the sign-up confirmation).

ALTER TABLE users
    ADD COLUMN password_reset_token        VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN password_reset_expires_at   DATETIME    NULL DEFAULT NULL,
    ADD COLUMN password_reset_last_sent_at DATETIME    NULL DEFAULT NULL;
