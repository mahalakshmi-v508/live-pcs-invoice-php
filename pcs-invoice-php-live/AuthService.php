<?php
/**
 * Authentication and password-reset service.
 *
 * Security notes:
 * - New passwords use password_hash(); legacy MD5 hashes are upgraded on the
 *   next successful login.
 * - OTP codes are stored as bcrypt hashes, expire after 10 minutes and are
 *   limited to 5 verification attempts.
 * - The reset flow is bound to the current PHP session and protected by CSRF.
 * - Login, OTP request and password verification are rate limited in MySQL.
 */

// Shared connection ($c). Included at global scope so the global stays
// available even when AuthService is the first file that needs the database.
require_once __DIR__ . '/db.php';

class AuthException extends RuntimeException
{
}

class AuthRateLimitException extends AuthException
{
    private $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = max(1, $retryAfter);
        parent::__construct('Too many attempts. Please try again later.');
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}

class AuthRestartException extends AuthException
{
}

class AuthService
{
    private const OTP_TTL = 600;
    private const OTP_MAX_ATTEMPTS = 5;
    private const OTP_RESEND_COOLDOWN = 60;
    private const RESET_TTL = 900;

    private const LOGIN_MAX_ATTEMPTS = 8;
    private const LOGIN_WINDOW = 900;
    private const LOGIN_BLOCK = 300;

    private const LOGIN_IP_MAX_ATTEMPTS = 30;
    private const LOGIN_IP_WINDOW = 900;
    private const LOGIN_IP_BLOCK = 900;

    private const OTP_REQUEST_MAX = 3;
    private const OTP_REQUEST_WINDOW = 900;
    private const OTP_REQUEST_BLOCK = 900;

    private const OTP_REQUEST_IP_MAX = 10;
    private const OTP_REQUEST_IP_WINDOW = 3600;
    private const OTP_REQUEST_IP_BLOCK = 3600;

    // Used only to keep failed-login timing similar when an email is unknown.
    private const DUMMY_HASH = '$2y$10$dnnQQsw64A5WcFsRdpQq4eG6PXWPrvijdmKYILmD/1abd3DY7lwRG';

    private static $schemaReady = false;

    /** @var mysqli|null */
    private $db;

    public function __construct()
    {
    }

    /* ---------------------------------------------------------------------
     * Session / CSRF helpers
     * ------------------------------------------------------------------ */

    public function csrfToken(): string
    {
        if (
            empty($_SESSION['csrf_token'])
            || !is_string($_SESSION['csrf_token'])
            || strlen($_SESSION['csrf_token']) < 32
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function rotateCsrfToken(): string
    {
        unset($_SESSION['csrf_token']);

        return $this->csrfToken();
    }

    public function validateCsrf(string $token): bool
    {
        $stored = $_SESSION['csrf_token'] ?? '';

        return is_string($stored)
            && $stored !== ''
            && $token !== ''
            && hash_equals($stored, $token);
    }

    /* ---------------------------------------------------------------------
     * Login
     * ------------------------------------------------------------------ */

    /**
     * Verify a login and return the user row (without the password hash).
     * Returns null for invalid credentials.
     */
    public function login(string $email, string $password, string $ip = ''): ?array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 200) {
            return null;
        }

        $this->ensureSchema();

        $emailKey = $this->throttleKey('login', $email);
        $ipKey = $ip !== '' ? $this->throttleKey('login-ip', $ip) : '';

        $this->assertNotThrottled($emailKey);
        if ($ipKey !== '') {
            $this->assertNotThrottled($ipKey);
        }

        $db = $this->db();
        $stmt = $db->prepare(
            'SELECT id, email, first_name, last_name, address, mobile, password
             FROM invoice_user
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $verified = false;
        $hash = is_array($row) ? (string) ($row['password'] ?? '') : '';

        if ($row && $hash !== '' && password_verify($password, $hash)) {
            $verified = true;
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $this->updatePasswordHash((int) $row['id'], password_hash($password, PASSWORD_DEFAULT));
            }
        } elseif ($row && preg_match('/^[a-f0-9]{32}$/i', $hash) === 1 && hash_equals(strtolower($hash), md5($password))) {
            // Upgrade legacy MD5 passwords transparently after a valid login.
            $verified = true;
            $this->updatePasswordHash((int) $row['id'], password_hash($password, PASSWORD_DEFAULT));
        } elseif (!$row || $hash === '') {
            // Equalise timing a little for unknown accounts.
            password_verify($password, self::DUMMY_HASH);
        }

        if (!$verified) {
            $this->recordAttempt($emailKey, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW, self::LOGIN_BLOCK);
            if ($ipKey !== '') {
                $this->recordAttempt($ipKey, self::LOGIN_IP_MAX_ATTEMPTS, self::LOGIN_IP_WINDOW, self::LOGIN_IP_BLOCK);
            }

            return null;
        }

        $this->clearThrottle($emailKey);
        unset($row['password']);

        return $row;
    }

    /* ---------------------------------------------------------------------
     * Registration
     * ------------------------------------------------------------------ */

    public function register(
        string $firstName,
        string $lastName,
        string $email,
        string $address,
        string $mobile,
        string $password
    ): void {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $address = trim($address);
        $mobile = trim($mobile);
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('Please enter a valid email address.');
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new AuthException('Name is too long (maximum 100 characters).');
        }
        if (mb_strlen($mobile) > 20) {
            throw new AuthException('Mobile number is too long (maximum 20 characters).');
        }
        if (mb_strlen($address) > 5000) {
            throw new AuthException('Address is too long.');
        }
        if ($firstName === '') {
            throw new AuthException('Please enter your first name.');
        }

        $this->validatePassword($password);
        $db = $this->db();

        $stmt = $db->prepare('SELECT id FROM invoice_user WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($existing) {
            throw new AuthException('An account with this email already exists. Please log in instead.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            'INSERT INTO invoice_user (first_name, last_name, email, address, mobile, password)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssss', $firstName, $lastName, $email, $address, $mobile, $hash);
        $stmt->execute();
        $stmt->close();
    }

    /* ---------------------------------------------------------------------
     * Forgot password / OTP
     * ------------------------------------------------------------------ */

    /**
     * Send an OTP when the email exists. The caller should always show the same
     * generic message so that unknown emails cannot be enumerated.
     */
    public function requestPasswordReset(string $email, string $ip = ''): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('Please enter a valid email address.');
        }

        $this->loadMailConfig();
        if (SMTP_USER === '' || SMTP_PASSWORD === '') {
            throw new AuthException('Email service is not configured. Please contact the administrator.');
        }

        $this->ensureSchema();

        $emailKey = $this->throttleKey('otp-request', $email);
        $ipKey = $ip !== '' ? $this->throttleKey('otp-request-ip', $ip) : '';

        $this->assertNotThrottled($emailKey);
        if ($ipKey !== '') {
            $this->assertNotThrottled($ipKey);
        }

        $db = $this->db();
        $stmt = $db->prepare('SELECT id, first_name, email FROM invoice_user WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$user) {
            // Do not reveal whether the account exists.
            return;
        }

        // Enforce a short server-side cooldown between OTP requests.
        $stmt = $db->prepare('SELECT created_at FROM password_reset_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $userId = (int) $user['id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lastRequest = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($lastRequest && !empty($lastRequest['created_at'])) {
            $elapsed = time() - (int) strtotime($lastRequest['created_at']);
            if ($elapsed < self::OTP_RESEND_COOLDOWN) {
                throw new AuthRateLimitException(self::OTP_RESEND_COOLDOWN - $elapsed);
            }
        }

        $this->recordAttempt($emailKey, self::OTP_REQUEST_MAX, self::OTP_REQUEST_WINDOW, self::OTP_REQUEST_BLOCK);
        if ($ipKey !== '') {
            $this->recordAttempt(
                $ipKey,
                self::OTP_REQUEST_IP_MAX,
                self::OTP_REQUEST_IP_WINDOW,
                self::OTP_REQUEST_IP_BLOCK
            );
        }

        $otp = (string) random_int(100000, 999999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $now = time();
        $createdAt = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + self::OTP_TTL);
        $requestIp = $ip !== '' ? substr($ip, 0, 45) : null;

        $db->begin_transaction();
        try {
            $stmt = $db->prepare('UPDATE password_reset_otp SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL');
            $stmt->bind_param('si', $createdAt, $userId);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare(
                'INSERT INTO password_reset_otp
                    (user_id, email, otp_hash, attempts, max_attempts, request_ip, created_at, expires_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
            );
            $maxAttempts = self::OTP_MAX_ATTEMPTS;
            $stmt->bind_param(
                'ississs',
                $userId,
                $email,
                $otpHash,
                $maxAttempts,
                $requestIp,
                $createdAt,
                $expiresAt
            );
            $stmt->execute();
            $resetId = (int) $db->insert_id;
            $stmt->close();

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $_SESSION['pwd_reset'] = [
            'reset_id' => $resetId,
            'user_id' => $userId,
            'email' => $email,
            'stage' => 'otp',
            'requested_at' => $now,
        ];

        try {
            $this->sendOtpEmail($email, $otp, (string) ($user['first_name'] ?? ''));
        } catch (Throwable $e) {
            error_log('Password reset OTP email failed: ' . $e->getMessage());

            $stmt = $db->prepare('DELETE FROM password_reset_otp WHERE id = ?');
            $stmt->bind_param('i', $resetId);
            $stmt->execute();
            $stmt->close();

            unset($_SESSION['pwd_reset']);

            throw new AuthException('We could not send the OTP email right now. Please try again later.');
        }
    }

    public function verifyOtp(string $otp): void
    {
        $state = $_SESSION['pwd_reset'] ?? null;
        if (!is_array($state) || empty($state['reset_id']) || ($state['stage'] ?? '') !== 'otp') {
            throw new AuthRestartException('Please request a new OTP.');
        }

        $otp = preg_replace('/\D+/', '', $otp);
        if (!is_string($otp) || strlen($otp) !== 6) {
            throw new AuthException('Please enter the 6-digit OTP.');
        }

        $this->ensureSchema();

        $db = $this->db();
        $resetId = (int) $state['reset_id'];
        $userId = (int) ($state['user_id'] ?? 0);

        $stmt = $db->prepare(
            'SELECT id, otp_hash, attempts, max_attempts, expires_at, verified_at, consumed_at
             FROM password_reset_otp
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('ii', $resetId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || $row['consumed_at'] !== null || $row['verified_at'] !== null) {
            unset($_SESSION['pwd_reset']);
            throw new AuthRestartException('This OTP is no longer valid. Please request a new one.');
        }

        if (strtotime((string) $row['expires_at']) <= time()) {
            $this->consumeOtp($resetId);
            unset($_SESSION['pwd_reset']);
            throw new AuthRestartException('This OTP has expired. Please request a new one.');
        }

        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            $this->consumeOtp($resetId);
            unset($_SESSION['pwd_reset']);
            throw new AuthRestartException('Too many incorrect attempts. Please request a new OTP.');
        }

        if (!password_verify($otp, (string) $row['otp_hash'])) {
            $newAttempts = (int) $row['attempts'] + 1;
            $stmt = $db->prepare('UPDATE password_reset_otp SET attempts = ? WHERE id = ?');
            $stmt->bind_param('ii', $newAttempts, $resetId);
            $stmt->execute();
            $stmt->close();

            if ($newAttempts >= (int) $row['max_attempts']) {
                $this->consumeOtp($resetId);
                unset($_SESSION['pwd_reset']);
                throw new AuthRestartException('Too many incorrect attempts. Please request a new OTP.');
            }

            throw new AuthException('Incorrect OTP. Please try again.');
        }

        $verifiedAt = date('Y-m-d H:i:s');
        $stmt = $db->prepare('UPDATE password_reset_otp SET verified_at = ?, otp_hash = ? WHERE id = ?');
        $emptyHash = '';
        $stmt->bind_param('ssi', $verifiedAt, $emptyHash, $resetId);
        $stmt->execute();
        $stmt->close();

        $_SESSION['pwd_reset']['stage'] = 'reset';
        $_SESSION['pwd_reset']['verified_at'] = time();
    }

    public function resetPassword(string $password, string $confirmPassword): void
    {
        $state = $_SESSION['pwd_reset'] ?? null;
        if (!is_array($state) || ($state['stage'] ?? '') !== 'reset' || empty($state['verified_at'])) {
            throw new AuthException('Please verify your OTP before setting a new password.');
        }

        if (time() - (int) $state['verified_at'] > self::RESET_TTL) {
            unset($_SESSION['pwd_reset']);
            throw new AuthRestartException('Your reset session has expired. Please request a new OTP.');
        }

        $this->validatePassword($password);
        if (!hash_equals($password, $confirmPassword)) {
            throw new AuthException('New password and confirmation do not match.');
        }

        $this->ensureSchema();

        $db = $this->db();
        $resetId = (int) ($state['reset_id'] ?? 0);
        $userId = (int) ($state['user_id'] ?? 0);

        $stmt = $db->prepare(
            'SELECT id, verified_at, consumed_at
             FROM password_reset_otp
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('ii', $resetId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (
            !$row
            || $row['consumed_at'] !== null
            || $row['verified_at'] === null
            || strtotime((string) $row['verified_at']) < time() - self::RESET_TTL
        ) {
            unset($_SESSION['pwd_reset']);
            throw new AuthRestartException('Your reset session has expired. Please request a new OTP.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare('UPDATE invoice_user SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $updated = $stmt->affected_rows;
        $stmt->close();

        if ($updated < 1) {
            throw new AuthException('Unable to update the password. Please try again.');
        }

        $this->consumeOtp($resetId);

        $stmt = $db->prepare('UPDATE password_reset_otp SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL');
        $now = date('Y-m-d H:i:s');
        $stmt->bind_param('si', $now, $userId);
        $stmt->execute();
        $stmt->close();

        $this->clearThrottle($this->throttleKey('login', (string) ($state['email'] ?? '')));
        unset($_SESSION['pwd_reset']);
    }

    /* ---------------------------------------------------------------------
     * Password / validation helpers
     * ------------------------------------------------------------------ */

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new AuthException('Password must be at least 8 characters long.');
        }
        if (strlen($password) > 72) {
            throw new AuthException('Password must be 72 characters or fewer.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new AuthException('Password must contain at least one letter and one number.');
        }

        $common = [
            'password',
            'password1',
            'password123',
            '12345678',
            '123456789',
            'qwerty123',
            'admin123',
            'letmein1',
            'welcome1',
        ];
        if (in_array(strtolower($password), $common, true)) {
            throw new AuthException('Please choose a stronger password.');
        }
    }

    private function updatePasswordHash(int $userId, string $hash): void
    {
        $db = $this->db();
        $stmt = $db->prepare('UPDATE invoice_user SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function consumeOtp(int $resetId): void
    {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare('UPDATE password_reset_otp SET consumed_at = ? WHERE id = ?');
        $stmt->bind_param('si', $now, $resetId);
        $stmt->execute();
        $stmt->close();
    }

    /* ---------------------------------------------------------------------
     * Mail
     * ------------------------------------------------------------------ */

    private function loadMailConfig(): void
    {
        if (!defined('SMTP_HOST')) {
            require_once __DIR__ . '/mail_config.php';
        }
    }

    /**
     * Kept protected so tests can override delivery without sending real mail.
     */
    protected function sendOtpEmail(string $email, string $otp, string $name): void
    {
        $this->loadMailConfig();

        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }

        $safeName = htmlspecialchars($name !== '' ? $name : 'Customer', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 20;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($email);
        $mail->Subject = 'Your PCS Invoice password reset OTP';
        $mail->isHTML(true);
        $mail->Body = '
            <div style="font-family:Segoe UI,Arial,sans-serif;color:#222;line-height:1.6;">
                <h2 style="margin:0 0 12px;color:#1e3c72;">PCS Invoice</h2>
                <p>Hello ' . $safeName . ',</p>
                <p>We received a request to reset your invoice account password.</p>
                <p style="margin:24px 0;text-align:center;">
                    <span style="display:inline-block;padding:14px 24px;border-radius:8px;background:#f2f4ff;color:#1e3c72;font-size:30px;font-weight:700;letter-spacing:8px;">' . $safeOtp . '</span>
                </p>
                <p>This OTP is valid for <strong>10 minutes</strong> and can be used only once.</p>
                <p>If you did not request this, you can safely ignore this email. Your password will not change.</p>
                <p style="color:#777;font-size:13px;margin-top:24px;">For security, never share this OTP with anyone.</p>
            </div>';
        $mail->AltBody = "Hello " . ($name !== '' ? $name : 'Customer') . ",\n\n"
            . "Your PCS Invoice password reset OTP is: " . $otp . "\n\n"
            . "This OTP is valid for 10 minutes and can be used only once.\n"
            . "If you did not request this, ignore this email.\n";
        $mail->send();
    }

    /* ---------------------------------------------------------------------
     * Database
     * ------------------------------------------------------------------ */

    private function db(): mysqli
    {
        if ($this->db instanceof mysqli) {
            return $this->db;
        }

        // db.php is included at the top of this file, so global $c always exists.
        global $c;
        if (!$c instanceof mysqli) {
            throw new RuntimeException('Unable to connect to the database.');
        }

        $this->db = $c;

        return $this->db;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db = $this->db();
        $db->query(
            'CREATE TABLE IF NOT EXISTS password_reset_otp (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                email VARCHAR(150) NOT NULL,
                otp_hash VARCHAR(255) NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
                request_ip VARCHAR(45) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                verified_at DATETIME DEFAULT NULL,
                consumed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_password_reset_email (email, created_at),
                KEY idx_password_reset_user (user_id, consumed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        $db->query(
            'CREATE TABLE IF NOT EXISTS auth_throttle (
                throttle_key CHAR(64) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                blocked_until DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (throttle_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        self::$schemaReady = true;
    }

    /* ---------------------------------------------------------------------
     * Rate limiting
     * ------------------------------------------------------------------ */

    private function throttleKey(string $type, string $identifier): string
    {
        return hash('sha256', $type . '|' . strtolower(trim($identifier)));
    }

    private function assertNotThrottled(string $key): void
    {
        $remaining = $this->throttleRemaining($key);
        if ($remaining > 0) {
            throw new AuthRateLimitException($remaining);
        }
    }

    private function throttleRemaining(string $key): int
    {
        $db = $this->db();
        $stmt = $db->prepare('SELECT blocked_until FROM auth_throttle WHERE throttle_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || empty($row['blocked_until'])) {
            return 0;
        }

        return max(0, (int) strtotime($row['blocked_until']) - time());
    }

    private function recordAttempt(string $key, int $maxAttempts, int $windowSeconds, int $blockSeconds): void
    {
        $db = $this->db();
        $now = time();

        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'SELECT attempts, window_started_at
                 FROM auth_throttle
                 WHERE throttle_key = ?
                 FOR UPDATE'
            );
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                $startedAt = date('Y-m-d H:i:s', $now);
                $blockedUntil = $maxAttempts <= 1 ? date('Y-m-d H:i:s', $now + $blockSeconds) : null;
                $stmt = $db->prepare(
                    'INSERT INTO auth_throttle
                        (throttle_key, attempts, window_started_at, blocked_until, updated_at)
                     VALUES (?, 1, ?, ?, ?)'
                );
                $updatedAt = date('Y-m-d H:i:s', $now);
                $stmt->bind_param('ssss', $key, $startedAt, $blockedUntil, $updatedAt);
                $stmt->execute();
                $stmt->close();
            } else {
                $startedAt = strtotime((string) $row['window_started_at']);
                $attempts = (int) $row['attempts'];

                if ($startedAt <= $now - $windowSeconds) {
                    $attempts = 1;
                    $startedAt = $now;
                    $blockedUntil = $maxAttempts <= 1 ? date('Y-m-d H:i:s', $now + $blockSeconds) : null;
                } else {
                    $attempts++;
                    $blockedUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', $now + $blockSeconds) : null;
                }

                $stmt = $db->prepare(
                    'UPDATE auth_throttle
                     SET attempts = ?, window_started_at = ?, blocked_until = ?, updated_at = ?
                     WHERE throttle_key = ?'
                );
                $startedString = date('Y-m-d H:i:s', $startedAt);
                $updatedAt = date('Y-m-d H:i:s', $now);
                $stmt->bind_param('issss', $attempts, $startedString, $blockedUntil, $updatedAt, $key);
                $stmt->execute();
                $stmt->close();
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function clearThrottle(string $key): void
    {
        if ($key === '') {
            return;
        }

        $db = $this->db();
        $stmt = $db->prepare('DELETE FROM auth_throttle WHERE throttle_key = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }
}
