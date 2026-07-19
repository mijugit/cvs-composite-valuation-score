<?php

declare(strict_types=1);

namespace CVS\Tests\Auth;

use CVS\Auth\PillarCaptcha;
use CVS\Core\Request;
use PHPUnit\Framework\TestCase;

class PillarCaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
    }

    private function makeCaptcha(int $minFormAgeSeconds = 3): PillarCaptcha
    {
        return new PillarCaptcha(0.40, 0.15, $minFormAgeSeconds, 'referral_code');
    }

    /** @param array<string, mixed> $post */
    private function makeRequest(array $post): Request
    {
        $_POST = $post;
        return new Request();
    }

    public function test_generate_stores_expected_answer_and_render_time_in_session(): void
    {
        $challenge = $this->makeCaptcha()->generate();

        $this->assertArrayHasKey('valuation', $challenge);
        $this->assertArrayHasKey('quality', $challenge);
        $this->assertSame('referral_code', $challenge['honeypot_field']);
        $this->assertArrayHasKey('pillar_captcha', $_SESSION);
        $this->assertSame(
            round($challenge['valuation'] * 0.40 + $challenge['quality'] * 0.15, 1),
            $_SESSION['pillar_captcha']['expected']
        );
    }

    public function test_verify_accepts_correct_answer_after_min_age(): void
    {
        $captcha   = $this->makeCaptcha(minFormAgeSeconds: 0);
        $challenge = $captcha->generate();
        $expected  = $_SESSION['pillar_captcha']['expected'];

        $req = $this->makeRequest(['pillar_answer' => (string) $expected]);

        $this->assertTrue($captcha->verify($req));
    }

    public function test_verify_accepts_polish_decimal_comma(): void
    {
        // Directly seed a fractional expected value (rather than relying on
        // generate()'s random operands, which could by chance land on a
        // whole number and never exercise the comma-replacement path at
        // all) — a Polish user typing "42,5" rather than "42.5" must still
        // be accepted.
        $captcha = $this->makeCaptcha(minFormAgeSeconds: 0);
        $_SESSION['pillar_captcha'] = ['expected' => 42.5, 'rendered_at' => time()];

        $req = $this->makeRequest(['pillar_answer' => '42,5']);

        $this->assertTrue($captcha->verify($req));
    }

    public function test_verify_rejects_wrong_answer(): void
    {
        $captcha = $this->makeCaptcha(minFormAgeSeconds: 0);
        $captcha->generate();

        $req = $this->makeRequest(['pillar_answer' => '999999']);

        $this->assertFalse($captcha->verify($req));
    }

    public function test_verify_rejects_non_numeric_answer(): void
    {
        $captcha = $this->makeCaptcha(minFormAgeSeconds: 0);
        $captcha->generate();

        $req = $this->makeRequest(['pillar_answer' => 'not-a-number']);

        $this->assertFalse($captcha->verify($req));
    }

    public function test_verify_rejects_when_honeypot_filled(): void
    {
        $captcha   = $this->makeCaptcha(minFormAgeSeconds: 0);
        $challenge = $captcha->generate();
        $expected  = $_SESSION['pillar_captcha']['expected'];

        $req = $this->makeRequest([
            'pillar_answer'        => (string) $expected,
            $challenge['honeypot_field'] => 'i am a bot',
        ]);

        $this->assertFalse($captcha->verify($req));
    }

    public function test_verify_rejects_submission_before_min_form_age(): void
    {
        $captcha  = $this->makeCaptcha(minFormAgeSeconds: 60);
        $captcha->generate();
        $expected = $_SESSION['pillar_captcha']['expected'];

        // Submitted "instantly" — rendered_at is now(), so age is ~0s < 60s.
        $req = $this->makeRequest(['pillar_answer' => (string) $expected]);

        $this->assertFalse($captcha->verify($req));
    }

    public function test_verify_rejects_with_no_prior_challenge(): void
    {
        $captcha = $this->makeCaptcha(minFormAgeSeconds: 0);
        // No generate() call — nothing in session.
        $req = $this->makeRequest(['pillar_answer' => '42']);

        $this->assertFalse($captcha->verify($req));
    }

    public function test_clear_removes_session_challenge(): void
    {
        $captcha = $this->makeCaptcha();
        $captcha->generate();
        $this->assertArrayHasKey('pillar_captcha', $_SESSION);

        $captcha->clear();

        $this->assertArrayNotHasKey('pillar_captcha', $_SESSION);
    }
}
