<?php
// SMTP configuration for PHPMailer email sending.
//
// Real values are read from environment variables. For local WAMP/XAMPP
// development a .env file next to this file is used as a fallback. That file
// is ignored by git and blocked from web access by .htaccess, so secrets are
// never committed or downloadable.

$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($envLines as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || $envLine[0] === '#' || strpos($envLine, '=') === false) {
            continue;
        }

        [$envKey, $envValue] = explode('=', $envLine, 2);
        $envKey = trim($envKey);
        $envValue = trim($envValue);

        // Existing environment variables always win over the .env file.
        if ($envKey === '' || getenv($envKey) !== false) {
            continue;
        }

        if (strlen($envValue) >= 2) {
            $firstChar = $envValue[0];
            $lastChar = substr($envValue, -1);
            if (($firstChar === '"' || $firstChar === "'") && $firstChar === $lastChar) {
                $envValue = substr($envValue, 1, -1);
            }
        }

        putenv($envKey . '=' . $envValue);
        $_ENV[$envKey] = $envValue;
    }

    unset($envLines, $envLine, $envKey, $envValue, $firstChar, $lastChar);
}

define('SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', intval(getenv('MAIL_SMTP_PORT') ?: 587));
define('SMTP_SECURE', getenv('MAIL_SMTP_SECURE') ?: 'tls');
define('SMTP_USER', getenv('MAIL_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'PCS Invoice');
$smtpPassword = getenv('MAIL_SMTP_PASSWORD') ?: '';
define('SMTP_PASSWORD', preg_replace('/\s+/', '', (string) $smtpPassword));
