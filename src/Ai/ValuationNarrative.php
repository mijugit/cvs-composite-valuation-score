<?php

declare(strict_types=1);

namespace CVS\Ai;

/**
 * Describing, in the prompt, what the model actually measured.
 *
 * The data block used to state "Valuation (EV/FCF vs sector median)" and
 * "Quality (gross margin, leverage, growth)" for every company, because when it
 * was written those were the only paths. They are not any more: variant B
 * prices on EV/Sales, C on price/book, D on EV/EBITDA, and financials are
 * scored on returns rather than margins.
 *
 * The cost of the stale labels is not cosmetic. Reviewing ALR.WA — a bank,
 * scored on price/book — an external model was told the figure came from
 * EV/FCF and spent a section of its critique explaining that free cash flow is
 * a poor measure for a deposit-taking institution. That is a correct statement
 * about a method the model had already stopped using: the prompt described a
 * defect that no longer existed, and the reviewer dutifully found it.
 *
 * An anchor the reader cannot verify is worse than no anchor. Pure and offline.
 */
final class ValuationNarrative
{
    /**
     * How the Valuation pillar reached its number, per variant.
     *
     * @return string label for the pillar line, e.g. "P/B vs peer median"
     */
    public static function valuationLabel(?string $variant): string
    {
        return match ($variant) {
            'A'     => 'forward EV/FCF vs peer median',
            'B'     => 'EV/Sales adjusted for growth, vs peer median',
            'C'     => 'P/B vs peer median — this company is a financial, where EV and free cash flow are not meaningful',
            'D'     => 'EV/EBITDA vs peer median — this company is real estate, where free cash flow nets out property acquisitions',
            default => 'peer-median multiple (variant not recorded on this snapshot)',
        };
    }

    /**
     * The multiple itself, for prose that needs to name it in two words.
     */
    public static function metricName(?string $variant): string
    {
        return match ($variant) {
            'A'     => 'EV/FCF',
            'B'     => 'EV/Sales',
            'C'     => 'P/B',
            'D'     => 'EV/EBITDA',
            default => 'the valuation multiple',
        };
    }

    /**
     * Which inputs the Quality pillar used. Financials take the returns path;
     * everything else the margin/leverage/growth one.
     */
    public static function qualityLabel(bool $isFinancial): string
    {
        return $isFinancial
            ? 'ROE, ROA, dividend-payout sanity — the financial path; gross margin and net debt/EBITDA are not used here'
            : 'gross margin vs sector, net debt/EBITDA, forward growth';
    }

    /**
     * How implied fair value was derived, matching the variant that produced it.
     *
     * @param  float|null $medianEvFcf cold-start/sector EV/FCF, for the A path
     * @return list<string> lines to append, empty when nothing can be said
     */
    public static function fairValueMethod(bool $isFinancial, ?float $medianEvFcf): array
    {
        if ($isFinancial) {
            return [
                '- Calculation method: Fair Price = peer_median_P/B × book value per share.',
                '- This is the price at which the company would trade at its peer group\'s book multiple '
                    . '(Valuation pillar = 50/100). It is NOT an EV/FCF or DCF-derived figure.',
            ];
        }

        if ($medianEvFcf === null) {
            return [];
        }

        return [
            '- Calculation method: Fair EV = peer_median_EV/FCF (' . $medianEvFcf . 'x) × Forward_FCF²; '
                . 'Fair Price = (Fair EV - Net Debt) / Shares Outstanding',
            '- This represents the price at which the stock would be fairly valued '
                . 'relative to its peer median on an EV/FCF basis (Valuation pillar = 50/100).',
        ];
    }

    /**
     * Extra metrics worth sending for a financial.
     *
     * The block already carries P/E and ROE, but omitted every figure the
     * financial paths actually consume — the book multiple the score is built
     * on, the book value behind fair value, and the ROA and payout that make up
     * eight of the ten quality points. A reviewer cannot check the arithmetic
     * without them.
     *
     * @param  array<string, mixed> $financials
     * @return list<string>
     */
    public static function financialMetrics(array $financials, callable $num, callable $pct): array
    {
        return [
            '- Price / book (the Valuation pillar\'s own metric): ' . $num($financials['price_to_book'] ?? null),
            '- Book value per share: ' . $num($financials['book_value_per_share'] ?? null),
            '- Return on assets: ' . $pct($financials['return_on_assets'] ?? null),
            '- Dividend payout ratio: ' . $pct($financials['payout_ratio'] ?? null),
        ];
    }

    /**
     * Is this company on the financial path?
     *
     * @param array<string, mixed> $cvsConfig config/cvs-weights.php
     */
    public static function isFinancialSector(string $sector, array $cvsConfig): bool
    {
        $sectors = is_array($cvsConfig['financials']['sectors'] ?? null)
            ? $cvsConfig['financials']['sectors']
            : [];

        return in_array($sector, $sectors, true);
    }
}
