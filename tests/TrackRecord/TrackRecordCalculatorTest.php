<?php

declare(strict_types=1);

namespace CVS\Tests\TrackRecord;

use CVS\TrackRecord\TrackRecordCalculator;
use PHPUnit\Framework\TestCase;

class TrackRecordCalculatorTest extends TestCase
{
    // ------------------------------------------------------------------
    // isHit()
    // ------------------------------------------------------------------

    public function test_bullish_reco_price_up_is_hit(): void
    {
        $this->assertTrue(TrackRecordCalculator::isHit('SILNE KUPUJ', 5.0));
        $this->assertTrue(TrackRecordCalculator::isHit('KUPUJ', 0.5));
        $this->assertTrue(TrackRecordCalculator::isHit('AKUMULUJ', 1.0));
    }

    public function test_bullish_reco_price_down_is_miss(): void
    {
        $this->assertFalse(TrackRecordCalculator::isHit('SILNE KUPUJ', -3.0));
        $this->assertFalse(TrackRecordCalculator::isHit('KUPUJ', -0.1));
    }

    public function test_bearish_reco_price_down_is_hit(): void
    {
        $this->assertTrue(TrackRecordCalculator::isHit('REDUKUJ', -2.0));
        $this->assertTrue(TrackRecordCalculator::isHit('UNIKAJ', -10.0));
    }

    public function test_bearish_reco_price_up_is_miss(): void
    {
        $this->assertFalse(TrackRecordCalculator::isHit('UNIKAJ', 5.0));
        $this->assertFalse(TrackRecordCalculator::isHit('REDUKUJ', 0.5));
    }

    public function test_neutral_reco_returns_null(): void
    {
        $this->assertNull(TrackRecordCalculator::isHit('NEUTRALNIE', 5.0));
        $this->assertNull(TrackRecordCalculator::isHit('NEUTRALNIE', -5.0));
        $this->assertNull(TrackRecordCalculator::isHit('', 5.0));
    }

    // ------------------------------------------------------------------
    // enrichWithResult()
    // ------------------------------------------------------------------

    public function test_enrich_adds_result_key(): void
    {
        $rows = [
            ['reco_swing' => 'KUPUJ',     'price_change_pct' => 3.0],
            ['reco_swing' => 'UNIKAJ',    'price_change_pct' => -2.0],
            ['reco_swing' => 'NEUTRALNIE','price_change_pct' => 1.0],
        ];

        $enriched = TrackRecordCalculator::enrichWithResult($rows);

        $this->assertSame('hit',     $enriched[0]['result']);
        $this->assertSame('hit',     $enriched[1]['result']);
        $this->assertSame('neutral', $enriched[2]['result']);
    }

    // ------------------------------------------------------------------
    // summarise()
    // ------------------------------------------------------------------

    public function test_summarise_empty_returns_zeros(): void
    {
        $stats = TrackRecordCalculator::summarise([]);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertNull($stats['hit_rate_pct']);
        $this->assertNull($stats['avg_change_pct']);
    }

    public function test_summarise_counts_correctly(): void
    {
        $enriched = [
            ['result' => 'hit',     'price_change_pct' => 5.0],
            ['result' => 'hit',     'price_change_pct' => 3.0],
            ['result' => 'miss',    'price_change_pct' => -2.0],
            ['result' => 'neutral', 'price_change_pct' => 1.0],
        ];

        $stats = TrackRecordCalculator::summarise($enriched);

        $this->assertSame(4, $stats['total']);
        $this->assertSame(2, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(1, $stats['neutral']);
        $this->assertEquals(66.7, $stats['hit_rate_pct']); // 2/3 = 66.7%
        $this->assertEquals(1.75, $stats['avg_change_pct']); // (5+3-2+1)/4
    }

    public function test_summarise_all_neutral_hit_rate_null(): void
    {
        $enriched = [
            ['result' => 'neutral', 'price_change_pct' => 1.0],
        ];

        $stats = TrackRecordCalculator::summarise($enriched);
        $this->assertNull($stats['hit_rate_pct']);
        $this->assertSame(1, $stats['neutral']);
    }

    // ------------------------------------------------------------------
    // deltaHitRatePct()
    // ------------------------------------------------------------------

    public function test_delta_positive_when_recent_band_outperforms(): void
    {
        // Older band (horizon 2N): 1/2 hit = 50%.
        $older = [
            ['score_date' => '2026-01-01', 'result' => 'hit',  'price_change_pct' => 5.0],
            ['score_date' => '2026-01-02', 'result' => 'miss', 'price_change_pct' => -1.0],
        ];
        // Current (horizon N) = older + two new rows that are both hits → recent
        // band (current minus older, by score_date) = 2/2 hit = 100%.
        $current = array_merge($older, [
            ['score_date' => '2026-02-01', 'result' => 'hit', 'price_change_pct' => 3.0],
            ['score_date' => '2026-02-02', 'result' => 'hit', 'price_change_pct' => 2.0],
        ]);

        $delta = TrackRecordCalculator::deltaHitRatePct($current, $older);

        $this->assertEquals(50.0, $delta); // 100% - 50%
    }

    public function test_delta_negative_when_recent_band_underperforms(): void
    {
        $older = [
            ['score_date' => '2026-01-01', 'result' => 'hit', 'price_change_pct' => 5.0],
            ['score_date' => '2026-01-02', 'result' => 'hit', 'price_change_pct' => 4.0],
        ];
        $current = array_merge($older, [
            ['score_date' => '2026-02-01', 'result' => 'miss', 'price_change_pct' => -3.0],
        ]);

        $delta = TrackRecordCalculator::deltaHitRatePct($current, $older);

        $this->assertEquals(-100.0, $delta); // 0% - 100%
    }

    public function test_delta_null_when_recent_band_empty(): void
    {
        // $current === $older → the "recent" band (set difference) is empty.
        $older = [
            ['score_date' => '2026-01-01', 'result' => 'hit', 'price_change_pct' => 5.0],
        ];

        $this->assertNull(TrackRecordCalculator::deltaHitRatePct($older, $older));
    }

    public function test_delta_null_when_older_band_empty(): void
    {
        $current = [
            ['score_date' => '2026-02-01', 'result' => 'hit', 'price_change_pct' => 5.0],
        ];

        $this->assertNull(TrackRecordCalculator::deltaHitRatePct($current, []));
    }

    public function test_delta_null_when_recent_band_all_neutral(): void
    {
        $older = [
            ['score_date' => '2026-01-01', 'result' => 'hit', 'price_change_pct' => 5.0],
        ];
        $current = array_merge($older, [
            ['score_date' => '2026-02-01', 'result' => 'neutral', 'price_change_pct' => 0.5],
        ]);

        $this->assertNull(TrackRecordCalculator::deltaHitRatePct($current, $older));
    }
}
