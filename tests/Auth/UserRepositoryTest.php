<?php

declare(strict_types=1);

namespace CVS\Tests\Auth;

use CVS\Auth\UserRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase
{
    // Full current production shape (001 + 005 is_admin + 021 email
    // verification + 032 verify-resend cooldown) — earlier versions of this
    // builder only had id/email/password_hash/created_at, leaving
    // findByEmail's is_admin select and every verify-token method
    // completely untested against a schema that didn't have the columns.
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE users (
                id                        INTEGER PRIMARY KEY AUTOINCREMENT,
                email                     TEXT    NOT NULL UNIQUE,
                password_hash             TEXT    NOT NULL,
                is_admin                  INTEGER NOT NULL DEFAULT 0,
                email_verify_token        TEXT    NULL,
                email_verify_expires_at   TEXT    NULL,
                email_verify_last_sent_at TEXT    NULL,
                email_verified_at         TEXT    NULL,
                created_at                TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        return $pdo;
    }

    private function makeRepo(): UserRepository
    {
        return new UserRepository($this->makePdo());
    }

    public function test_find_all_returns_empty_when_no_users(): void
    {
        $repo = $this->makeRepo();
        $this->assertSame([], $repo->findAll());
    }

    public function test_find_all_returns_all_users(): void
    {
        $repo = $this->makeRepo();
        $repo->create('a@test.com', 'hash1');
        $repo->create('b@test.com', 'hash2');

        $all = $repo->findAll();
        $this->assertCount(2, $all);
        $this->assertSame('a@test.com', $all[0]['email']);
        $this->assertSame('b@test.com', $all[1]['email']);
    }

    public function test_find_all_orders_by_id(): void
    {
        $repo = $this->makeRepo();
        $repo->create('z@test.com', 'h');
        $repo->create('a@test.com', 'h');

        $ids = array_column($repo->findAll(), 'id');
        $this->assertLessThan($ids[1], $ids[0]);
    }

    // ------------------------------------------------------------------
    // Verification cooldown (guards the resend-verification email-bombing
    // vector — see AuthController::sendVerificationEmail())
    // ------------------------------------------------------------------

    public function test_can_resend_verification_true_when_never_sent(): void
    {
        $repo = $this->makeRepo();
        $id   = $repo->create('a@test.com', 'h');

        $this->assertTrue($repo->canResendVerification($id, 90));
    }

    public function test_set_verify_token_stamps_last_sent_at_and_blocks_immediate_resend(): void
    {
        $repo = $this->makeRepo();
        $id   = $repo->create('a@test.com', 'h');

        $repo->setVerifyToken($id, 'tok123', '2099-01-01 00:00:00');

        $this->assertFalse($repo->canResendVerification($id, 90));
    }

    public function test_can_resend_verification_true_after_cooldown_elapses(): void
    {
        $pdo  = $this->makePdo();
        $repo = new UserRepository($pdo);
        $id   = $repo->create('a@test.com', 'h');
        $repo->setVerifyToken($id, 'tok123', '2099-01-01 00:00:00');

        // Simulate the cooldown having elapsed by backdating the stamp
        // directly (no sleep() in a test suite).
        $twoMinutesAgo = (new DateTimeImmutable('-120 seconds'))->format('Y-m-d H:i:s');
        $pdo->prepare('UPDATE users SET email_verify_last_sent_at = ? WHERE id = ?')
            ->execute([$twoMinutesAgo, $id]);

        $this->assertTrue($repo->canResendVerification($id, 90));
    }
}
