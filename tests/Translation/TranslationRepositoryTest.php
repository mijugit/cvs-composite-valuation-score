<?php

declare(strict_types=1);

namespace CVS\Tests\Translation;

use CVS\Translation\TranslationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class TranslationRepositoryTest extends TestCase
{
    private function makeRepo(): TranslationRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE company_translations (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker      TEXT NOT NULL,
                lang        TEXT NOT NULL,
                field       TEXT NOT NULL,
                translation TEXT NOT NULL,
                updated_at  TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (ticker, lang, field)
            )
        ');
        return new TranslationRepository($pdo);
    }

    public function test_find_returns_null_when_none(): void
    {
        $repo = $this->makeRepo();
        $this->assertNull($repo->find('AAPL', 'pl', 'long_description'));
    }

    public function test_save_and_find(): void
    {
        $repo = $this->makeRepo();
        $repo->save('aapl', 'pl', 'long_description', 'Opis po polsku');

        $this->assertSame('Opis po polsku', $repo->find('AAPL', 'pl', 'long_description'));
    }

    public function test_save_is_idempotent_overwrites_on_duplicate(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', 'pl', 'long_description', 'Pierwsza wersja');
        $repo->save('AAPL', 'pl', 'long_description', 'Druga wersja');

        $this->assertSame('Druga wersja', $repo->find('AAPL', 'pl', 'long_description'));
    }

    public function test_different_fields_and_langs_are_independent(): void
    {
        $repo = $this->makeRepo();
        $repo->save('AAPL', 'pl', 'long_description', 'Opis PL');
        $repo->save('MSFT', 'pl', 'long_description', 'Inny opis PL');

        $this->assertSame('Opis PL', $repo->find('AAPL', 'pl', 'long_description'));
        $this->assertSame('Inny opis PL', $repo->find('MSFT', 'pl', 'long_description'));
        $this->assertNull($repo->find('AAPL', 'en', 'long_description'));
    }
}
