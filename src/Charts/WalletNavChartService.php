<?php

declare(strict_types=1);

namespace CVS\Charts;

use CVS\Api\FinancialDataFetcher;
use CVS\Lab\LabMetrics;
use CVS\LlmFree\LlmFreeCycleRepository;
use CVS\Portfolio\CycleRepository;

/**
 * Builds the four-series NAV comparison chart (LLM Bazowy, LLM Free, S&P 500,
 * Nasdaq 100) shown on both /portfolio and /llm-free (change: wallet-nav-chart)
 * — same visual pattern as /lab, so a page switching between the two wallets
 * sees a consistent chart.
 *
 * Deliberately outside both CVS\Portfolio\ and CVS\LlmFree\: it reads from
 * both modules' own repositories (never their raw tables — same "sanctioned
 * read via the owning repository" rule LabRepository::getLlmValueSeries()
 * already established) plus CVS\Api\, so neither module is a natural owner.
 *
 * build() is pure (no I/O) and unit-tested directly; fetch() is the thin,
 * untested wiring layer that gathers the raw inputs — same split as
 * FinancialDataFetcher (fetch, not tested) vs LabMetrics (pure, tested).
 */
final class WalletNavChartService
{
    public function __construct(
        private readonly CycleRepository $portfolioCycles,
        private readonly LlmFreeCycleRepository $llmFreeCycles,
        private readonly FinancialDataFetcher $fetcher,
    ) {
    }

    /**
     * @return array{chartSeries: array<string, list<array{date: string, value: float}>>, d0: string|null}
     */
    public function fetch(): array
    {
        return self::build(
            $this->portfolioCycles->getValueSeries(),
            $this->llmFreeCycles->getValueSeries(),
            $this->fetcher->fetchSpyDailyCloses(),
            $this->fetcher->fetchDailyCloses('QQQ'),
        );
    }

    /**
     * @param list<array{date: string, value: float}>   $portfolioSeries LLM Bazowy, oldest first
     * @param list<array{date: string, value: float}>   $llmFreeSeries   LLM Free, oldest first
     * @param array{date: string[], close: float[]}|null $spy            null when the fetch failed
     * @param array{date: string[], close: float[]}|null $qqq            null when the fetch failed
     * @return array{chartSeries: array<string, list<array{date: string, value: float}>>, d0: string|null}
     */
    public static function build(array $portfolioSeries, array $llmFreeSeries, ?array $spy, ?array $qqq): array
    {
        $d0 = null;
        foreach ([$portfolioSeries, $llmFreeSeries] as $series) {
            if ($series === []) {
                continue;
            }
            $first = (string) $series[0]['date'];
            if ($d0 === null || $first < $d0) {
                $d0 = $first;
            }
        }

        if ($d0 === null) {
            return ['chartSeries' => [], 'd0' => null];
        }

        $chartSeries = [
            'LLM Bazowy' => LabMetrics::normaliseToBase100($portfolioSeries, 'value'),
            'LLM Free'   => LabMetrics::normaliseToBase100($llmFreeSeries, 'value'),
        ];

        // Benchmarks are rebased from their value AT $d0 (not their own fetch
        // window's first point, which is a full year back) so their 100 lines
        // up with the wallets' own start — otherwise the chart would compare a
        // few weeks of wallet history against a full year of benchmark drift.
        if ($spy !== null) {
            $series = self::closesSince($spy, $d0);
            if ($series !== []) {
                $chartSeries['S&P 500'] = LabMetrics::normaliseToBase100($series, 'close');
            }
        }
        if ($qqq !== null) {
            $series = self::closesSince($qqq, $d0);
            if ($series !== []) {
                $chartSeries['Nasdaq 100'] = LabMetrics::normaliseToBase100($series, 'close');
            }
        }

        return ['chartSeries' => $chartSeries, 'd0' => $d0];
    }

    /**
     * @param array{date: string[], close: float[]} $raw
     * @return list<array{date: string, close: float}>
     */
    private static function closesSince(array $raw, string $sinceDate): array
    {
        $out = [];
        foreach ($raw['date'] as $i => $date) {
            $date = (string) $date;
            if ($date < $sinceDate || !isset($raw['close'][$i])) {
                continue;
            }
            $out[] = ['date' => $date, 'close' => (float) $raw['close'][$i]];
        }
        return $out;
    }
}
