<?php

declare(strict_types=1);

namespace CVS\Tests\Forecast;

use CVS\Forecast\EarningsSurpriseParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EarningsSurpriseParser (Phase 7, slice 2).
 *
 * Pure offline tests using synthetic raw Yahoo earningsHistory payloads — no
 * network, no fetcher (mirrors EarningsTrendParserTest conventions).
 */
class EarningsSurpriseParserTest extends TestCase
{
    /** Yahoo wraps numerics as {"raw": x, "fmt": "y"}. */
    private function num(float $x): array
    {
        return ['raw' => $x, 'fmt' => (string) $x];
    }

    /** @return array<string, mixed> raw payload with a 4-quarter earningsHistory.history (oldest first). */
    private function rawWithHistory(array $surprises, ?array $quarters = null): array
    {
        $history = [];
        foreach ($surprises as $i => $surprise) {
            $row = [];
            if ($quarters !== null) {
                $row['quarter'] = $this->num($quarters[$i]);
            }
            if ($surprise !== null) {
                $row['surprisePercent'] = $this->num($surprise);
            }
            $history[] = $row;
        }

        return ['earningsHistory' => ['history' => $history]];
    }

    public function test_surprise_pct_picks_most_recent_quarter_by_epoch(): void
    {
        // Out-of-order quarters: epoch 200 (surprise -0.02) should win over 100 (0.05).
        $raw = [
            'earningsHistory' => [
                'history' => [
                    ['quarter' => $this->num(100), 'surprisePercent' => $this->num(0.05)],
                    ['quarter' => $this->num(200), 'surprisePercent' => $this->num(-0.02)],
                ],
            ],
        ];

        $this->assertEqualsWithDelta(-0.02, EarningsSurpriseParser::surprisePct($raw), 0.0001);
    }

    public function test_surprise_pct_falls_back_to_last_entry_without_quarter(): void
    {
        $raw = $this->rawWithHistory([0.01, 0.02, -0.03]);

        $this->assertEqualsWithDelta(-0.03, EarningsSurpriseParser::surprisePct($raw), 0.0001);
    }

    public function test_surprise_pct_returns_null_when_module_absent(): void
    {
        $this->assertNull(EarningsSurpriseParser::surprisePct([]));
    }

    public function test_surprise_pct_returns_null_when_history_empty(): void
    {
        $this->assertNull(EarningsSurpriseParser::surprisePct(['earningsHistory' => ['history' => []]]));
    }

    public function test_surprise_pct_returns_null_when_most_recent_row_missing_field(): void
    {
        $raw = $this->rawWithHistory([null]);

        $this->assertNull(EarningsSurpriseParser::surprisePct($raw));
    }

    public function test_beat_count_counts_positive_surprises(): void
    {
        $raw = $this->rawWithHistory([0.05, -0.01, 0.02, 0.0]);

        $this->assertSame(2, EarningsSurpriseParser::beatCount($raw));
    }

    public function test_beat_count_zero_is_distinct_from_null(): void
    {
        $raw = $this->rawWithHistory([-0.05, -0.01, 0.0]);

        $this->assertSame(0, EarningsSurpriseParser::beatCount($raw));
    }

    public function test_beat_count_returns_null_when_module_absent(): void
    {
        $this->assertNull(EarningsSurpriseParser::beatCount([]));
    }

    public function test_beat_count_returns_null_when_history_empty(): void
    {
        $this->assertNull(EarningsSurpriseParser::beatCount(['earningsHistory' => ['history' => []]]));
    }

    public function test_beat_count_caps_at_last_4_quarters(): void
    {
        // 5 quarters, only the last 4 (all beats) should count -> 4, not 5.
        $raw = $this->rawWithHistory([-0.10, 0.01, 0.02, 0.03, 0.04]);

        $this->assertSame(4, EarningsSurpriseParser::beatCount($raw));
    }
}
