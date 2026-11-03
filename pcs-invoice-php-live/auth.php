<?php
/**
 * JSON endpoint for the login-page forgot-password flow.
 * All state-changing actions require a valid CSRF token.
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
ini_set('display_errors', '0');

function authJsonResponse(int $status, string $message, array $extra = []): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode(
        array_merge(['status' => $status, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

require_once __DIR__ . '/session.php';
ensureSessionStarted();
require_once __DIR__ . '/AuthService.php';

try {
    $auth = new AuthService();
    $action = (string) ($_POST['action'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!$auth->validateCsrf($csrfToken)) {
        authJsonResponse(
            0,
            'Your security token has expired. Please refresh the page and try again.',
            ['code' => 'csrf']
        );
    }

    if ($action === 'request_otp') {
        $auth->requestPasswordReset(
            (string) ($_POST['email'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );

        // Only remember emails that actually have a reset request (registered accounts).
        $resetEmail = (string) ($_SESSION['pwd_reset']['email'] ?? '');
        if ($resetEmail !== '') {
            rememberEmailCookie($resetEmail);
        }

        authJsonResponse(
            1,
            'If this email is registered, an OTP has been sent. Check your inbox and spam folder.'
        );
    }

    if ($action === 'verify_otp') {
        $auth->verifyOtp((string) ($_POST['otp'] ?? ''));

        authJsonResponse(1, 'OTP verified. Please set your new password.');
    }

    if ($action === 'reset_password') {
        $resetEmail = (string) ($_SESSION['pwd_reset']['email'] ?? '');

        $auth->resetPassword(
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );

        if ($resetEmail !== '') {
            rememberEmailCookie($resetEmail);
        }

        authJsonResponse(1, 'Password updated successfully. You can now log in with your new password.');
    }

    authJsonResponse(0, 'Invalid request.');
} catch (AuthRateLimitException $e) {
    $minutes = max(1, (int) ceil($e->getRetryAfter() / 60));
    authJsonResponse(
        0,
        'Too many attempts. Please try again after ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.',
        ['code' => 'rate_limit', 'retry_after' => $e->getRetryAfter()]
    );
} catch (AuthRestartException $e) {
    authJsonResponse(0, $e->getMessage(), ['code' => 'restart']);
} catch (AuthException $e) {
    authJsonResponse(0, $e->getMessage(), ['code' => 'auth']);
} catch (Throwable $e) {
    error_log('Auth endpoint error: ' . $e->getMessage());
    authJsonResponse(0, 'Something went wrong. Please try again.');
}
