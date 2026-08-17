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
 * Detects CVS state changes and dispatches ONE digest email per user per
 * rescore run, listing every watched ticker whose state changed in that run.
 *
 * checkAndNotify() is called from bin/rescore.php once per ticker, inside the
 * scoring loop — it detects the change, records it in alert_sent (dedup
 * unchanged from before), and QUEUES a row in memory. Nothing is sent yet.
 * flushDigests() is called once, after the loop ends, and sends the
 * accumulated per-user digests. Before this, each changed ticker sent its own
 * mail immediately — a user watching several tickers that all moved in the
 * same run received several mails within seconds of each other, which reads
 * as spam regardless of how legitimate each individual alert was.
 */
class AlertService
{
    /** @var array<string, mixed> */
    private array $trajectoryConfig;

    /** @var array<int, list<array<string, mixed>>> userId => queued row contexts for this run */
    private array $pending = [];

    /** @var array<int, string> userId => email, captured at queue time */
    private array $pendingEmails = [];

    /** @var array<string, array<string, mixed>|null> "ticker|modelVersion" => TrajectoryCalculator::summarise() result, memoised for one flushDigests() call — several users commonly watch the same ticker in the same run */
    private array $trajectoryCache = [];

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
     * Check if state changed for this ticker and queue a digest row for each
     * affected user. Call once per ticker, from inside the rescore loop.
     *
     * @param array<string, mixed> $cvsResult   CVSResult::toArray()
     * @param string|null          $companyName Yahoo long name (FinancialDataFetcher 'long_name')
     * @param float|null           $price       Current price at scoring time (USD)
     * @param array<string, mixed>|null $atrZone AtrZoneCalculator::compute() output (has_zone/zone_low/zone_high/stop_swing/stop_fund)
     * @return int Number of users queued for this ticker (0 = no change or no eligible users)
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

        $userIds = $this->alertRepo->findUsersWatchingTicker($ticker);
        if (empty($userIds)) {
            return 0;
        }

        $queued = 0;

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

            $user = $this->users->findById($userId);
            if ($user === null || empty($user['email'])) {
                continue;
            }

            $this->pendingEmails[$userId] = (string) $user['email'];
            $this->pending[$userId][] = [
                'ticker'        => $ticker,
                'company_name'  => $companyName,
                'price'         => $price,
                'atr_zone'      => $atrZone,
                'reco_old'      => $last['last_reco']   ?? null,
                'reco_new'      => $currentReco ?: null,
                'signal_old'    => $last['last_signal']  ?? null,
                'signal_new'    => $currentSignal,
                'cvs_swing'     => $cvsSwing,
                'cvs_fund'      => $cvsFund,
                'fund_reco'     => $fundReco,
                'model_version' => $modelVersion,
            ];

            // Update last-sent state as soon as the change is detected, not
            // when the digest actually sends — an SMTP failure at flush time
            // must not cause the same change to be queued again tomorrow.
            $this->alertRepo->upsertSent($userId, $ticker, $currentReco ?: null, $currentSignal);
            $queued++;
        }

        return $queued;
    }

    /**
     * Send the accumulated per-user digests and clear the queue. Call once,
     * after the rescore loop that called checkAndNotify() per ticker ends.
     *
     * @return int Number of digest emails sent (one per user with >= 1 queued row)
     */
    public function flushDigests(): int
    {
        $sent = 0;

        foreach ($this->pending as $userId => $rows) {
            $email = $this->pendingEmails[$userId] ?? null;
            if ($email === null || $rows === []) {
                continue;
            }

            // Alphabetical, not detection order — a user scanning for one
            // ticker among many should not have to read the whole table.
            usort($rows, static fn(array $a, array $b) => $a['ticker'] <=> $b['ticker']);

            $unsubToken = hash_hmac('sha256', 'unsub:' . $userId . ':' . $email, $_ENV['APP_SECRET'] ?? '');
            $unsubUrl   = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun')
                . '/alerts/unsubscribe?uid=' . $userId . '&token=' . $unsubToken;

            $html = $this->buildDigestHtml($rows, $unsubUrl);
            $subject = count($rows) === 1
                ? sprintf('CVS Alert: %s — zmiana sygnału', $rows[0]['ticker'])
                : sprintf('CVS Alert: %d zmian w Twojej watchliście', count($rows));

            $this->mail->send($email, $subject, $html);
            $sent++;
        }

        $this->pending = [];
        $this->pendingEmails = [];
        $this->trajectoryCache = [];

        return $sent;
    }

    /**
     * Manual production test: renders and sends a one-row digest for a
     * ticker's most recent real snapshot to an arbitrary address. Bypasses
     * per-user dedup entirely — never call this from the rescore pipeline, only
     * from bin/send_test_mail.php for verifying rendering/deliverability.
     * Goes through the same buildDigestHtml() a real multi-row digest uses, so
     * what this renders is never a different template than production sends.
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

        $row = [
            'ticker'       => $ticker,
            'company_name' => $companyName ?? ($snapshot['company_name'] ?? null),
            'price'        => $price ?? (isset($snapshot['price_at_snapshot']) ? (float) $snapshot['price_at_snapshot'] : null),
            'atr_zone'     => $atrZone,
            'reco_old'     => null,
            'reco_new'     => $snapshot['reco_swing'] ?? null,
            'signal_old'   => null,
            'signal_new'   => $snapshot['golden_signal'] ?? null,
            'cvs_swing'    => isset($snapshot['cvs_swing']) ? (float) $snapshot['cvs_swing'] : null,
            'cvs_fund'     => isset($snapshot['cvs_fund'])  ? (float) $snapshot['cvs_fund']  : null,
            'fund_reco'    => $snapshot['reco_fund'] ?? null,
            'model_version' => $liveModelVersion,
        ];

        $html = $this->buildDigestHtml([$row], '');
        $subject = sprintf('[TEST] CVS Alert: %s — podgląd', $ticker);

        return $this->mail->send($toEmail, $subject, $html);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Trajectory is ticker-level, not per-user — memoised per (ticker, model
     * version) for the lifetime of one flushDigests() call, since several
     * users commonly watch the same ticker in the same run. Gracefully
     * returns null on any failure (e.g. missing table in a minimal test
     * setup) — a digest without a trend arrow is fine; a digest that never
     * sends is not.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTrajectory(string $ticker, string $modelVersion): ?array
    {
        if ($modelVersion === '') {
            return null;
        }

        $cacheKey = $ticker . '|' . $modelVersion;
        if (array_key_exists($cacheKey, $this->trajectoryCache)) {
            return $this->trajectoryCache[$cacheKey];
        }

        try {
            $windowDays = (int) ($this->trajectoryConfig['window_days'] ?? 90);
            $minPoints  = (int) ($this->trajectoryConfig['min_points']  ?? 2);
            $since      = (new DateTimeImmutable())->modify('-' . $windowDays . ' days');
            $rows       = $this->snapshots->findTrajectory($ticker, $since, $modelVersion);
            $result     = TrajectoryCalculator::summarise($rows, $minPoints);
        } catch (Throwable $e) {
            $result = null;
        }

        return $this->trajectoryCache[$cacheKey] = $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function buildDigestHtml(array $rows, string $unsubUrl): string
    {
        $appUrl = $_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun';
        $count  = count($rows);
        $today  = (new DateTimeImmutable())->format('d.m.Y');

        $rowsHtml = '';
        foreach ($rows as $ctx) {
            $rowsHtml .= $this->buildDigestRow($ctx);
        }

        $title = $count === 1
            ? 'CVS Alert — zmiana sygnału'
            : sprintf('CVS Alert — %d zmian w Twojej watchliście', $count);

        $unsubLine = $unsubUrl !== ''
            ? '<a href="' . htmlspecialchars($unsubUrl) . '" style="color:#64748b;">wypisz się ze wszystkich alertów</a>'
            : '';

        return '
        <div style="background:#f4f6f9;padding:24px 12px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
        <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;
                    box-shadow:0 1px 3px rgba(15,23,42,.08);">
            <div style="padding:24px 24px 16px 24px;">
                <div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:#2f74c4;text-transform:uppercase;">
                    CVS · Composite Valuation Score
                </div>
                <h1 style="margin:6px 0 4px 0;font-size:20px;line-height:1.3;color:#0e1b2f;">' . htmlspecialchars($title) . '</h1>
                <div style="font-size:12px;color:#64748b;">Przeliczono ' . $today . ' · Twoja watchlista</div>
            </div>
            <table role="presentation" style="width:100%;border-collapse:collapse;">
                <tr style="background:#f8fafc;">
                    <td style="padding:8px 12px;font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Spółka</td>
                    <td style="padding:8px 12px;font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Rekomendacja</td>
                    <td style="padding:8px 12px;font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Sygnał</td>
                    <td style="padding:8px 12px;font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">CVS</td>
                    <td style="padding:8px 12px;font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b;text-transform:uppercase;border-bottom:1px solid #e2e8f0;">Cena</td>
                </tr>
                ' . $rowsHtml . '
            </table>
            <div style="padding:20px 24px 24px 24px;">
                <a href="' . htmlspecialchars($appUrl) . '/"
                   style="display:inline-block;background:#2f74c4;color:#ffffff;font-weight:600;font-size:14px;
                          padding:10px 22px;text-decoration:none;border-radius:8px;">
                    Otwórz panel →
                </a>
                <p style="color:#64748b;font-size:11px;margin-top:18px;line-height:1.5;">
                    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.<br>
                    Wygenerowano automatycznie przez CVS Composite Valuation Score.
                </p>
                <p style="color:#64748b;font-size:10px;margin-top:10px;">
                    Wyciszysz pojedynczy ticker na jego stronie analizy (link w wierszu powyżej).'
                    . ($unsubLine !== '' ? ' Albo ' . $unsubLine . '.' : '') . '
                </p>
            </div>
        </div>
        </div>';
    }

    /** @param array<string, mixed> $ctx */
    private function buildDigestRow(array $ctx): string
    {
        $ticker      = (string) $ctx['ticker'];
        $tickerEsc   = htmlspecialchars($ticker);
        $analysisUrl = ($_ENV['APP_URL'] ?? 'https://cvs.timeflow.fun') . '/analysis/' . urlencode($ticker);
        $companyName = $ctx['company_name'] ?? null;

        $recoOld = $ctx['reco_old'] ?? null;
        $recoNew = $ctx['reco_new'] ?? null;
        $recoCell = ($recoOld !== null && $recoOld !== '' && $recoOld !== $recoNew)
            ? AlertEmailHelpers::recoBadge($recoOld) . ' <span style="color:#64748b;">→</span> ' . AlertEmailHelpers::recoBadge($recoNew)
            : AlertEmailHelpers::recoBadge($recoNew);

        $signalOld = $ctx['signal_old'] ?? null;
        $signalNew = $ctx['signal_new'] ?? null;
        $signalCell = ($signalOld !== $signalNew && ($signalOld !== null || $signalNew !== null))
            ? '<span style="font-size:11px;color:#64748b;">' . AlertEmailHelpers::signalLabel($signalOld) . ' →</span><br>'
              . '<span style="font-size:12px;">' . AlertEmailHelpers::signalLabel($signalNew) . '</span>'
            : '<span style="font-size:12px;">' . AlertEmailHelpers::signalLabel($signalNew) . '</span>';

        $cvsSwing = $ctx['cvs_swing'] ?? null;
        $cvsFund  = $ctx['cvs_fund']  ?? null;

        // Score trend (90d d/d), not the same thing as the reco/signal change
        // this row already announces — a tiny arrow next to the Swing figure
        // rather than its own column, since it is a secondary signal.
        $trendArrow = '';
        $trajectory = $this->fetchTrajectory($ticker, (string) ($ctx['model_version'] ?? ''));
        if ($trajectory !== null && !empty($trajectory['has_trajectory'])) {
            $d = $trajectory['delta_daily'] ?? null;
            $d = $d !== null ? (float) $d : null;
            if ($d !== null && $d !== 0.0) {
                $trendArrow = $d > 0
                    ? ' <span style="color:#22c55e;">▲</span>'
                    : ' <span style="color:#ef4444;">▼</span>';
            }
        }

        $cvsCell  = ($cvsSwing !== null || $cvsFund !== null)
            ? '<span style="font-size:12px;color:#334155;">S ' . ($cvsSwing !== null ? number_format((float) $cvsSwing, 1) : '–') . '</span>' . $trendArrow . '<br>'
              . '<span style="font-size:12px;color:#a16207;font-weight:600;">F ' . ($cvsFund !== null ? number_format((float) $cvsFund, 1) : '–') . '</span>'
            : '<span style="color:#64748b;">–</span>';

        $priceCell = $this->buildDigestPriceCell($ctx['price'] ?? null, $ctx['atr_zone'] ?? null);

        return '
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 12px;vertical-align:top;">
                        <div style="font-size:14px;font-weight:700;color:#0e1b2f;">' . $tickerEsc . '</div>'
                        . ($companyName !== null && $companyName !== ''
                            ? '<div style="font-size:11px;color:#64748b;max-width:160px;">' . htmlspecialchars((string) $companyName) . '</div>'
                            : '') . '
                        <a href="' . htmlspecialchars($analysisUrl) . '" style="font-size:10px;color:#2f74c4;text-decoration:none;">Otwórz · wycisz →</a>
                    </td>
                    <td style="padding:10px 12px;vertical-align:top;">' . $recoCell . '</td>
                    <td style="padding:10px 12px;vertical-align:top;">' . $signalCell . '</td>
                    <td style="padding:10px 12px;vertical-align:top;">' . $cvsCell . '</td>
                    <td style="padding:10px 12px;vertical-align:top;">' . $priceCell . '</td>
                </tr>';
    }

    /** @param array<string, mixed>|null $atrZone */
    private function buildDigestPriceCell(?float $price, ?array $atrZone): string
    {
        if ($price === null) {
            return '<span style="color:#64748b;">–</span>';
        }

        $usdText = '$' . number_format($price, 2);

        if ($atrZone === null || empty($atrZone['has_zone'])) {
            return '<span style="font-size:12px;color:#334155;">' . $usdText . '</span>';
        }

        $low  = (float) $atrZone['zone_low'];
        $high = (float) $atrZone['zone_high'];
        [$badgeColor, $badgeLabel] = AlertEmailHelpers::zoneBadge($price, $low, $high);
        // Compact form of the same three-state label the analysis page uses —
        // full sentence there, a few words here.
        $shortLabel = match (true) {
            str_contains($badgeLabel, 'w strefie')  => 'w strefie',
            str_contains($badgeLabel, 'Powyżej')     => 'powyżej',
            default                                  => 'poniżej',
        };

        return '<span style="font-size:12px;color:#334155;">' . $usdText . '</span><br>'
            . '<span style="font-size:10px;color:' . $badgeColor . ';font-weight:600;">' . $shortLabel . '</span>';
    }
}
