<?php
const APP_SESSION_LIFETIME = 604800; // 7 days

function ensureSessionStarted()
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        $cookieParams = [
            'lifetime' => APP_SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ];

        if (!headers_sent()) {
            ini_set('session.gc_maxlifetime', (string) APP_SESSION_LIFETIME);
            ini_set('session.cookie_lifetime', (string) APP_SESSION_LIFETIME);
            session_set_cookie_params($cookieParams);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_start();
    }

    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

function clearSessionAndCookie()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        !empty($params['secure']),
        !empty($params['httponly'])
    );

    session_unset();
    session_destroy();
}

/**
 * Email last used on this device, used only to pre-fill the login and
 * forgot-password forms. Never contains a password or token.
 */
function rememberedEmail(): string
{
    $email = $_COOKIE['pcs_last_email'] ?? '';

    if (!is_string($email)) {
        return '';
    }

    $email = strtolower(trim($email));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function rememberEmailCookie(string $email): void
{
    $email = strtolower(trim($email));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || headers_sent()) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    setcookie('pcs_last_email', $email, [
        'expires' => time() + 60 * 60 * 24 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE['pcs_last_email'] = $email;
}
