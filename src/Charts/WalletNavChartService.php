<?php

declare(strict_types=1);

namespace CVS\Charts;

use CVS\Api\FinancialDataFetcher;
use CVS\Lab\LabMetrics;
use CVS\LlmFree\LlmFreeCycleRepository;
use CVS\LlmGemini\LlmGeminiCycleRepository;
use CVS\LlmGptLuna\LlmGptLunaCycleRepository;
use CVS\Portfolio\CycleRepository;

/**
 * Builds the NAV comparison chart (LLM Bazowy, LLM Free, LLM Gemini, LLM GPT
 * Luna, S&P 500, Nasdaq 100) shown on /portfolio, /llm-free, /llm-gemini and
 * /llm-gpt-luna (change: wallet-nav-chart, extended by llm-gemini-wallet and
 * llm-gpt-luna-wallet) — same visual pattern as /lab, so a page switching
 * between wallets sees a consistent chart.
 *
 * Deliberately outside CVS\Portfolio\, CVS\LlmFree\, CVS\LlmGemini\, and
 * CVS\LlmGptLuna\: it reads from each module's own repository (never their
 * raw tables — same "sanctioned read via the owning repository" rule
 * LabRepository::getLlmValueSeries() already established) plus CVS\Api\, so
 * no single module is a natural owner.
 *
 * Both the Gemini and GPT Luna series are optional constructor params, still
 * nullable for test convenience and backward compatibility, but all four
 * callers (/portfolio, /llm-free, /llm-gemini, /llm-gpt-luna) now pass every
 * cycle repository — every wallet page shows the identical four-wallet +
 * two-benchmark comparison.
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
        private readonly ?LlmGeminiCycleRepository $llmGeminiCycles = null,
        private readonly ?LlmGptLunaCycleRepository $llmGptLunaCycles = null,
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
            $this->llmGeminiCycles?->getValueSeries(),
            $this->llmGptLunaCycles?->getValueSeries(),
        );
    }

    /**
     * @param list<array{date: string, value: float}>   $portfolioSeries  LLM Bazowy, oldest first
     * @param list<array{date: string, value: float}>   $llmFreeSeries    LLM Free, oldest first
     * @param array{date: string[], close: float[]}|null $spy             null when the fetch failed
     * @param array{date: string[], close: float[]}|null $qqq             null when the fetch failed
     * @param list<array{date: string, value: float}>|null $llmGeminiSeries LLM Gemini, oldest first; null = series omitted entirely (backward-compat default)
     * @param list<array{date: string, value: float}>|null $llmGptLunaSeries LLM GPT Luna, oldest first; null = series omitted entirely (backward-compat default)
     * @return array{chartSeries: array<string, list<array{date: string, value: float}>>, d0: string|null}
     */
    public static function build(array $portfolioSeries, array $llmFreeSeries, ?array $spy, ?array $qqq, ?array $llmGeminiSeries = null, ?array $llmGptLunaSeries = null): array
    {
        $d0 = null;
        foreach ([$portfolioSeries, $llmFreeSeries, $llmGeminiSeries ?? [], $llmGptLunaSeries ?? []] as $series) {
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

        if ($llmGeminiSeries !== null) {
            $chartSeries['LLM Gemini'] = LabMetrics::normaliseToBase100($llmGeminiSeries, 'value');
        }

        if ($llmGptLunaSeries !== null) {
            $chartSeries['LLM GPT Luna'] = LabMetrics::normaliseToBase100($llmGptLunaSeries, 'value');
        }

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
