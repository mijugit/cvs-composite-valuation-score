<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Builds the prompt and calls Claude API to generate a 4-section AI analysis
 * explaining the divergence between the CVS model score and analyst consensus.
 *
 * - System prompt: stable (cacheable, TTL 5m) — expert role, section structure,
 *   anti-hallucination guardrail, Polish response requirement.
 * - User message: per-ticker data — CVS scores, pillars, analyst forecast.
 * - Returns AiResult; never throws. Caller handles ok/failure.
 */
class AiDivergenceService
{
    public function __construct(
        private readonly ClaudeClient $client
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Generate the divergence analysis narrative.
     *
     * @param array<string, mixed> $cvsResult    CVSResult::toArray()
     * @param array<string, mixed> $financials   FinancialDataFetcher output
     * @param float|null           $cvsFairPrice Sector-parity implied price (null = not calculable)
     */
    public function generate(
        string $ticker,
        array  $cvsResult,
        array  $financials,
        ?float $cvsFairPrice = null
    ): AiResult {
        $system  = $this->buildSystemPrompt();
        $message = $this->buildUserMessage($ticker, $cvsResult, $financials, $cvsFairPrice);

        return $this->client->sendMessage(
            [['role' => 'user', 'content' => $message]],
            $system
        );
    }

    // ------------------------------------------------------------------
    // Prompt building
    // ------------------------------------------------------------------

    private function buildSystemPrompt(): CacheableSystem
    {
        $text = <<<'SYSTEM'
You are an expert financial analyst specializing in quantitative investment models.
Your task is to analyze and explain the divergence between a CVS (Composite Valuation Score)
quantitative model and Wall Street analyst consensus for a given publicly traded company.

CRITICAL GROUNDING RULE: Base your analysis ONLY on the numerical data provided in the
user message. Do not speculate about, invent, or reference any financial facts, news events,
or company information not present in the provided data. If a data point is missing,
acknowledge its absence rather than assuming any value.

Structure your response in exactly these 4 sections using the exact headers below:

## 1. Ocena modelu CVS
Explain what the CVS Swing and Fundamental scores indicate. Interpret the pillar
breakdown — which pillars are strong or weak, and how they drive the overall score.
Connect the pillar scores to their real-world meaning (e.g., high Valuation pillar
means the stock looks cheap relative to sector peers on EV/FCF basis).
If CVS Model Implied Fair Value is provided, explain briefly what it means:
it is the theoretical stock price at which the company's EV/FCF multiple would equal
the sector median — i.e., the "sector-parity price" derived from the model's own
inputs (Forward FCF², Net Debt, Shares Outstanding). Note whether the current price
is at a premium or discount to this implied fair value.

## 2. Opinia rynku (analitycy)
Summarize what analysts collectively think: their consensus recommendation, price
targets (low/mean/high), and upside/downside potential relative to current price.
If no analyst data is provided, state clearly that analyst coverage is unavailable.

## 3. Analiza rozjazdu
Explain concisely WHY the CVS model and analysts disagree. Identify the specific
pillar or metric driving the gap. For example: the model may score Valuation high
(cheap on EV/FCF) while analysts see a Growth risk that lowers their targets —
or Momentum is strong but analysts expect mean-reversion. Be specific and grounded
in the numbers provided. If CVS Fair Value is available, reference it when discussing
the valuation gap: compare the CVS implied fair value to analyst mean price target
to show where each methodology places "fair" for this stock.

## 4. Komu wierzyć i w jakim horyzoncie
Provide a practical take: under what conditions and in which time horizon would
you follow the CVS model (Swing = 1-4 months, Fundamental = 6-12 months) vs
analyst consensus? Acknowledge uncertainty explicitly.

OUTPUT REQUIREMENTS:
- Write entirely in Polish. Use formal but accessible language suitable for
  an individual investor.
- Aim for 400-600 words total across all four sections.
- End the response with this exact disclaimer on a new line:
  "⚠️ Powyższa analiza to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie."
SYSTEM;

        return new CacheableSystem($text, CacheableSystem::TTL_5M);
    }

    /**
     * @param array<string, mixed> $cvsResult
     * @param array<string, mixed> $financials
     */
    private function buildUserMessage(
        string $ticker,
        array  $cvsResult,
        array  $financials,
        ?float $cvsFairPrice = null
    ): string {
        $swing   = $cvsResult['swing']       ?? [];
        $fund    = $cvsResult['fundamental'] ?? [];
        $pillars = $cvsResult['pillar_scores'] ?? [];

        $cvsSwing = isset($swing['cvs'])   ? number_format((float) $swing['cvs'],   1) : 'N/A';
        $cvsFund  = isset($fund['cvs'])    ? number_format((float) $fund['cvs'],    1) : 'N/A';
        $recoSwing = $swing['recommendation'] ?? 'N/A';
        $recoFund  = $fund['recommendation']  ?? 'N/A';

        $pVal      = isset($pillars['valuation'])      ? number_format((float) $pillars['valuation'],      1) : 'N/A';
        $pMomSwing = isset($pillars['momentum_swing']) ? number_format((float) $pillars['momentum_swing'], 1) : 'N/A';
        $pMomFund  = isset($pillars['momentum_fund'])  ? number_format((float) $pillars['momentum_fund'],  1) : 'N/A';
        $pQual     = isset($pillars['quality'])        ? number_format((float) $pillars['quality'],        1) : 'N/A';

        $sector   = (string) ($financials['sector']    ?? 'N/A');
        $forecast = $financials['forecast'] ?? null;

        $lines = [];
        $lines[] = "COMPANY: {$ticker}";
        $lines[] = "SECTOR: {$sector}";
        $lines[] = '';
        $lines[] = 'CVS MODEL SCORES:';
        $lines[] = "- Swing (1-4 month horizon): {$cvsSwing}/100 → {$recoSwing}";
        $lines[] = "- Fundamental (6-12 month horizon): {$cvsFund}/100 → {$recoFund}";
        $lines[] = '';
        $lines[] = 'PILLAR BREAKDOWN (each 0-100):';
        $lines[] = "- Valuation (EV/FCF vs sector median): {$pVal}/100";
        $lines[] = "- Momentum - Swing profile: {$pMomSwing}/100";
        $lines[] = "- Momentum - Fundamental profile: {$pMomFund}/100";
        $lines[] = "- Quality (gross margin, leverage, growth): {$pQual}/100";
        $lines[] = '';
        $lines[] = 'CVS MODEL IMPLIED FAIR VALUE (Sector-Parity Price):';
        if ($cvsFairPrice !== null) {
            $curPrice  = (float) ($financials['current_price'] ?? 0);
            $fairFmt   = '$' . number_format($cvsFairPrice, 2);
            $lines[]   = "- Fair Value: {$fairFmt}";
            if ($curPrice > 0) {
                $premium = (($curPrice - $cvsFairPrice) / $cvsFairPrice) * 100;
                $dir     = $premium >= 0 ? 'premium' : 'discount';
                $lines[] = sprintf(
                    '- Current price vs Fair Value: %s %.1f%% %s (current=$%.2f)',
                    $dir === 'premium' ? '+' : '',
                    $premium,
                    $dir,
                    $curPrice
                );
            }
            $bm = $this->getSectorBenchmark($financials);
            if ($bm !== null) {
                $lines[] = '- Calculation method: Fair EV = sector_median_EV/FCF ('
                    . $bm['median_ev_fcf'] . 'x) × Forward_FCF²; '
                    . 'Fair Price = (Fair EV - Net Debt) / Shares Outstanding';
                $lines[] = '- This represents the price at which the stock would be fairly valued '
                    . 'relative to its sector peer median on an EV/FCF basis (Valuation pillar = 50/100).';
            }
        } else {
            $lines[] = '- Not calculable (insufficient FCF or growth data for this company).';
        }
        $lines[] = '';
        $lines[] = 'ANALYST CONSENSUS:';

        if ($forecast === null || (empty($forecast['targets']['mean']) && empty($forecast['recommendation_mean']))) {
            $lines[] = '- No analyst coverage data available for this company.';
        } else {
            $targets  = $forecast['targets']   ?? [];
            $latest   = $forecast['latest']    ?? null;
            $recMean  = $forecast['recommendation_mean'] ?? null;
            $numAnal  = $forecast['num_analysts']        ?? null;

            if ($numAnal !== null) {
                $lines[] = "- Number of analysts: {$numAnal}";
            }

            if ($recMean !== null) {
                $recLabel = $this->analystConsensusLabel((float) $recMean);
                $recMeanFmt = number_format((float) $recMean, 2);
                $lines[] = "- Consensus: {$recLabel} (mean {$recMeanFmt}/5.0 scale; 1=Strong Buy, 5=Strong Sell)";
            }

            $tMean   = isset($targets['mean'])   ? '$' . number_format((float) $targets['mean'],   2) : 'N/A';
            $tLow    = isset($targets['low'])    ? '$' . number_format((float) $targets['low'],    2) : 'N/A';
            $tHigh   = isset($targets['high'])   ? '$' . number_format((float) $targets['high'],   2) : 'N/A';
            $lines[] = "- Price targets: Low={$tLow} | Mean={$tMean} | High={$tHigh}";

            if (isset($targets['upside'])) {
                $upside = number_format((float) $targets['upside'] * 100, 1);
                $lines[] = "- Upside from current price (mean target): {$upside}%";
            }

            if ($latest !== null) {
                $sb = (int) ($latest['strong_buy']  ?? 0);
                $b  = (int) ($latest['buy']         ?? 0);
                $h  = (int) ($latest['hold']        ?? 0);
                $s  = (int) ($latest['sell']        ?? 0);
                $ss = (int) ($latest['strong_sell'] ?? 0);
                $lines[] = "- Distribution: Strong Buy={$sb}, Buy={$b}, Hold={$h}, Sell={$s}, Strong Sell={$ss}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $financials
     * @return array<string, mixed>|null
     */
    private function getSectorBenchmark(array $financials): ?array
    {
        $sector     = (string) ($financials['sector'] ?? 'DEFAULT');
        $benchmarks = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $bms        = $benchmarks['benchmarks'] ?? [];
        return $bms[$sector] ?? $bms['DEFAULT'] ?? null;
    }

    private function analystConsensusLabel(float $mean): string
    {
        if ($mean <= 1.5) return 'Strong Buy';
        if ($mean <= 2.5) return 'Buy';
        if ($mean <= 3.5) return 'Hold';
        if ($mean <= 4.5) return 'Sell';
        return 'Strong Sell';
    }
}
