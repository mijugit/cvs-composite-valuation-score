<?php

declare(strict_types=1);

namespace CVS\Alerts;

use CVS\Auth\UserRepository;
use CVS\Mail\MailService;

/**
 * Detects CVS state changes and dispatches alert emails.
 *
 * Called from bin/rescore.php after each snapshot save.
 * An alert is sent when reco_swing OR golden_signal differs from the
 * last recorded state in alert_sent — preventing duplicate daily mails.
 */
class AlertService
{
    public function __construct(
        private readonly AlertRepository $alertRepo,
        private readonly MailService     $mail,
        private readonly UserRepository  $users
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Check if state changed for this ticker and notify affected users.
     *
     * @param array<string, mixed> $cvsResult CVSResult::toArray()
     * @return int Number of alerts sent (0 = no change or no eligible users)
     */
    public function checkAndNotify(string $ticker, array $cvsResult): int
    {
        $currentReco   = (string) ($cvsResult['swing']['recommendation'] ?? '');
        $currentSignal = $cvsResult['golden_signal'] !== '' ? ($cvsResult['golden_signal'] ?? null) : null;
        $cvsSwing      = isset($cvsResult['swing']['cvs']) ? (float) $cvsResult['swing']['cvs'] : null;

        $userIds = $this->alertRepo->findUsersWatchingTicker($ticker);
        if (empty($userIds)) {
            return 0;
        }

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

            $html = $this->buildHtml(
                $ticker,
                $oldReco,
                $currentReco ?: null,
                $oldSignal,
                $currentSignal,
                $cvsSwing
            );

            $subject = sprintf('CVS Alert: %s — zmiana sygnału', $ticker);

            $this->mail->send((string) $user['email'], $subject, $html);

            // Update last sent state regardless of mail success (avoid spam on SMTP failure).
            $this->alertRepo->upsertSent($userId, $ticker, $currentReco ?: null, $currentSignal);
            $sent++;
        }

        return $sent;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function buildHtml(
        string  $ticker,
        ?string $oldReco,
        ?string $newReco,
        ?string $oldSignal,
        ?string $newSignal,
        ?float  $cvsSwing
    ): string {
        $recoLine = ($oldReco !== null && $oldReco !== '')
            ? htmlspecialchars($oldReco) . ' → ' . htmlspecialchars((string) $newReco)
            : htmlspecialchars((string) $newReco);

        $signalLine = '';
        if ($newSignal !== null) {
            $sigLabel = match ($newSignal) {
                'strong'    => '⭐⭐ Silny sygnał (wartość + momentum)',
                'watchlist' => '⭐ Obserwuj (setup fundamentalny)',
                'momentum'  => '↑ Momentum',
                default     => htmlspecialchars($newSignal),
            };
            $signalLine = '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Złoty sygnał:</td>'
                . '<td style="padding:8px;">' . $sigLabel . '</td></tr>';
        }

        $scoreLine = $cvsSwing !== null
            ? '<tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">CVS Swing:</td>'
              . '<td style="padding:8px;font-weight:bold;">' . number_format($cvsSwing, 1) . ' / 100</td></tr>'
            : '';

        return '
            <h2 style="color:#1e3a5f;">CVS Alert — zmiana sygnału: ' . htmlspecialchars($ticker) . '</h2>
            <table style="border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;width:140px;">Ticker:</td>
                    <td style="padding:8px;font-weight:bold;font-size:16px;">' . htmlspecialchars($ticker) . '</td></tr>
                <tr><td style="padding:8px;background:#f0f4f8;font-weight:bold;">Rekomendacja:</td>
                    <td style="padding:8px;">' . $recoLine . '</td></tr>
                ' . $scoreLine . $signalLine . '
            </table>
            <p style="margin-top:16px;">
                <a href="https://cvs.timeflow.fun/analysis/' . urlencode($ticker) . '"
                   style="background:#1e3a5f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;">
                    Otwórz analizę ' . htmlspecialchars($ticker) . ' →
                </a>
            </p>
            <p style="color:#888;font-size:11px;margin-top:12px;">
                Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.<br>
                Wygenerowano automatycznie przez CVS Composite Valuation Score.
            </p>
        ';
    }
}
