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

    public static function footerMeta(string $modelVersion): string
    {
        return 'Wersja modelu: ' . htmlspecialchars($modelVersion)
            . ' · przeliczono ' . (new DateTimeImmutable())->format('d.m.Y');
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
