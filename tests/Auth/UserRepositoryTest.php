<?php

declare(strict_types=1);

namespace CVS\Tests\Auth;

use CVS\Auth\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase
{
    private function makeRepo(): UserRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                email         TEXT    NOT NULL UNIQUE,
                password_hash TEXT    NOT NULL,
                created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        return new UserRepository($pdo);
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
}
