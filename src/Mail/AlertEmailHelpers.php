<?php

declare(strict_types=1);

namespace CVS\Mail;

use DateTimeImmutable;

/**
 * Shared HTML fragment builders for the watchlist alert emails
 * (AlertService, PriceAlertService) — both mails show the same trajectory
 * and earnings-timing context, so the row markup lives here once instead
 * of twice.
 */
class AlertEmailHelpers
{
    public static function headerTitle(string $ticker, ?string $companyName): string
    {
        $tickerEsc = htmlspecialchars($ticker);
        return $companyName !== null && $companyName !== ''
            ? $tickerEsc . ' — ' . htmlspecialchars($companyName)
            : $tickerEsc;
    }

    /** @param array<string, mixed>|null $trajectory TrajectoryCalculator::summarise() output */
    public static function trajectoryRow(?array $trajectory): string
    {
        if ($trajectory === null || empty($trajectory['has_trajectory'])) {
            return '';
        }

        $delta = static function ($d): string {
            $d = $d !== null ? (float) $d : null;
            if ($d === null || $d === 0.0) {
                return '<span style="color:#888;">→ b/d</span>';
            }
            return $d > 0
                ? '<span style="color:#22c55e;">▲ +' . number_format($d, 1) . '</span>'
                : '<span style="color:#ef4444;">▼ ' . number_format($d, 1) . '</span>';
        };

        return '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Trajektoria (90d):</td>'
            . '<td style="padding:8px;">d/d ' . $delta($trajectory['delta_daily'] ?? null)
            . ' &nbsp; t/t ' . $delta($trajectory['delta_weekly'] ?? null) . '</td></tr>';
    }

    /** @param array<string, mixed>|null $earningsTiming CVSResult earnings_timing block */
    public static function earningsRow(?array $earningsTiming): string
    {
        if ($earningsTiming === null || ($earningsTiming['state'] ?? null) === null) {
            return '';
        }
        $state = (string) $earningsTiming['state'];
        $days  = static fn(?int $n): string => $n === 1 ? 'dzień' : 'dni';
        $label = match ($state) {
            'before'     => sprintf('📅 Wyniki za %d %s', (int) $earningsTiming['days_to'], $days((int) $earningsTiming['days_to'])),
            'in_transit' => '📅 W oknie wyników',
            'after'      => sprintf('📅 Wyniki %d %s temu', (int) $earningsTiming['days_since'], $days((int) $earningsTiming['days_since'])),
            default      => '📅 Wyniki',
        };

        return '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Wyniki finansowe:</td>'
            . '<td style="padding:8px;">' . htmlspecialchars($label) . '</td></tr>';
    }

    /**
     * Price position relative to the ATR accumulation zone — same three-state
     * classification as the analysis-page exec-plan badge (in/above/below).
     * Used by both alert templates so a price shown in a mail always reflects
     * where it actually sits, not just an assumed "just entered the zone".
     *
     * @return array{0: string, 1: string} [color hex, label]
     */
    public static function zoneBadge(float $price, float $zoneLow, float $zoneHigh): array
    {
        return match (true) {
            $price >= $zoneLow && $price <= $zoneHigh => ['#22c55e', '✓ Cena w strefie kupna'],
            $price > $zoneHigh                        => ['#f59e0b', '↑ Powyżej strefy — czekaj na cofnięcie'],
            default                                    => ['#ef4444', '↓ Poniżej strefy (poniżej wsparcia)'],
        };
    }

    public static function footerMeta(string $modelVersion): string
    {
        return 'Wersja modelu: ' . htmlspecialchars($modelVersion)
            . ' · przeliczono ' . (new DateTimeImmutable())->format('d.m.Y');
    }

    /**
     * Solid-fill recommendation pill for the digest table's dense rows.
     *
     * Same hue mapping as the app's own watchlist-chip classes
     * (SILNE KUPUJ=green, AKUMULUJ=blue, NEUTRALNIE=gray, REDUKUJ=amber,
     * UNIKAJ=red — public/css/app.css .reco--*), matched the same way the
     * screener already does it (str_contains against the label, see
     * templates/screener.php $recoColor). Re-expressed as a solid tint
     * background with dark saturated text rather than the app's
     * translucent-fill-on-dark-navy tokens: those read as a barely-visible
     * wash on the white background an email needs — client dark-mode support
     * is too inconsistent to risk a dark card — so this is the same brand hue
     * for a light medium, not a different palette.
     */
    public static function recoBadge(?string $reco): string
    {
        if ($reco === null || $reco === '') {
            return '<span style="color:#64748b;font-size:12px;">—</span>';
        }

        [$bg, $fg] = match (true) {
            str_contains($reco, 'SILNE KUPUJ') => ['#dcfce7', '#15803d'],
            str_contains($reco, 'AKUMULUJ')    => ['#dbeafe', '#1d4ed8'],
            str_contains($reco, 'REDUKUJ')     => ['#fef3c7', '#92400e'],
            str_contains($reco, 'UNIKAJ')      => ['#fee2e2', '#b91c1c'],
            default                            => ['#f1f5f9', '#475569'], // NEUTRALNIE
        };

        // Strip the leading arrow glyph — the pill's colour already carries
        // the direction, so repeating it as a second glyph inside a small
        // badge is noise, not information.
        $label = trim((string) preg_replace('/^[⬆⬇→]+\s*/u', '', $reco));

        return '<span style="display:inline-block;background:' . $bg . ';color:' . $fg . ';'
            . 'font-size:11px;font-weight:700;letter-spacing:.02em;padding:3px 9px;'
            . 'border-radius:999px;white-space:nowrap;">' . htmlspecialchars($label) . '</span>';
    }

    /**
     * Compact signal label for the digest table — no full "old → new" prose,
     * just the current glyph+word (the row's presence in the digest already
     * signals "this changed"; a table read top-to-bottom does not need every
     * cell to repeat that framing).
     */
    public static function signalLabel(?string $signal): string
    {
        return match ($signal) {
            'strong'    => '⭐⭐ Silny',
            'watchlist' => '⭐ Obserwuj',
            'momentum'  => '↑ Momentum',
            default     => '<span style="color:#64748b;">—</span>',
        };
    }

    /**
     * Footer offering to mute just this ticker (deep-link to the analysis page,
     * which already carries the per-ticker mute toggle) plus, optionally, the
     * global unsubscribe link.
     */
    public static function muteFooter(string $ticker, ?string $unsubUrl): string
    {
        $muteUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/analysis/' . urlencode($ticker);
        $tail    = $unsubUrl !== null && $unsubUrl !== ''
            ? ' Albo <a href="' . htmlspecialchars($unsubUrl) . '" style="color:#aaa;">wypisz się ze wszystkich alertów</a>.'
            : '';

        return '<p style="color:#aaa;font-size:10px;margin-top:8px;">'
            . 'Nie chcesz alertów tylko dla ' . htmlspecialchars($ticker) . '? '
            . '<a href="' . htmlspecialchars($muteUrl) . '" style="color:#aaa;">Zarządzaj na stronie analizy</a>.'
            . $tail
            . '</p>';
    }
}
