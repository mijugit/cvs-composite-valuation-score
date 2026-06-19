<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\UserRepository;
use CVS\Mail\MailService;

/**
 * Detects "price entered the accumulation zone" and dispatches alert emails
 * (Phase 8, slice 3). Driven by bin/check_price_alerts.php (hourly cron).
 *
 * Zone bounds (USD) come from ticker_zone (written by the daily rescore); the live
 * price comes from a light chart read. Hysteresis: alert fires on an out→in
 * transition, and only re-arms once the price leaves the zone again (with an optional
 * margin). State is per (user, ticker) in price_alert.
 *
 * The transition decision is a pure function (decide()) so it is unit-testable
 * without I/O; checkAndNotify() handles the fetch/mail/DB orchestration.
 */
class PriceAlertService
{
    private float $marginFrac;

    /** @param array<string, mixed> $cfg `price_alert` config section */
    public function __construct(
        private readonly PriceAlertRepository $repo,
        private readonly FinancialDataFetcher $fetcher,
        private readonly MailService          $mail,
        private readonly UserRepository       $users,
        array $cfg = []
    ) {
        $this->marginFrac = (float) ($cfg['hysteresis_margin_frac'] ?? 0.0);
    }

    /**
     * Pure transition decision.
     *
     * @param string|null $lastState 'in' | 'out' | null
     * @return array{action: string, new_state: string} action ∈ {send, rearm, none}
     */
    public static function decide(
        float $priceUsd,
        float $zoneLow,
        float $zoneHigh,
        ?string $lastState,
        float $marginFrac
    ): array {
        $margin  = $marginFrac * max(0.0, $zoneHigh - $zoneLow);
        $inZone  = $priceUsd >= $zoneLow && $priceUsd <= $zoneHigh;
        $outZone = $priceUsd > $zoneHigh + $margin || $priceUsd < $zoneLow - $margin;

        if ($inZone && $lastState !== 'in') {
            return ['action' => 'send', 'new_state' => 'in'];
        }
        if ($outZone && $lastState !== 'out') {
            return ['action' => 'rearm', 'new_state' => 'out'];
        }
        return ['action' => 'none', 'new_state' => $lastState ?? ''];
    }

    /**
     * Evaluate all active price alerts, send mails on out→in transitions.
     *
     * @return int number of alert emails sent
     */
    public function checkAndNotify(): int
    {
        $active = $this->repo->findActiveAlerts();
        if ($active === []) {
            return 0;
        }

        /** @var array<string, array{zone: array<string,mixed>|null, price: float|null}> $cache */
        $cache = [];
        $sent  = 0;

        foreach ($active as $a) {
            $ticker = $a['ticker'];

            if (!isset($cache[$ticker])) {
                $zone  = $this->repo->findZone($ticker);
                $price = null;
                if ($zone !== null) {
                    $native = $this->fetcher->fetchLatestPrice($ticker);
                    if ($native !== null) {
                        $fx    = $zone['fx_rate_to_usd'] !== null ? (float) $zone['fx_rate_to_usd'] : 1.0;
                        $price = $native * $fx;
                    }
                }
                $cache[$ticker] = ['zone' => $zone, 'price' => $price];
            }

            $zone  = $cache[$ticker]['zone'];
            $price = $cache[$ticker]['price'];
            if ($zone === null || $price === null || $zone['zone_low'] === null || $zone['zone_high'] === null) {
                continue;
            }

            $decision = self::decide(
                $price,
                (float) $zone['zone_low'],
                (float) $zone['zone_high'],
                $a['last_state'],
                $this->marginFrac
            );

            if ($decision['action'] === 'send') {
                $user = $this->users->findById($a['user_id']);
                if ($user !== null && !empty($user['email'])) {
                    $html = $this->buildHtml($ticker, (float) $zone['zone_low'], (float) $zone['zone_high'], $price, $zone['stop_swing'] ?? null);
                    $this->mail->send((string) $user['email'], sprintf('CVS Alert: %s — cena w strefie kupna', $ticker), $html);
                }
                $this->repo->updateState($a['user_id'], $ticker, 'in', true);
                $sent++;
            } elseif ($decision['action'] === 'rearm') {
                $this->repo->updateState($a['user_id'], $ticker, 'out', false);
            }
        }

        return $sent;
    }

    private function buildHtml(string $ticker, float $zoneLow, float $zoneHigh, float $price, ?float $stopSwing): string
    {
        $usd     = static fn(float $v): string => '$' . number_format($v, 2);
        $stopRow = $stopSwing !== null
            ? '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Stop (swing):</td><td style="padding:8px;">' . $usd($stopSwing) . '</td></tr>'
            : '';

        return '
            <h2 style="color:#1e3a5f;">CVS Alert — cena weszła w strefę kupna: ' . htmlspecialchars($ticker) . '</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:160px;">Ticker:</td>
                    <td style="padding:8px;font-weight:bold;font-size:16px;">' . htmlspecialchars($ticker) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Strefa kupna:</td>
                    <td style="padding:8px;">' . $usd($zoneLow) . ' – ' . $usd($zoneHigh) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Bieżąca cena:</td>
                    <td style="padding:8px;font-weight:bold;">' . $usd($price) . '</td></tr>
                ' . $stopRow . '
            </table>
            <p style="margin-top:16px;">
                <a href="https://cvs.timeflow.fun/analysis/' . urlencode($ticker) . '"
                   style="background:#1e3a5f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;">
                    Otwórz analizę ' . htmlspecialchars($ticker) . ' →
                </a>
            </p>
            <p style="color:#888;font-size:11px;margin-top:12px;">
                Poziomy orientacyjne z danych cenowych — nie są rekomendacją inwestycyjną. Inwestuj świadomie.<br>
                Wygenerowano automatycznie przez CVS Composite Valuation Score.
            </p>
        ';
    }
}
