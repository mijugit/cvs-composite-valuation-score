<?php

declare(strict_types=1);

namespace CVS\Tests\Screener;

use CVS\Screener\TickerIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every case below is a real pair observed while sweeping the 590-ticker
 * universe on 2026-08-16. The five "shorthand" cases are what a naive string
 * comparison flags as reassignment; the GOLD case is the one that actually was.
 */
class TickerIdentityTest extends TestCase
{
    /**
     * The whole reason this class exists: the symbol was handed to a different
     * company, and every other layer of the pipeline was satisfied.
     */
    public function test_reassigned_symbol_is_flagged(): void
    {
        $warning = TickerIdentity::driftWarning('GOLD', 'Barrick Gold', 'Gold.com, Inc.');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('GOLD', $warning);
        $this->assertStringContainsString('Gold.com', $warning);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ourShorthandVsLegalName(): array
    {
        return [
            'abbreviation'      => ['IBM Corp.', 'International Business Machines Corporation'],
            'initials'          => ['BBVA', 'Banco Bilbao Vizcaya Argentaria, S.A.'],
            'utility initials'  => ['PSEG', 'Public Service Enterprise Group Incorporated'],
            'german legal form' => ['Siemens Aktiengesellschaft', 'SIEMENS AG                    N'],
            'listing suffix'    => ['Bank of Nova Scotia', 'Bank Nova Scotia Halifax Pfd 3'],
            'legal form only'   => ['Apple Inc.', 'Apple Inc'],
            'polish legal form' => ['LPP SA', 'LPP Spółka Akcyjna'],
        ];
    }

    #[DataProvider('ourShorthandVsLegalName')]
    public function test_our_shorthand_is_not_reported_as_drift(string $ours, string $yahoo): void
    {
        $this->assertNull(
            TickerIdentity::driftWarning('X', $ours, $yahoo),
            "false alarm on \"$ours\" vs \"$yahoo\" — a check that cries wolf gets ignored"
        );
    }

    /**
     * Yahoo omits longName for some listings (ITX.MC). Missing data must not be
     * read as a claim — the same mistake that turned MU's absent income
     * statement into "a company with no revenue".
     */
    public function test_missing_yahoo_name_is_not_drift(): void
    {
        $this->assertNull(TickerIdentity::driftWarning('ITX.MC', 'Industria de Diseno Textil', null));
        $this->assertNull(TickerIdentity::driftWarning('ITX.MC', 'Industria de Diseno Textil', ''));
    }

    public function test_missing_stored_name_is_not_drift(): void
    {
        $this->assertNull(TickerIdentity::driftWarning('FOO', '', 'Some Corporation'));
    }

    public function test_tokens_drop_legal_form_punctuation_and_listing_noise(): void
    {
        $this->assertSame(['apple'], TickerIdentity::tokens('Apple Inc.'));
        $this->assertSame(['apple'], TickerIdentity::tokens('APPLE  INC'));
        // Share class, preferred-listing suffix and sequence numbers say nothing
        // about which company this is.
        $this->assertSame(['alphabet'], TickerIdentity::tokens('Alphabet Inc. (A)'));
        $this->assertSame(['bank', 'nova', 'scotia', 'halifax'], TickerIdentity::tokens('Bank Nova Scotia Halifax Pfd 3'));
    }

    public function test_unrelated_companies_are_not_the_same(): void
    {
        $this->assertFalse(TickerIdentity::sameCompany('Kellanova', 'Kenvue Inc.'));
        $this->assertFalse(TickerIdentity::sameCompany('Block Inc. (Square)', 'Blackstone Inc.'));
    }

    public function test_check_is_deterministic(): void
    {
        $a = TickerIdentity::driftWarning('GOLD', 'Barrick Gold', 'Gold.com, Inc.');
        $b = TickerIdentity::driftWarning('GOLD', 'Barrick Gold', 'Gold.com, Inc.');

        $this->assertSame($a, $b);
    }
}
