<?php

declare(strict_types=1);

namespace CVS\Tests\Pro;

use CVS\Pro\ProRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ProRepositoryTest extends TestCase
{
    private function makeRepo(): ProRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE pro_codes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                code        TEXT    NOT NULL UNIQUE,
                user_id     INTEGER NULL,
                description TEXT    NULL,
                is_active   INTEGER NOT NULL DEFAULT 1,
                created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        // Stub users table for the LEFT JOIN in findAll()
        $pdo->exec('
            CREATE TABLE users (
                id    INTEGER PRIMARY KEY,
                email TEXT NOT NULL
            )
        ');
        return new ProRepository($pdo);
    }

    public function test_find_active_global_code(): void
    {
        $repo = $this->makeRepo();
        $repo->create('GLOBAL-CODE', null, '');

        $this->assertTrue($repo->findActiveCode('GLOBAL-CODE', 1));
        $this->assertTrue($repo->findActiveCode('GLOBAL-CODE', 99));
    }

    public function test_find_active_user_specific_code(): void
    {
        $repo = $this->makeRepo();
        $repo->create('USER-CODE', 5, '');

        $this->assertTrue($repo->findActiveCode('USER-CODE', 5));
        $this->assertFalse($repo->findActiveCode('USER-CODE', 6));
    }

    public function test_find_active_returns_false_for_unknown_code(): void
    {
        $repo = $this->makeRepo();
        $this->assertFalse($repo->findActiveCode('BAD-CODE', 1));
    }

    public function test_revoke_deactivates_code(): void
    {
        $repo = $this->makeRepo();
        $repo->create('REVOKE-ME', null, '');

        $all = $repo->findAll();
        $repo->revoke((int) $all[0]['id']);

        $this->assertFalse($repo->findActiveCode('REVOKE-ME', 1));
    }

    public function test_activate_reactivates_code(): void
    {
        $repo = $this->makeRepo();
        $repo->create('TOGGLE', null, '');
        $all = $repo->findAll();
        $id  = (int) $all[0]['id'];

        $repo->revoke($id);
        $this->assertFalse($repo->findActiveCode('TOGGLE', 1));

        $repo->activate($id);
        $this->assertTrue($repo->findActiveCode('TOGGLE', 1));
    }

    public function test_find_all_returns_all_codes(): void
    {
        $repo = $this->makeRepo();
        $repo->create('A', null, 'global');
        $repo->create('B', 1, 'user 1');

        $this->assertCount(2, $repo->findAll());
    }
}
