<?php

declare(strict_types=1);

namespace CVS\Tests\Forecast;

use CVS\Forecast\EarningsTrendParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EarningsTrendParser (Phase 5, slice 1).
 *
 * Pure offline tests using synthetic raw Yahoo earningsTrend payloads — no
 * network, no fetcher (mirrors ForecastParserTest conventions).
 */
class EarningsTrendParserTest extends TestCase
{
    /** Yahoo wraps numerics as {"raw": x, "fmt": "y"}. */
    private function num(float $x): array
    {
        return ['raw' => $x, 'fmt' => (string) $x];
    }

    /** @return array<string, mixed> raw payload with a +1q row built from the given current/90daysAgo. */
    private function rawWithPlus1q(?array $current, ?array $ago): array
    {
        return [
            'earningsTrend' => [
                'trend' => [
                    [
                        'period'   => '0q',
                        'epsTrend' => ['current' => $this->num(5.0), '90daysAgo' => $this->num(5.0)],
                    ],
                    [
                        'period'   => '+1q',
                        'epsTrend' => array_filter([
                            'current'   => $current,
                            '90daysAgo' => $ago,
                        ], static fn ($v) => $v !== null),
                    ],
                ],
            ],
        ];
    }

    public function test_detects_estimate_cut_as_negative_fraction(): void
    {
        $raw = $this->rawWithPlus1q($this->num(1.74), $this->num(2.00));

        $pct = EarningsTrendParser::revisionPct($raw);

        $this->assertNotNull($pct);
        // (1.74 / 2.00) - 1 = -0.13
        $this->assertEqualsWithDelta(-0.13, $pct, 0.0005);
    }

    public function test_detects_estimate_raise_as_positive_fraction(): void
    {
        $raw = $this->rawWithPlus1q($this->num(2.30), $this->num(2.00));

        $pct = EarningsTrendParser::revisionPct($raw);

        $this->assertNotNull($pct);
        // (2.30 / 2.00) - 1 = 0.15
        $this->assertEqualsWithDelta(0.15, $pct, 0.0005);
    }

    public function test_returns_null_when_90_days_ago_is_zero(): void
    {
        $raw = $this->rawWithPlus1q($this->num(1.74), $this->num(0.0));

        $this->assertNull(EarningsTrendParser::revisionPct($raw));
    }

    public function test_returns_null_when_90_days_ago_is_missing(): void
    {
        $raw = $this->rawWithPlus1q($this->num(1.74), null);

        $this->assertNull(EarningsTrendParser::revisionPct($raw));
    }

    public function test_returns_null_when_current_is_missing(): void
    {
        $raw = $this->rawWithPlus1q(null, $this->num(2.00));

        $this->assertNull(EarningsTrendParser::revisionPct($raw));
    }

    public function test_returns_null_when_plus1q_period_absent(): void
    {
        $raw = [
            'earningsTrend' => [
                'trend' => [
                    [
                        'period'   => '0q',
                        'epsTrend' => ['current' => $this->num(5.0), '90daysAgo' => $this->num(5.0)],
                    ],
                    [
                        'period'   => '+1y',
                        'epsTrend' => ['current' => $this->num(8.0), '90daysAgo' => $this->num(7.5)],
                    ],
                ],
            ],
        ];

        $this->assertNull(EarningsTrendParser::revisionPct($raw));
    }

    public function test_returns_null_when_earnings_trend_module_absent(): void
    {
        $this->assertNull(EarningsTrendParser::revisionPct([]));
    }

    public function test_returns_null_when_trend_is_not_an_array(): void
    {
        $this->assertNull(EarningsTrendParser::revisionPct(['earningsTrend' => ['trend' => 'not-an-array']]));
    }

    public function test_returns_null_when_eps_trend_block_is_not_an_array(): void
    {
        $raw = [
            'earningsTrend' => [
                'trend' => [
                    ['period' => '+1q', 'epsTrend' => 'not-an-array'],
                ],
            ],
        ];

        $this->assertNull(EarningsTrendParser::revisionPct($raw));
    }

    /** @return array<string, mixed> raw payload with a +1q row carrying the given epsRevisions. */
    private function rawWithRevisions(?array $revisions): array
    {
        $row = ['period' => '+1q'];
        if ($revisions !== null) {
            $row['epsRevisions'] = $revisions;
        }

        return [
            'earningsTrend' => [
                'trend' => [
                    ['period' => '0q', 'epsRevisions' => ['upLast30days' => $this->num(1.0), 'downLast30days' => $this->num(1.0)]],
                    $row,
                ],
            ],
        ];
    }

    public function test_breadth_positive_when_more_upgrades_than_downgrades(): void
    {
        $raw = $this->rawWithRevisions(['upLast30days' => $this->num(8.0), 'downLast30days' => $this->num(2.0)]);

        // (8 - 2) / (8 + 2) = 0.6
        $this->assertEqualsWithDelta(0.6, EarningsTrendParser::revisionBreadth($raw), 0.0001);
    }

    public function test_breadth_negative_when_more_downgrades_than_upgrades(): void
    {
        $raw = $this->rawWithRevisions(['upLast30days' => $this->num(1.0), 'downLast30days' => $this->num(4.0)]);

        // (1 - 4) / (1 + 4) = -0.6
        $this->assertEqualsWithDelta(-0.6, EarningsTrendParser::revisionBreadth($raw), 0.0001);
    }

    public function test_breadth_returns_null_when_denominator_zero(): void
    {
        $raw = $this->rawWithRevisions(['upLast30days' => $this->num(0.0), 'downLast30days' => $this->num(0.0)]);

        $this->assertNull(EarningsTrendParser::revisionBreadth($raw));
    }

    public function test_breadth_returns_null_when_eps_revisions_missing(): void
    {
        $raw = $this->rawWithRevisions(null);

        $this->assertNull(EarningsTrendParser::revisionBreadth($raw));
    }

    public function test_breadth_returns_null_when_plus1q_period_absent(): void
    {
        $raw = [
            'earningsTrend' => [
                'trend' => [
                    ['period' => '0q', 'epsRevisions' => ['upLast30days' => $this->num(8.0), 'downLast30days' => $this->num(2.0)]],
                ],
            ],
        ];

        $this->assertNull(EarningsTrendParser::revisionBreadth($raw));
    }

    public function test_breadth_returns_null_when_earnings_trend_module_absent(): void
    {
        $this->assertNull(EarningsTrendParser::revisionBreadth([]));
    }

    public function test_breadth_returns_null_when_fields_missing(): void
    {
        $raw = $this->rawWithRevisions(['upLast30days' => $this->num(5.0)]);

        $this->assertNull(EarningsTrendParser::revisionBreadth($raw));
    }
}
