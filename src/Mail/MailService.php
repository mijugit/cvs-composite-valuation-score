<?php

declare(strict_types=1);

namespace CVS\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Transactional mail service — thin wrapper around PHPMailer.
 *
 * Configuration from config/mail.php (reads $_ENV).
 * Graceful failure: SMTP not configured or send throws → error_log + return false.
 * Never throws. Zero bulk/marketing mail.
 *
 * Pattern ported from C:\python\blog\api\mailer.php.
 */
class MailService
{
    /** @var array<string, mixed> */
    private array $config;

    private ?PHPMailer $injectedMailer;

    /**
     * @param PHPMailer|null       $mailer Injected mailer for testing (null = build from config)
     * @param array<string, mixed> $config Override config (empty = load from config/mail.php)
     */
    public function __construct(?PHPMailer $mailer = null, array $config = [])
    {
        $this->injectedMailer = $mailer;
        $this->config = $config !== []
            ? $config
            : require dirname(__DIR__, 2) . '/config/mail.php';
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Send a transactional email.
     *
     * @param string      $to             Recipient address
     * @param string      $subject        Subject line
     * @param string      $htmlBody       HTML body
     * @param string|null $altBody        Plain-text fallback (auto-stripped if null)
     * @param string|null $unsubscribeUrl Optional List-Unsubscribe URL
     */
    public function send(
        string  $to,
        string  $subject,
        string  $htmlBody,
        ?string $altBody = null,
        ?string $unsubscribeUrl = null
    ): bool {
        $mail = $this->buildMailer();

        if ($mail === null) {
            return false;
        }

        try {
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody ?? $this->htmlToPlainText($htmlBody);

            if ($unsubscribeUrl !== null) {
                $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
                $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }

            $mail->send();
            return true;
        } catch (MailerException $e) {
            error_log('[Mail] Failed to send to ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a notification to the admin address.
     */
    public function sendToAdmin(string $subject, string $htmlBody): bool
    {
        $adminEmail = (string) ($this->config['admin_email'] ?? '');

        if ($adminEmail === '') {
            error_log('[Mail] sendToAdmin: ADMIN_EMAIL not configured');
            return false;
        }

        return $this->send($adminEmail, $subject, $htmlBody);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a configured PHPMailer instance, or return the injected one.
     * Returns null when SMTP is not configured (graceful skip).
     */
    private function buildMailer(): ?PHPMailer
    {
        // Test injection — use as-is.
        if ($this->injectedMailer !== null) {
            return $this->injectedMailer;
        }

        $smtpHost = (string) ($this->config['smtp_host'] ?? '');

        if ($smtpHost === '') {
            error_log('[Mail] SMTP not configured — skipping mail');
            return null;
        }

        $mail = new PHPMailer(true);
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->Port       = (int) ($this->config['smtp_port']       ?? 465);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) ($this->config['smtp_user']    ?? '');
        $mail->Password   = (string) ($this->config['smtp_pass']    ?? '');
        $mail->SMTPSecure = (string) ($this->config['smtp_encryption'] ?? 'ssl');

        $fromEmail = (string) ($this->config['from_email'] ?? '');
        $fromName  = (string) ($this->config['from_name']  ?? 'CVS');

        if ($fromEmail !== '') {
            $mail->setFrom($fromEmail, $fromName);
            $mail->addReplyTo($fromEmail, $fromName);

            $domain = explode('@', $fromEmail)[1] ?? 'timeflow.fun';
            $mail->MessageID = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        }

        $mail->XMailer = ' '; // suppress default X-Mailer header
        $mail->isHTML(true);

        return $mail;
    }

    /**
     * Strip HTML tags and convert to readable plain text for AltBody.
     */
    private function htmlToPlainText(string $html): string
    {
        // Links → "text (url)"
        $text = (string) preg_replace('/<a[^>]+href="([^"]*)"[^>]*>(.*?)<\/a>/si', '$2 ($1)', $html);
        // Line breaks
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
        // Block elements → double newline
        $text = (string) preg_replace('/<\/(p|div|h[1-6]|tr|li)>/i', "\n\n", $text);
        $text = (string) preg_replace('/<(p|div|h[1-6]|tr)[^>]*>/i', '', $text);
        // List items
        $text = (string) preg_replace('/<li[^>]*>/i', '- ', $text);
        // Strip remaining tags
        $text = strip_tags($text);
        // Decode entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // Normalize whitespace
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
