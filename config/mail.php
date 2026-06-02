<?php

declare(strict_types=1);

/**
 * Mail (SMTP) configuration.
 *
 * All values read from $_ENV — never hard-coded.
 * Variable names mirror the blog pattern (SMTP_HOST, SMTP_USER, …).
 * Empty SMTP_HOST → MailService returns false gracefully (no send).
 */
return [
    'smtp_host'       => (string) ($_ENV['SMTP_HOST']       ?? ''),
    'smtp_port'       => (int)    ($_ENV['SMTP_PORT']        ?? 465),
    'smtp_user'       => (string) ($_ENV['SMTP_USER']        ?? ''),
    'smtp_pass'       => (string) ($_ENV['SMTP_PASSWORD']    ?? ''),
    'smtp_encryption' => (string) ($_ENV['SMTP_ENCRYPTION']  ?? 'ssl'),
    'from_email'      => (string) ($_ENV['SMTP_FROM_EMAIL']  ?? ''),
    'from_name'       => (string) ($_ENV['SMTP_FROM_NAME']   ?? 'CVS Composite Valuation Score'),
    'admin_email'     => (string) ($_ENV['ADMIN_EMAIL']      ?? ''),
];
