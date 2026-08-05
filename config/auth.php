<?php

declare(strict_types=1);

/**
 * Auth / anti-abuse configuration.
 *
 * Values here defend the registration + email-verification flow against
 * two abuse vectors observed 2026-07-19: bulk fake-account creation and
 * unlimited verification-email resends (an unauthenticated email-bombing
 * vector against arbitrary third-party addresses — see
 * AuthController::resendVerification()).
 */
return [
    // Minimum seconds between verification-email sends for the same
    // account, across register / login-unverified-resend / resend-verification.
    'verify_resend_cooldown_seconds' => (int) ($_ENV['AUTH_VERIFY_RESEND_COOLDOWN'] ?? 90),

    // Same guard, same rationale, applied to the forgot-password flow
    // (AuthController::sendPasswordResetEmail()) — a public, unauthenticated
    // endpoint that emails an arbitrary address is the same abuse shape.
    'reset_resend_cooldown_seconds' => (int) ($_ENV['AUTH_RESET_RESEND_COOLDOWN'] ?? 90),

    // "CVS Pillar Check" — on-brand anti-bot challenge for /register.
    // See src/Auth/PillarCaptcha.php.
    'captcha' => [
        // Honeypot input name. Real users never see it (hidden off-screen
        // via CSS); a non-empty value on submit means an autofill bot filled
        // every field it could find.
        'honeypot_field' => 'referral_code',

        // Minimum seconds between form render and submit. Bots typically
        // submit near-instantly; a real person needs at least this long to
        // read the prompt and type an answer.
        'min_form_age_seconds' => (int) ($_ENV['AUTH_CAPTCHA_MIN_AGE'] ?? 3),
    ],
];
