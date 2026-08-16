<?php

declare(strict_types=1);

namespace CVS\Screener;

/**
 * Does this symbol still mean the company we think it means?
 *
 * Ticker symbols are not stable identifiers. They get retired when a company is
 * acquired (Activision ATVI, Kellanova K), reissued when one renames (Block
 * SQ -> XYZ, Fiserv FISV -> FI), and — the dangerous case — handed to a
 * completely different business. On 2026-08-16 `GOLD` no longer resolved to
 * Barrick, which had moved to `B`: it resolved to Gold.com, Inc., a Capital
 * Markets company. The universe still carried the row under the name "Barrick
 * Gold", so the model scored one company and labelled it another.
 *
 * Nothing in the pipeline noticed, because every layer trusted the symbol: the
 * fetch succeeded, the payload was complete, the gate passed, the snapshot was
 * written. The only witness is the name the exchange returns, so that is what
 * this compares.
 *
 * Pure and offline — no network, no clock, no database.
 */
final class TickerIdentity
{
    /**
     * Words that carry no identity: legal forms, share-class and listing noise,
     * and connectives. Dropped before comparison so "IBM Corp." and
     * "International Business Machines Corporation" are judged on what actually
     * names the company.
     *
     * @var array<string, true>
     */
    private const NOISE = [
        'inc' => true, 'incorporated' => true, 'corp' => true, 'corporation' => true,
        'company' => true, 'co' => true, 'plc' => true, 'ltd' => true, 'limited' => true,
        'sa' => true, 'nv' => true, 'ag' => true, 'se' => true, 'spa' => true, 'as' => true,
        'aktiengesellschaft' => true, 'group' => true, 'holding' => true, 'holdings' => true,
        'the' => true, 'of' => true, 'and' => true, 'adr' => true, 'class' => true,
        'ordinary' => true, 'shares' => true, 'common' => true, 'stock' => true,
        'pfd' => true, 'preferred' => true, 'spolka' => true, 'spółka' => true,
        'akcyjna' => true, 'publiczna' => true,
    ];

    /**
     * Longest an acronym can be. Beyond this a short string is a name, not a
     * set of initials, and matching it against initials invites false pairs.
     */
    private const MAX_ACRONYM_LENGTH = 6;

    /**
     * Split a name into the tokens that actually identify a company.
     *
     * @return list<string>
     */
    public static function tokens(string $name): array
    {
        $s     = mb_strtolower(trim($name));
        $parts = preg_split('/[^a-z0-9\p{L}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($parts as $p) {
            // Single letters and bare numbers are listing noise ("SIEMENS AG N",
            // "Halifax Pfd 3", "Alphabet Inc. (A)").
            if (mb_strlen($p) < 2 || ctype_digit($p)) {
                continue;
            }
            if ((self::NOISE[$p] ?? false) === true) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Initials of the first words of a name, used to recognise our own
     * shorthand: BBVA for Banco Bilbao Vizcaya Argentaria, PSEG for Public
     * Service Enterprise Group. Built from the RAW words, before noise removal —
     * dropping "Group" first would turn PSEG into PSE and lose the match.
     */
    private static function initials(string $name): string
    {
        $parts = preg_split('/[^a-z0-9\p{L}]+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = '';
        foreach ($parts as $p) {
            $out .= mb_substr($p, 0, 1);
        }
        return $out;
    }

    /**
     * Is $short our abbreviation of $long?
     */
    private static function isAcronymOf(string $short, string $long): bool
    {
        $s = implode('', self::tokens($short));
        if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > self::MAX_ACRONYM_LENGTH) {
            return false;
        }
        if (count(self::tokens($short)) !== 1) {
            return false; // an acronym is one word, not a phrase
        }

        $initials = self::initials($long);

        return mb_strlen($initials) >= 2 && str_starts_with($initials, $s);
    }

    /**
     * Are these two names plausibly the same company?
     *
     * An empty name on either side is NOT a mismatch — Yahoo omits longName for
     * some listings (ITX.MC). Absence of evidence is not evidence of
     * reassignment, and treating it as one would repeat the `?? 0` mistake that
     * froze MU: turning missing data into an accusation.
     */
    public static function sameCompany(string $storedName, ?string $yahooName): bool
    {
        $a = self::tokens($storedName);
        $b = self::tokens((string) $yahooName);

        if ($a === [] || $b === []) {
            return true;
        }

        // One name's identifying words contained in the other's: "Bank of Nova
        // Scotia" vs "Bank Nova Scotia Halifax Pfd 3", "Apple Inc." vs "Apple".
        $shared = array_intersect($a, $b);
        if (count($shared) === min(count($a), count($b))) {
            return true;
        }

        // Our shorthand against the registered legal name.
        if (self::isAcronymOf($storedName, (string) $yahooName)
            || self::isAcronymOf((string) $yahooName, $storedName)) {
            return true;
        }

        // Backstop for spelling and transliteration differences that neither
        // rule above catches.
        $percent = 0.0;
        similar_text(implode('', $a), implode('', $b), $percent);

        return $percent >= 85.0;
    }

    /**
     * Human-readable warning when the symbol looks reassigned, or null when it
     * still points where we expect.
     *
     * Returns a message rather than throwing or filtering: the operator decides
     * whether to repoint or drop the ticker. Guessing on their behalf could
     * silently drop a holding.
     */
    public static function driftWarning(string $ticker, string $storedName, ?string $yahooName): ?string
    {
        if (self::sameCompany($storedName, $yahooName)) {
            return null;
        }

        return sprintf(
            '%s: zapisana nazwa "%s" nie zgadza się z Yahoo "%s" — ticker mógł zostać przepisany na inną spółkę',
            $ticker,
            $storedName,
            (string) $yahooName
        );
    }
}
