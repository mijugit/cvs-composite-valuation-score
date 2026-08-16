<?php

declare(strict_types=1);

namespace CVS\Tests\Ai;

use CVS\Ai\AiDivergenceService;
use CVS\Ai\ClaudeClient;
use CVS\Ai\ValuationNarrative;
use PHPUnit\Framework\TestCase;

/**
 * The data block must describe the metric the model actually used.
 *
 * Reviewing ALR.WA on 2026-08-16 — a bank, scored on price/book since variant C
 * shipped — an external model was told the score came from EV/FCF. It then
 * spent a section of its critique explaining that free cash flow is a poor
 * measure for a deposit-taking institution: a correct statement about a method
 * the model had already stopped using. The prompt described a defect that no
 * longer existed and the reviewer found it, exactly as asked.
 */
class PromptVariantLabellingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function clientConfig(): array
    {
        return [
            'api_key' => 'sk-ant-test', 'base_url' => 'https://example.invalid',
            'model' => 'claude-test', 'anthropic_version' => '2023-06-01',
            'max_tokens' => 2048, 'timeout' => 5, 'max_retries' => 0,
            'total_timeout' => 10, 'retry_base_delay_ms' => 0,
        ];
    }

    private function service(): AiDivergenceService
    {
        return new AiDivergenceService(new ClaudeClient($this->clientConfig(), new FakeTransport([])));
    }

    /**
     * @param array<string, mixed> $valuationReference
     * @return array<string, mixed>
     */
    private function cvsResult(array $valuationReference): array
    {
        return [
            'ticker'        => 'ALR.WA',
            'quality_gate'  => true,
            'swing'         => ['cvs' => 58.1, 'recommendation' => 'AKUMULUJ'],
            'fundamental'   => ['cvs' => 65.0, 'recommendation' => 'AKUMULUJ'],
            'pillar_scores' => ['valuation' => 67.1, 'momentum_swing' => 41.2, 'momentum_fund' => 29.4, 'quality' => 85.0],
            'valuation_reference' => $valuationReference,
        ];
    }

    /** @return array<string, mixed> */
    private function bank(): array
    {
        return [
            'sector'   => 'Financial Services',
            'industry' => 'Banks - Diversified',
            'current_price' => 35.48,
            'price_to_book' => 1.38,
            'book_value_per_share' => 33.71,
            'return_on_equity' => 0.168,
            'return_on_assets' => 0.0141,
            'payout_ratio' => 0.42,
            'pe_ratio' => 8.52,
            'benchmark_label' => 'WIG20TR',
        ];
    }

    private function block(): string
    {
        return $this->service()->buildDataBlock(
            'ALR.WA',
            $this->cvsResult([
                'source'          => 'subsector',
                'bucket'          => 'Banks - Diversified',
                'variant'         => 'C',
                'roe_conditioned' => true,
            ]),
            $this->bank(),
            46.52,
            null
        );
    }

    /**
     * Variant C divides the book multiple by ROE. Saying "P/B vs peer median"
     * invites the reviewer to re-read the raw 2.96 against a ~1.7 median and
     * conclude the bank is expensive — the very reasoning the model stopped
     * doing. The prompt lagged the pillar by a day when this was added.
     */
    public function test_roe_conditioning_is_named_in_the_block(): void
    {
        $block = $this->block();

        $this->assertStringContainsString('P/B ÷ ROE vs the peer median', $block);
        $this->assertStringContainsString('its P/B ÷ ROE was compared to peers', $block);
        $this->assertStringContainsString('peer_median(P/B ÷ ROE) × this company\'s ROE × book value per share', $block);
    }

    public function test_bank_without_positive_roe_says_so(): void
    {
        $block = $this->service()->buildDataBlock(
            'XYZ.WA',
            $this->cvsResult([
                'source'          => 'subsector',
                'bucket'          => 'Banks - Regional',
                'variant'         => 'C',
                'roe_conditioned' => false,
            ]),
            $this->bank(),
            10.0,
            null
        );

        $this->assertStringContainsString('no positive ROE', $block);
        $this->assertStringNotContainsString('P/B ÷ ROE vs the peer median', $block);
    }

    /**
     * A declined score and a "fairly valued" score are both 50. Without the
     * note they read identically, which is the neutral-value blind spot that
     * hid the missing share counts for weeks.
     */
    public function test_declined_score_is_not_presented_as_a_verdict(): void
    {
        $block = $this->service()->buildDataBlock(
            'HSBC',
            $this->cvsResult([
                'source'          => 'implausible_pb',
                'bucket'          => 'Banks - Diversified',
                'variant'         => 'C',
                'roe_conditioned' => true,
            ]),
            $this->bank(),
            null,
            null
        );

        $this->assertStringContainsString('that 50 is NOT a judgement', $block);
        $this->assertStringContainsString('Treat the Valuation pillar as ABSENT', $block);
    }

    public function test_a_bank_is_not_described_as_scored_on_ev_fcf(): void
    {
        $block = $this->block();

        $this->assertStringContainsString('P/B ÷ ROE vs the peer median', $block);
        // The block may still say the words "EV/FCF" — it does, to rule the
        // multiple OUT — so assert on the claims, not on the substring.
        $this->assertStringNotContainsString('Valuation (EV/FCF', $block);
        $this->assertStringNotContainsString('median_EV/FCF', $block);
        $this->assertStringContainsString('It is NOT an EV/FCF or DCF-derived figure', $block);
    }

    public function test_quality_line_names_the_financial_path(): void
    {
        $block = $this->block();

        $this->assertStringContainsString('ROE, ROA, dividend-payout sanity', $block);
        $this->assertStringNotContainsString('gross margin vs sector', $block);
    }

    public function test_fair_value_method_matches_the_variant(): void
    {
        $block = $this->block();

        $this->assertStringContainsString('× this company\'s ROE × book value per share', $block);
        $this->assertStringNotContainsString('Forward_FCF', $block);
    }

    /**
     * A reviewer asked to check a price/book score needs the price/book, and a
     * returns-based quality score needs the returns. None were sent before.
     */
    public function test_block_carries_the_metrics_the_financial_paths_use(): void
    {
        $block = $this->block();

        $this->assertStringContainsString('Price / book', $block);
        $this->assertStringContainsString('Book value per share', $block);
        $this->assertStringContainsString('Return on assets', $block);
        $this->assertStringContainsString('Dividend payout ratio', $block);
    }

    public function test_peer_group_note_names_the_right_multiple(): void
    {
        $this->assertStringContainsString('its P/B ÷ ROE was compared to peers', $this->block());
    }

    /** An ordinary company must be unaffected. */
    public function test_variant_a_still_reads_as_ev_fcf(): void
    {
        $block = $this->service()->buildDataBlock(
            'AAPL',
            $this->cvsResult(['source' => 'subsector', 'bucket' => 'Consumer Electronics', 'variant' => 'A']),
            ['sector' => 'Technology', 'industry' => 'Consumer Electronics', 'current_price' => 300.0],
            350.0,
            null
        );

        $this->assertStringContainsString('forward EV/FCF vs peer median', $block);
        $this->assertStringContainsString('gross margin vs sector', $block);
        $this->assertStringNotContainsString('Price / book', $block);
    }

    public function test_real_estate_reads_as_ev_ebitda(): void
    {
        $block = $this->service()->buildDataBlock(
            'O',
            $this->cvsResult(['source' => 'sector_fallback', 'bucket' => 'Real Estate', 'variant' => 'D']),
            ['sector' => 'Real Estate', 'industry' => 'REIT - Retail', 'current_price' => 55.0],
            null,
            null
        );

        $this->assertStringContainsString('EV/EBITDA vs peer median', $block);
    }

    // ------------------------------------------------------------------
    // The mapping itself
    // ------------------------------------------------------------------

    public function test_unknown_variant_says_so_rather_than_guessing(): void
    {
        // Snapshots written before migration 036 carry no variant. Naming a
        // multiple we cannot confirm is what caused this whole problem.
        $label = ValuationNarrative::valuationLabel(null);

        $this->assertStringContainsString('variant not recorded', $label);
        $this->assertStringNotContainsString('EV/FCF', $label);
    }

    public function test_metric_names_are_stable(): void
    {
        $this->assertSame('EV/FCF', ValuationNarrative::metricName('A'));
        $this->assertSame('EV/Sales', ValuationNarrative::metricName('B'));
        $this->assertSame('P/B', ValuationNarrative::metricName('C'));
        $this->assertSame('EV/EBITDA', ValuationNarrative::metricName('D'));
    }

    public function test_financial_sector_detection_follows_config(): void
    {
        $cfg = ['financials' => ['sectors' => ['Financial Services']]];

        $this->assertTrue(ValuationNarrative::isFinancialSector('Financial Services', $cfg));
        $this->assertFalse(ValuationNarrative::isFinancialSector('Technology', $cfg));
        $this->assertFalse(ValuationNarrative::isFinancialSector('Financial Services', []));
    }
}
