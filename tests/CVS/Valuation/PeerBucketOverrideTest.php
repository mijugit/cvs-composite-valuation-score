<?php

declare(strict_types=1);

namespace CVS\Tests\CVS\Valuation;

use CVS\CVS\Valuation\PeerBucketOverrideRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PeerBucketOverrideTest extends TestCase
{
    private PDO $db;
    private PeerBucketOverrideRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('
            CREATE TABLE peer_bucket_override (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                ticker      TEXT NOT NULL UNIQUE,
                bucket_key  TEXT NOT NULL,
                reason      TEXT,
                review_date TEXT,
                created_by  INTEGER,
                created_at  TEXT NOT NULL,
                updated_at  TEXT
            )
        ');
        $this->repo = new PeerBucketOverrideRepository($this->db);
    }

    public function testUpsertThenReadBack(): void
    {
        $this->repo->upsert('MU', 'Memory & Storage', 'DRAM/NAND', '2026-12-31', 1);

        $row = $this->repo->findByTicker('MU');
        $this->assertNotNull($row);
        $this->assertSame('Memory & Storage', $row['bucket_key']);
        $this->assertSame('DRAM/NAND', $row['reason']);
        $this->assertSame('2026-12-31', $row['review_date']);
    }

    public function testTickerIsNormalisedToUppercase(): void
    {
        $this->repo->upsert('mu', 'Memory & Storage', 'x');

        $this->assertNotNull($this->repo->findByTicker('MU'));
        $this->assertNotNull($this->repo->findByTicker('mu'));
        $this->assertSame(['MU' => 'Memory & Storage'], $this->repo->findBucketMap());
    }

    public function testSecondUpsertReplacesRatherThanDuplicates(): void
    {
        $this->repo->upsert('MU', 'Memory & Storage', 'pierwsza teza', '2026-12-31');
        $this->repo->upsert('MU', 'Semiconductors',   'teza zrewidowana', null);

        $this->assertCount(1, $this->repo->findAll());
        $row = $this->repo->findByTicker('MU');
        $this->assertSame('Semiconductors', $row['bucket_key']);
        $this->assertSame('teza zrewidowana', $row['reason']);
        $this->assertNull($row['review_date']);
    }

    public function testBucketMapCoversTheWholeGroup(): void
    {
        foreach (['MU', 'SNDK', 'STX', 'WDC', '005930.KS'] as $t) {
            $this->repo->upsert($t, 'Memory & Storage', 'cykl pamięciowy', '2027-01-31');
        }

        $map = $this->repo->findBucketMap();
        $this->assertCount(5, $map);
        $this->assertSame('Memory & Storage', $map['005930.KS']);
    }

    public function testDeleteRestoresYahooClassification(): void
    {
        $this->repo->upsert('MU', 'Memory & Storage', 'x');
        $this->repo->delete('mu');

        $this->assertNull($this->repo->findByTicker('MU'));
        $this->assertSame([], $this->repo->findBucketMap());
    }

    /**
     * Segment-dominance groupings are true for a cycle and then quietly stop
     * being true. A passed review date has to surface, or the override becomes
     * a forgotten thumb on the scale.
     */
    public function testFindDueForReviewReturnsOnlyExpiredDatedOverrides(): void
    {
        $this->repo->upsert('MU',     'Memory & Storage', 'cykl',        '2026-08-01'); // passed
        $this->repo->upsert('STX',    'Memory & Storage', 'cykl',        '2027-01-01'); // future
        $this->repo->upsert('PKO.WA', 'Banki PL',         'regulator',   null);         // structural

        $due = $this->repo->findDueForReview('2026-08-16');

        $this->assertCount(1, $due);
        $this->assertSame('MU', $due[0]['ticker']);
    }

    public function testStructuralOverrideNeverComesUpForReview(): void
    {
        $this->repo->upsert('PKO.WA', 'Banki PL', 'jeden regulator i rynek', null);

        $this->assertSame([], $this->repo->findDueForReview('2099-01-01'));
    }
}
