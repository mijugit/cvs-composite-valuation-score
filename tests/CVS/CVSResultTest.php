<?php

declare(strict_types=1);

namespace CVS\Tests\CVS;

use CVS\CVS\CVSResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CVSResult — Phase 5 slice 1 additive overlay block.
 *
 * Guards backward compatibility (FR-017): base fields/keys unchanged, the
 * `overlay` block is purely additive and defaults to null.
 */
class CVSResultTest extends TestCase
{
    private function passed(?array $overlay = null): CVSResult
    {
        return CVSResult::passed(
            ticker:                    'TEST',
            swingCvs:                  60.0,
            fundamentalCvs:            65.0,
            pillarScores:              ['valuation' => 70.0, 'momentum_swing' => 50.0, 'momentum_fund' => 55.0, 'quality' => 80.0],
            swingRecommendation:       '⬆ AKUMULUJ',
            fundamentalRecommendation: '⬆ AKUMULUJ',
            config:                    ['thresholds' => ['accumulate' => 58]],
            modelVersion:              '3.0',
            overlay:                   $overlay,
        );
    }

    public function test_overlay_defaults_to_null_and_is_present_in_array(): void
    {
        $result = $this->passed();

        $this->assertNull($result->overlay);

        $arr = $result->toArray();
        $this->assertArrayHasKey('overlay', $arr);
        $this->assertNull($arr['overlay']);
    }

    public function test_overlay_block_is_carried_through_to_array(): void
    {
        $overlay = [
            'shadow_version' => '3.1',
            'swing'          => 54.9,
            'fund'           => 64.7,
            'swing_reco'     => '→ NEUTRALNIE',
            'fund_reco'      => '⬆ AKUMULUJ',
            'penalties'      => ['revision' => -11.8, 'target' => 0.0, 'total' => -11.8],
            'coverage'       => ['missing_eps_trend' => false, 'missing_target' => false],
        ];

        $result = $this->passed($overlay);

        $this->assertSame($overlay, $result->overlay);
        $this->assertSame($overlay, $result->toArray()['overlay']);
    }

    public function test_base_fields_unchanged_and_disclaimer_present(): void
    {
        $arr = $this->passed()->toArray();

        // Base contract (backward compatibility) still intact.
        $this->assertSame('TEST', $arr['ticker']);
        $this->assertSame(60.0, $arr['swing']['cvs']);
        $this->assertSame(65.0, $arr['fundamental']['cvs']);
        $this->assertArrayHasKey('disclaimer', $arr);
    }

    public function test_failed_result_has_null_overlay(): void
    {
        $result = CVSResult::failed('BAD', ['low gross margin']);

        $this->assertNull($result->overlay);
        $this->assertArrayHasKey('overlay', $result->toArray());
        $this->assertNull($result->toArray()['overlay']);
    }
}
