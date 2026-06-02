<?php

declare(strict_types=1);

namespace CVS\Tests\Mail;

use CVS\Mail\MailService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MailService.
 *
 * No real SMTP server needed — tests the graceful-failure path
 * (empty SMTP_HOST → return false) and utility methods.
 */
class MailServiceTest extends TestCase
{
    // ------------------------------------------------------------------
    // Graceful failure when SMTP not configured
    // ------------------------------------------------------------------

    public function test_send_returns_false_when_smtp_not_configured(): void
    {
        // Empty smtp_host → graceful false, no exception.
        $svc = new MailService(null, [
            'smtp_host'  => '',
            'smtp_port'  => 465,
            'smtp_user'  => '',
            'smtp_pass'  => '',
            'smtp_encryption' => 'ssl',
            'from_email' => 'test@test.com',
            'from_name'  => 'Test',
            'admin_email'=> 'admin@test.com',
        ]);

        $result = $svc->send('recipient@test.com', 'Subject', '<p>Hello</p>');

        $this->assertFalse($result);
    }

    public function test_send_to_admin_returns_false_when_smtp_not_configured(): void
    {
        $svc = new MailService(null, [
            'smtp_host'   => '',
            'admin_email' => 'admin@test.com',
        ]);

        $this->assertFalse($svc->sendToAdmin('Subject', '<p>Alert</p>'));
    }

    public function test_send_to_admin_returns_false_when_admin_email_empty(): void
    {
        // SMTP configured but admin_email missing → false.
        $svc = new MailService(null, [
            'smtp_host'   => 'smtp.example.com',
            'smtp_port'   => 465,
            'smtp_user'   => 'user',
            'smtp_pass'   => 'pass',
            'smtp_encryption' => 'ssl',
            'from_email'  => 'from@example.com',
            'from_name'   => 'CVS',
            'admin_email' => '',   // <-- empty
        ]);

        $this->assertFalse($svc->sendToAdmin('Subject', '<p>Alert</p>'));
    }

    // ------------------------------------------------------------------
    // htmlToPlainText (tested via public send — we test indirectly via
    // the AltBody not throwing; direct test via reflection not needed)
    // ------------------------------------------------------------------

    public function test_send_does_not_throw_on_empty_html(): void
    {
        $svc = new MailService(null, ['smtp_host' => '']);

        // Must return false quietly, not throw.
        $result = $svc->send('x@x.com', 'S', '');
        $this->assertFalse($result);
    }
}
