<?php

declare(strict_types=1);

namespace CVS\Logo;

/**
 * Renders a ticker's logo (or a consistent initials placeholder) as a ready
 * HTML fragment — the single implementation shared by every view that shows
 * a ticker, instead of duplicating the markup at each of the 5 call sites
 * (screener, portfolio ×2, track-record, track-record-ticker).
 *
 * Pure function, no I/O: the caller already has $logoRow from a bulk
 * TickerLogoRepository::findByTickers() read (or a single findByTicker()
 * for a detail page).
 */
final class TickerLogoPresenter
{
    /**
     * @param array{logo_path: string|null, status: string}|array{domain: string|null, logo_path: string|null, status: string}|null $logoRow
     */
    public static function render(string $ticker, ?string $companyName, ?array $logoRow): string
    {
        $logoPath = $logoRow['logo_path'] ?? null;
        if (($logoRow['status'] ?? null) === 'found' && is_string($logoPath) && $logoPath !== '') {
            $alt = htmlspecialchars($companyName ?? $ticker, ENT_QUOTES);
            return '<img class="ticker-logo" src="' . htmlspecialchars($logoPath, ENT_QUOTES)
                . '" alt="' . $alt . '" width="20" height="20" loading="lazy">';
        }

        $initials = self::initials($ticker, $companyName);
        $color    = self::colorFor($ticker);

        return '<span class="ticker-logo-fallback" style="background:#' . $color . ';" aria-hidden="true">'
            . htmlspecialchars($initials, ENT_QUOTES) . '</span>';
    }

    /** Always 2 characters, so every placeholder badge has the same visual weight. */
    private static function initials(string $ticker, ?string $companyName): string
    {
        if ($companyName !== null && trim($companyName) !== '') {
            $words = array_values(array_filter(preg_split('/\s+/', trim($companyName)) ?: []));
            if (count($words) >= 2) {
                return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
            }
            if (count($words) === 1) {
                return strtoupper(mb_substr($words[0], 0, 2));
            }
        }

        return strtoupper(mb_substr($ticker, 0, 2));
    }

    /** Deterministic hex colour from the full ticker (including any market suffix). */
    private static function colorFor(string $ticker): string
    {
        return substr(md5($ticker), 0, 6);
    }
}
