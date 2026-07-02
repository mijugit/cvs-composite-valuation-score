<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Auth\UserRepository;
use CVS\Mail\AlertEmailHelpers;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\TrajectoryCalculator;
use DateTimeImmutable;
use Throwable;

/**
 * Detects CVS state changes and dispatches alert emails.
 *
 * Called from bin/rescore.php after each snapshot save.
 * An alert is sent when reco_swing OR golden_signal differs from the
 * last recorded state in alert_sent — preventing duplicate daily mails.
 */
class AlertService
{
    /** @var array<string, mixed> */
    private array $trajectoryConfig;

    /** @param array<string, mixed> $trajectoryConfig `trajectory` config section (window_days/min_points) */
    public function __construct(
        private readonly AlertRepository       $alertRepo,
        private readonly MailService           $mail,
        private readonly UserRepository        $users,
        private readonly CvsSnapshotRepository $snapshots,
        array $trajectoryConfig = []
    ) {
        $this->trajectoryConfig = $trajectoryConfig;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Check if state changed for this ticker and notify affected users.
     *
     * @param array<string, mixed> $cvsResult   CVSResult::toArray()
     * @param string|null          $companyName Yahoo long name (FinancialDataFetcher 'long_name')
     * @param float|null           $price       Current price at scoring time (USD)
     * @param array<string, mixed>|null $atrZone AtrZoneCalculator::compute() output (has_zone/zone_low/zone_high/stop_swing/stop_fund)
     * @return int Number of alerts sent (0 = no change or no eligible users)
     */
    public function checkAndNotify(
        string  $ticker,
        array   $cvsResult,
        ?string $companyName = null,
        ?float  $price = null,
        ?array  $atrZone = null
    ): int {
        $currentReco   = (string) ($cvsResult['swing']['recommendation'] ?? '');
        $currentSignal = $cvsResult['golden_signal'] !== '' ? ($cvsResult['golden_signal'] ?? null) : null;
        $cvsSwing      = isset($cvsResult['swing']['cvs']) ? (float) $cvsResult['swing']['cvs'] : null;
        $cvsFund       = isset($cvsResult['fundamental']['cvs']) ? (float) $cvsResult['fundamental']['cvs'] : null;
        $fundReco      = $cvsResult['fundamental']['recommendation'] ?? null;
        $modelVersion  = (string) ($cvsResult['model_version'] ?? '');
        $earningsTiming = $cvsResult['earnings_timing'] ?? null;

        $userIds = $this->alertRepo->findUsersWatchingTicker($ticker);
        if (empty($userIds)) {
            return 0;
        }

        $trajectory = $this->fetchTrajectory($ticker, $modelVersion);

        $sent = 0;

        foreach ($userIds as $userId) {
            // Skip if per-ticker silenced.
            if ($this->alertRepo->isTickerDisabled($userId, $ticker)) {
                continue;
            }

            $last = $this->alertRepo->getLastSent($userId, $ticker);

            // Determine if state changed.
            $recoChanged   = $last === null || $last['last_reco']   !== ($currentReco   ?: null);
            $signalChanged = $last === null || $last['last_signal']  !== $currentSignal;

            if (!$recoChanged && !$signalChanged) {
                continue; // No change — skip.
            }

            // Fetch user email.
            $user = $this->users->findById($userId);
            if ($user === null || empty($user['email'])) {
                continue;
            }

            $oldReco   = $last['last_reco']   ?? null;
            $oldSignal = $last['last_signal']  ?? null;

            $unsubToken = hash_hmac(
                'sha256',
                'unsub:' . $userId . ':' . $user['email'],
                $_ENV['APP_SECRET'] ?? ''
            );
            $unsubUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun')
                . '/alerts/unsubscribe?uid=' . $userId
                . '&token=' . $unsubToken;

            $html = $this->buildHtml($ticker, [
                'company_name'    => $companyName,
                'price'           => $price,
                'atr_zone'        => $atrZone,
                'reco_old'        => $oldReco,
                'reco_new'        => $currentReco ?: null,
                'signal_old'      => $oldSignal,
                'signal_new'      => $currentSignal,
                'cvs_swing'       => $cvsSwing,
                'cvs_fund'        => $cvsFund,
                'fund_reco'       => $fundReco,
                'model_version'   => $modelVersion,
                'earnings_timing' => $earningsTiming,
                'trajectory'      => $trajectory,
                'unsub_url'       => $unsubUrl,
            ]);

            $subject = sprintf('CVS Alert: %s — zmiana sygnału', $ticker);

            $this->mail->send((string) $user['email'], $subject, $html);

            // Update last sent state regardless of mail success (avoid spam on SMTP failure).
            $this->alertRepo->upsertSent($userId, $ticker, $currentReco ?: null, $currentSignal);
            $sent++;
        }

        return $sent;
    }

    /**
     * Manual production test: renders and sends the state-change alert HTML for
     * a ticker's most recent real snapshot to an arbitrary address. Bypasses
     * per-user dedup entirely — never call this from the rescore pipeline, only
     * from bin/send_test_mail.php for verifying rendering/deliverability.
     *
     * @param string|null               $companyName Override; falls back to the snapshot's company_name.
     * @param float|null                $price       Override; falls back to the snapshot's price_at_snapshot.
     * @param array<string, mixed>|null $atrZone     AtrZoneCalculator-shaped zone (e.g. from PriceAlertRepository::findZone).
     */
    public function sendPreviewMail(
        string  $ticker,
        string  $toEmail,
        string  $liveModelVersion,
        ?string $companyName = null,
        ?float  $price = null,
        ?array  $atrZone = null
    ): bool {
        $snapshot = null;
        try {
            $snapshot = $this->snapshots->findLatestByTicker($ticker, $liveModelVersion ?: null);
        } catch (Throwable $e) {
            // fall through — $snapshot stays null, handled below
        }

        if ($snapshot === null) {
            return false;
        }

        $swingCvs = isset($snapshot['cvs_swing']) ? (float) $snapshot['cvs_swing'] : null;
        $fundCvs  = isset($snapshot['cvs_fund'])  ? (float) $snapshot['cvs_fund']  : null;

        $trajectory = $this->fetchTrajectory($ticker, $liveModelVersion);

        $html = $this->buildHtml($ticker, [
            'company_name'    => $companyName ?? ($snapshot['company_name'] ?? null),
            'price'           => $price ?? (isset($snapshot['price_at_snapshot']) ? (float) $snapshot['price_at_snapshot'] : null),
            'atr_zone'        => $atrZone,
            'reco_old'        => null,
            'reco_new'        => $snapshot['reco_swing'] ?? null,
            'signal_old'      => null,
            'signal_new'      => $snapshot['golden_signal'] ?? null,
            'cvs_swing'       => $swingCvs,
            'cvs_fund'        => $fundCvs,
            'fund_reco'       => $snapshot['reco_fund'] ?? null,
            'model_version'   => $liveModelVersion,
            'earnings_timing' => [
                'days_since' => $snapshot['days_since_earnings'] ?? null,
                'days_to'    => $snapshot['days_to_earnings']    ?? null,
                'state'      => $snapshot['earnings_state']      ?? null,
            ],
            'trajectory'      => $trajectory,
            'unsub_url'       => '',
        ]);

        $subject = sprintf('[TEST] CVS Alert: %s — podgląd', $ticker);

        return $this->mail->send($toEmail, $subject, $html);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Trajectory is ticker-level (not per-user) — computed once and reused
     * across every notified watcher. Gracefully returns null on any failure
     * (e.g. missing table in a minimal test setup) — a mail without a
     * trajectory block is fine; a mail that never sends is not.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTrajectory(string $ticker, string $modelVersion): ?array
    {
        if ($modelVersion === '') {
            return null;
        }

        try {
            $windowDays = (int) ($this->trajectoryConfig['window_days'] ?? 90);
            $minPoints  = (int) ($this->trajectoryConfig['min_points']  ?? 2);
            $since      = (new DateTimeImmutable())->modify('-' . $windowDays . ' days');
            $rows       = $this->snapshots->findTrajectory($ticker, $since, $modelVersion);
            return TrajectoryCalculator::summarise($rows, $minPoints);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array<string, mixed> $ctx */
    private function buildHtml(string $ticker, array $ctx): string
    {
        $tickerEsc  = htmlspecialchars($ticker);
        $headerName = AlertEmailHelpers::headerTitle($ticker, $ctx['company_name']);

        $recoLine = ($ctx['reco_old'] !== null && $ctx['reco_old'] !== '')
            ? htmlspecialchars((string) $ctx['reco_old']) . ' → ' . htmlspecialchars((string) $ctx['reco_new'])
            : htmlspecialchars((string) $ctx['reco_new']);

        $sigLabel = static function (?string $signal): string {
            return match ($signal) {
                'strong'    => '⭐⭐ Silny sygnał',
                'watchlist' => '⭐ Obserwuj',
                'momentum'  => '↑ Momentum',
                null        => 'brak',
                default     => htmlspecialchars($signal),
            };
        };

        $signalLine = '';
        if ($ctx['signal_old'] !== null || $ctx['signal_new'] !== null) {
            $signalLine = ($ctx['signal_old'] !== $ctx['signal_new'])
                ? $sigLabel($ctx['signal_old']) . ' → ' . $sigLabel($ctx['signal_new'])
                : $sigLabel($ctx['signal_new']);
        }

        $swingRow = $ctx['cvs_swing'] !== null
            ? '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">CVS Swing:</td>'
              . '<td style="padding:8px;font-weight:bold;">' . number_format((float) $ctx['cvs_swing'], 1) . ' / 100</td></tr>'
            : '';
        $fundRow = $ctx['cvs_fund'] !== null
            ? '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">CVS Fundamentalny:</td>'
              . '<td style="padding:8px;font-weight:bold;color:#a16207;">' . number_format((float) $ctx['cvs_fund'], 1) . ' / 100'
              . ($ctx['fund_reco'] !== null ? ' · ' . htmlspecialchars((string) $ctx['fund_reco']) : '') . '</td></tr>'
            : '';

        $signalRow = $signalLine !== ''
            ? '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Złoty sygnał:</td>'
              . '<td style="padding:8px;">' . $signalLine . '</td></tr>'
            : '';

        $priceZoneRow  = $this->buildPriceZoneRow($ctx['price'], $ctx['atr_zone']);
        $trajectoryRow = AlertEmailHelpers::trajectoryRow($ctx['trajectory']);
        $earningsRow   = AlertEmailHelpers::earningsRow($ctx['earnings_timing']);
        $footerMeta    = AlertEmailHelpers::footerMeta((string) $ctx['model_version']);

        return '
            <h2 style="color:#1e3a5f;">CVS Alert — zmiana sygnału: ' . $headerName . '</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Ticker:</td>
                    <td style="padding:8px;font-weight:bold;font-size:16px;">' . $tickerEsc . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Rekomendacja:</td>
                    <td style="padding:8px;">' . $recoLine . '</td></tr>
                ' . $swingRow . $fundRow . $signalRow . $priceZoneRow . $trajectoryRow . $earningsRow . '
            </table>
            <p style="color:#888;font-size:11px;margin-top:8px;">' . $footerMeta . '</p>
            <p style="margin-top:16px;">
                <a href="https://cvs.timeflow.fun/analysis/' . urlencode($ticker) . '"
                   style="background:#1e3a5f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;">
                    Otwórz analizę ' . $tickerEsc . ' →
                </a>
            </p>
            <p style="color:#888;font-size:11px;margin-top:12px;">
                Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.<br>
                Wygenerowano automatycznie przez CVS Composite Valuation Score.
            </p>
            ' . AlertEmailHelpers::muteFooter($ticker, $ctx['unsub_url'] ?: null) . '
        ';
    }

    /** @param array<string, mixed>|null $atrZone */
    private function buildPriceZoneRow(?float $price, ?array $atrZone): string
    {
        if ($price === null) {
            return '';
        }
        $usdFmt  = static fn(float $v): string => '$' . number_format($v, 2);
        $usdText = $usdFmt($price);

        if ($atrZone === null || empty($atrZone['has_zone'])) {
            return '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Bieżąca cena:</td>'
                . '<td style="padding:8px;">' . $usdText . '</td></tr>';
        }

        $low  = (float) $atrZone['zone_low'];
        $high = (float) $atrZone['zone_high'];
        [$badgeColor, $badgeLabel] = AlertEmailHelpers::zoneBadge($price, $low, $high);

        return '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Cena / strefa kupna:</td>'
            . '<td style="padding:8px;">' . $usdText . ' &nbsp; (' . $usdFmt($low) . ' – ' . $usdFmt($high) . ')<br>'
            . '<span style="color:' . $badgeColor . ';font-weight:bold;">' . $badgeLabel . '</span></td></tr>';
    }

}
