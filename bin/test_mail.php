<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/vendor/autoload.php';

foreach (file(ROOT_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (!str_starts_with(trim($line), '#') && str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$config = require ROOT_PATH . '/config/mail.php';
$svc    = new CVS\Mail\MailService(null, $config);

echo "SMTP host: " . ($config['smtp_host'] ?: '(empty)') . PHP_EOL;
echo "From: "      . ($config['from_email'] ?: '(empty)') . PHP_EOL;
echo "Admin: "     . ($config['admin_email'] ?: '(empty)') . PHP_EOL;
echo "Sending test mail..." . PHP_EOL;

$result = $svc->sendToAdmin(
    'Test CVS Mail — F-03',
    '<h2>Test serwisu maili CVS</h2><p>MailService dziala poprawnie na Cyber_Folks.</p>'
);

echo $result ? "SUCCESS: mail sent" : "FAILED: check error_log";
echo PHP_EOL;
