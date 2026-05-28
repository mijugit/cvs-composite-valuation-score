<?php

declare(strict_types=1);

namespace CVS\Tests\Forecast;

use CVS\Forecast\ForecastParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ForecastParser (S-09).
 *
 * Pure offline tests using synthetic raw Yahoo payloads — no network, no fetcher.
 */
class ForecastParserTest extends TestCase
{
    /** Yahoo wraps numerics as {"raw": x, "fmt": "y"}. */
    private function num(float $x): array
    {
        return ['raw' => $x, 'fmt' => (string) $x];
    }

    /** @return array<string, mixed> A fully-covered XOM-like raw payload. */
    private function fullRaw(): array
    {
        return [
            'financialData' => [
                'currentPrice'            => $this->num(146.96),
                'targetMeanPrice'         => $this->num(169.18),
                'targetMedianPrice'       => $this->num(171.5),
                'targetHighPrice'         => $this->num(185.0),
                'targetLowPrice'          => $this->num(130.0),
                'numberOfAnalystOpinions' => $this->num(25),
                'recommendationMean'      => $this->num(2.1),
                'recommendationKey'       => 'buy',
            ],
            'recommendationTrend' => [
                'trend' => [
                    ['period' => '0m',  'strongBuy' => 7, 'buy' => 4, 'hold' => 13, 'sell' => 0, 'strongSell' => 1],
                    ['period' => '-1m', 'strongBuy' => 6, 'buy' => 5, 'hold' => 12, 'sell' => 1, 'strongSell' => 1],
                    ['period' => '-2m', 'strongBuy' => 6, 'buy' => 4, 'hold' => 13, 'sell' => 1, 'strongSell' => 0],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // parse() — full coverage
    // ------------------------------------------------------------------

    public function test_parse_extracts_all_targets(): void
    {
        $f = ForecastParser::parse($this->fullRaw(), 146.96);

        $this->assertSame(169.18, $f['targets']['mean']);
        $this->assertSame(171.5,  $f['targets']['median']);
        $this->assertSame(185.0,  $f['targets']['high']);
        $this->assertSame(130.0,  $f['targets']['low']);
        $this->assertSame(25,     $f['num_analysts']);
        $this->assertSame(2.1,    $f['recommendation_mean']);
        $this->assertSame('buy',  $f['recommendation_key']);
    }

    public function test_parse_computes_positive_upside(): void
    {
        $f = ForecastParser::parse($this->fullRaw(), 146.96);

        // (169.18 - 146.96) / 146.96 ≈ 0.1512
        $this->assertNotNull($f['targets']['upside']);
        $this->assertEqualsWithDelta(0.1512, $f['targets']['upside'], 0.0005);
    }

    public function test_parse_computes_negative_upside_below_target(): void
    {
        $f = ForecastParser::parse($this->fullRaw(), 200.0);
        $this->assertLessThan(0, $f['targets']['upside']);
    }

    public function test_parse_preserves_trend_order_newest_first(): void
    {
        $f = ForecastParser::parse($this->fullRaw(), 146.96);

        $periods = array_column($f['trend'], 'period');
        $this->assertSame(['0m', '-1m', '-2m'], $periods);
        $this->assertSame(7, $f['trend'][0]['strong_buy']);
        $this->assertSame(1, $f['trend'][0]['strong_sell']);
    }

    public function test_parse_latest_is_current_period(): void
    {
        $f = ForecastParser::parse($this->fullRaw(), 146.96);

        $this->assertSame(
            ['strong_buy' => 7, 'buy' => 4, 'hold' => 13, 'sell' => 0, 'strong_sell' => 1],
            $f['latest']
        );
    }

    // ------------------------------------------------------------------
    // parse() — partial / missing coverage
    // ------------------------------------------------------------------

    public function test_parse_missing_targets_still_parses_trend(): void
    {
        $raw = $this->fullRaw();
        unset(
            $raw['financialData']['targetMeanPrice'],
            $raw['financialData']['targetMedianPrice'],
            $raw['financialData']['targetHighPrice'],
            $raw['financialData']['targetLowPrice']
        );

        $f = ForecastParser::parse($raw, 146.96);

        $this->assertNull($f['targets']['mean']);
        $this->assertNull($f['targets']['upside']);
        $this->assertCount(3, $f['trend']);
        $this->assertNotNull($f['latest']);
    }

    public function test_parse_empty_trend_yields_null_latest(): void
    {
        $raw = $this->fullRaw();
        unset($raw['recommendationTrend']);

        $f = ForecastParser::parse($raw, 146.96);

        $this->assertSame([], $f['trend']);
        $this->assertNull($f['latest']);
        // Targets still present.
        $this->assertSame(169.18, $f['targets']['mean']);
    }

    public function test_parse_zero_analysts_means_no_targets(): void
    {
        $raw = $this->fullRaw();
        $raw['financialData']['numberOfAnalystOpinions'] = $this->num(0);

        $f = ForecastParser::parse($raw, 146.96);

        $this->assertNull($f['targets']['mean']);
        $this->assertNull($f['targets']['high']);
        $this->assertNull($f['targets']['upside']);
        $this->assertSame(0, $f['num_analysts']);
    }

    public function test_parse_no_current_price_yields_null_upside(): void
    {
        $f = ForecastParser::parse($this->fullRaw(), null);

        $this->assertSame(169.18, $f['targets']['mean']);
        $this->assertNull($f['targets']['upside']);
    }

    public function test_parse_empty_raw_is_structurally_stable(): void
    {
        $f = ForecastParser::parse([], 100.0);

        $this->assertNull($f['targets']['mean']);
        $this->assertNull($f['num_analysts']);
        $this->assertNull($f['recommendation_mean']);
        $this->assertNull($f['recommendation_key']);
        $this->assertSame([], $f['trend']);
        $this->assertNull($f['latest']);
    }

    // ------------------------------------------------------------------
    // consensusLabel() — threshold boundaries
    // ------------------------------------------------------------------

    #[DataProvider('consensusBoundaryProvider')]
    public function test_consensus_label_boundaries(float $mean, string $expected): void
    {
        $thresholds = ['strong_buy' => 1.5, 'buy' => 2.5, 'hold' => 3.5, 'sell' => 4.5];
        $this->assertSame($expected, ForecastParser::consensusLabel($mean, $thresholds));
    }

    /** @return array<string, array{float, string}> */
    public static function consensusBoundaryProvider(): array
    {
        return [
            'strong buy low'   => [1.0, 'Silne Kupuj'],
            'strong buy edge'  => [1.5, 'Silne Kupuj'],
            'buy edge'         => [2.5, 'Kupuj'],
            'hold mid'         => [3.0, 'Trzymaj'],
            'hold edge'        => [3.5, 'Trzymaj'],
            'sell edge'        => [4.5, 'Sprzedaj'],
            'strong sell high' => [5.0, 'Silna Sprzedaż'],
        ];
    }
}
