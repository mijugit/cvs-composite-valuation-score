<?php

declare(strict_types=1);

namespace CVS\Auth;

use CVS\Core\Request;

/**
 * "CVS Pillar Check" — an on-brand, dependency-free anti-bot challenge for
 * /register. No third-party service, no JS SDK, no image/OCR puzzle
 * (those are both accessibility-hostile and easily broken by OCR — a
 * homemade visual CAPTCHA is a worse bet than a well-known one, not a
 * better one). Three independent layers, each cheap and stateless beyond
 * the session:
 *
 *  1. Honeypot — a form field real users never see (hidden off-screen via
 *     CSS in templates/register.php). Generic form-fill bots that populate
 *     every input they find will fill it; a real browser never will.
 *  2. Timing gate — render timestamp vs submit timestamp, both tracked
 *     server-side in the session (never trust a client-supplied timestamp).
 *     Bots typically submit near-instantly after fetching the page.
 *  3. On-brand arithmetic — solve
 *     valuation*valuation_weight + quality*quality_weight
 *     using the SAME weights as the real Swing pillar
 *     (config/cvs-weights.php -> modes.swing), with random operands per
 *     render so the answer can't be memorised or hardcoded by a scraper.
 *
 * Pure logic over session state — no I/O beyond $_SESSION, fully
 * unit-testable offline.
 */
final class PillarCaptcha
{
    private const SESSION_KEY = 'pillar_captcha';

    public function __construct(
        private readonly float  $valuationWeight,
        private readonly float  $qualityWeight,
        private readonly int    $minFormAgeSeconds,
        private readonly string $honeypotField,
    ) {
    }

    /**
     * Generate a fresh challenge, store its expected answer + render time
     * in the session, and return the values the template needs to render it.
     *
     * @return array{valuation: int, quality: int, valuation_weight: float, quality_weight: float, honeypot_field: string}
     */
    public function generate(): array
    {
        $valuation = random_int(20, 90);
        $quality   = random_int(20, 90);

        $_SESSION[self::SESSION_KEY] = [
            'expected'    => round($valuation * $this->valuationWeight + $quality * $this->qualityWeight, 1),
            'rendered_at' => time(),
        ];

        return [
            'valuation'        => $valuation,
            'quality'          => $quality,
            'valuation_weight' => $this->valuationWeight,
            'quality_weight'   => $this->qualityWeight,
            'honeypot_field'   => $this->honeypotField,
        ];
    }

    /**
     * Validate a submission against the session-stored challenge. Checks
     * all three layers; a failure in any one is a bot verdict. Deliberately
     * does not distinguish WHICH layer failed in its return value — a
     * uniform generic failure gives a probing bot nothing to iterate on.
     */
    public function verify(Request $req): bool
    {
        if (trim((string) $req->input($this->honeypotField, '')) !== '') {
            return false;
        }

        $challenge = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($challenge)) {
            return false;
        }

        $age = time() - (int) ($challenge['rendered_at'] ?? 0);
        if ($age < $this->minFormAgeSeconds) {
            return false;
        }

        // Accept a Polish-style decimal comma ("30,5") as well as a period —
        // (float) casting a comma-containing string silently truncates at
        // the comma, which would reject a correct answer from any user who
        // types the number the way they normally would.
        $answer = str_replace(',', '.', trim((string) $req->input('pillar_answer', '')));
        if ($answer === '' || !is_numeric($answer)) {
            return false;
        }

        return abs((float) $answer - (float) ($challenge['expected'] ?? PHP_INT_MAX)) < 0.05;
    }

    /** Call after a successful registration to avoid a stale challenge lingering in the session. */
    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
