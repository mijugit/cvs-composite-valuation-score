-- Migration 032: Add verification-email cooldown tracking.
--
-- Closes an email-bombing vector: resend-verification (and the
-- unverified-login auto-resend) had no rate limit, so an attacker could
-- register once with a victim's address then spam that inbox indefinitely
-- via /auth/resend-verification with no need to know any password.

ALTER TABLE users
    ADD COLUMN email_verify_last_sent_at DATETIME NULL DEFAULT NULL;
