<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\UserRepository;
use CVS\Mail\AlertEmailHelpers;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;
use DateTimeImmutable;
use Throwable;

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
    private float  $marginFrac;
    /** @var array<string, mixed> */
    private array  $trajectoryConfig;

    /**
     * @param array<string, mixed> $cfg              `price_alert` config section
     * @param string                $liveModelVersion `model_version` from cvs-weights config —
     *                                                pins CVS-score reads to the production model
     *                                                (shadow rows share the same score_date).
     * @param array<string, mixed> $trajectoryConfig `trajectory` config section (window_days/min_points)
     */
    public function __construct(
        private readonly PriceAlertRepository  $repo,
        private readonly FinancialDataFetcher  $fetcher,
        private readonly MailService           $mail,
        private readonly UserRepository        $users,
        private readonly CvsSnapshotRepository $snapshots,
        array $cfg = [],
        private readonly string $liveModelVersion = '',
        array $trajectoryConfig = []
    ) {
        $this->marginFrac      = (float) ($cfg['hysteresis_margin_frac'] ?? 0.0);
        $this->trajectoryConfig = $trajectoryConfig;
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
                    $stopSwing = $zone['stop_swing'] !== null ? (float) $zone['stop_swing'] : null;
                    $stopFund  = $zone['stop_fund']  !== null ? (float) $zone['stop_fund']  : null;
                    $html = $this->buildHtml($ticker, (float) $zone['zone_low'], (float) $zone['zone_high'], $price, $stopSwing, $stopFund);
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

    /**
     * Manual production test: renders and sends the price-zone alert HTML for a
     * ticker's real current zone/price to an arbitrary address. Reads only —
     * never touches price_alert state — safe to call outside the cron.
     */
    public function sendPreviewMail(string $ticker, string $toEmail): bool
    {
        $zone = $this->repo->findZone($ticker);
        if ($zone === null || $zone['zone_low'] === null || $zone['zone_high'] === null) {
            return false;
        }

        $native = $this->fetcher->fetchLatestPrice($ticker);
        if ($native === null) {
            return false;
        }
        $fx    = $zone['fx_rate_to_usd'] !== null ? (float) $zone['fx_rate_to_usd'] : 1.0;
        $price = $native * $fx;

        $stopSwing = $zone['stop_swing'] !== null ? (float) $zone['stop_swing'] : null;
        $stopFund  = $zone['stop_fund']  !== null ? (float) $zone['stop_fund']  : null;

        $html = $this->buildHtml($ticker, (float) $zone['zone_low'], (float) $zone['zone_high'], $price, $stopSwing, $stopFund);
        $subject = sprintf('[TEST] CVS Alert: %s — podgląd strefy kupna', $ticker);

        return $this->mail->send($toEmail, $subject, $html);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function buildHtml(string $ticker, float $zoneLow, float $zoneHigh, float $price, ?float $stopSwing, ?float $stopFund): string
    {
        $usd     = static fn(float $v): string => '$' . number_format($v, 2);
        $stopRow = '';
        if ($stopSwing !== null) {
            $stopRow .= '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Stop (swing):</td><td style="padding:8px;">' . $usd($stopSwing) . '</td></tr>';
        }
        if ($stopFund !== null) {
            $stopRow .= '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Stop (fundamentalny):</td><td style="padding:8px;">' . $usd($stopFund) . '</td></tr>';
        }

        $snapshot   = $this->findLatestSnapshot($ticker);
        $headerName = AlertEmailHelpers::headerTitle($ticker, $snapshot['company_name'] ?? null);
        $scoreRows  = $this->buildScoreRows($snapshot);
        $trajectory = $this->fetchTrajectory($ticker);
        $trajectoryRow = AlertEmailHelpers::trajectoryRow($trajectory);
        $earningsRow   = $snapshot !== null ? AlertEmailHelpers::earningsRow([
            'days_since' => $snapshot['days_since_earnings'] ?? null,
            'days_to'    => $snapshot['days_to_earnings']    ?? null,
            'state'      => $snapshot['earnings_state']      ?? null,
        ]) : '';
        $footerMeta = AlertEmailHelpers::footerMeta($this->liveModelVersion);

        // Real cron sends only fire on an out→in transition, so price is always
        // actually inside the zone there — but sendPreviewMail() renders this same
        // template against whatever the live price happens to be right now, which
        // may be outside the zone. Badge-driven color/label (not a hardcoded
        // "entered the zone" assumption) keeps the mail accurate in both cases.
        [$badgeColor, $badgeLabel] = AlertEmailHelpers::zoneBadge($price, $zoneLow, $zoneHigh);

        return '
            <h2 style="color:#1e3a5f;">CVS Alert — pozycja względem strefy kupna: ' . $headerName . '</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:160px;">Ticker:</td>
                    <td style="padding:8px;font-weight:bold;font-size:16px;">' . htmlspecialchars($ticker) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Strefa kupna:</td>
                    <td style="padding:8px;">' . $usd($zoneLow) . ' – ' . $usd($zoneHigh) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Bieżąca cena:</td>
                    <td style="padding:8px;font-weight:bold;color:' . $badgeColor . ';">' . $usd($price) . '<br>'
                    . '<span style="color:' . $badgeColor . ';font-weight:bold;">' . $badgeLabel . '</span></td></tr>
                ' . $stopRow . $scoreRows . $trajectoryRow . $earningsRow . '
            </table>
            <p style="color:#888;font-size:11px;margin-top:8px;">' . $footerMeta . '</p>
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
            ' . AlertEmailHelpers::muteFooter($ticker, null) . '
        ';
    }

    /**
     * Latest CVS snapshot for context (company name, scores, earnings timing) —
     * this alert type has no CVSResult of its own (price-only cron), so it reads
     * the most recent rescore row instead. Graceful null on any failure/absence.
     *
     * @return array<string, mixed>|null
     */
    private function findLatestSnapshot(string $ticker): ?array
    {
        try {
            return $this->snapshots->findLatestByTicker($ticker, $this->liveModelVersion ?: null);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchTrajectory(string $ticker): ?array
    {
        if ($this->liveModelVersion === '') {
            return null;
        }
        try {
            $windowDays = (int) ($this->trajectoryConfig['window_days'] ?? 90);
            $minPoints  = (int) ($this->trajectoryConfig['min_points']  ?? 2);
            $since      = (new DateTimeImmutable())->modify('-' . $windowDays . ' days');
            $rows       = $this->snapshots->findTrajectory($ticker, $since, $this->liveModelVersion);
            return TrajectoryCalculator::summarise($rows, $minPoints);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $snapshot */
    private function buildScoreRows(?array $snapshot): string
    {
        if ($snapshot === null) {
            return '';
        }
        $rows = '';
        if (isset($snapshot['cvs_swing'])) {
            $recoStr = !empty($snapshot['reco_swing']) ? ' · ' . htmlspecialchars((string) $snapshot['reco_swing']) : '';
            $rows .= '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">CVS Swing:</td>'
                . '<td style="padding:8px;font-weight:bold;">' . number_format((float) $snapshot['cvs_swing'], 1) . ' / 100' . $recoStr . '</td></tr>';
        }
        if (isset($snapshot['cvs_fund'])) {
            $recoStr = !empty($snapshot['reco_fund']) ? ' · ' . htmlspecialchars((string) $snapshot['reco_fund']) : '';
            $rows .= '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">CVS Fundamentalny:</td>'
                . '<td style="padding:8px;font-weight:bold;color:#a16207;">' . number_format((float) $snapshot['cvs_fund'], 1) . ' / 100' . $recoStr . '</td></tr>';
        }
        return $rows;
    }
}
