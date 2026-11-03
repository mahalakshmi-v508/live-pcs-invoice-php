<?php
require_once __DIR__ . '/session.php';
ensureSessionStarted();
require_once __DIR__ . '/AuthService.php';

$auth = new AuthService();
$csrfToken = $auth->csrfToken();
$loginError = '';
$loginEmail = rememberedEmail();
if ($loginEmail === '' && !empty($_SESSION['email']) && is_string($_SESSION['email'])) {
    $sessionEmail = strtolower(trim($_SESSION['email']));
    if (filter_var($sessionEmail, FILTER_VALIDATE_EMAIL)) {
        $loginEmail = $sessionEmail;
    }
}
$showForgotModal = isset($_GET['forgot']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $loginEmail = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['pwd'] ?? '');

    if (!$auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        $loginError = 'Your session expired. Please refresh the page and try again.';
    } elseif ($loginEmail === '' || $password === '') {
        $loginError = 'Please enter your email and password.';
    } else {
        try {
            $user = $auth->login($loginEmail, $password, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

            if ($user !== null) {
                session_regenerate_id(true);

                $fullName = trim(
                    (string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')
                );
                $_SESSION['user'] = $fullName !== '' ? $fullName : (string) ($user['email'] ?? $loginEmail);
                $_SESSION['userId'] = (int) ($user['id'] ?? 0);
                $_SESSION['email'] = (string) ($user['email'] ?? $loginEmail);
                $_SESSION['address'] = (string) ($user['address'] ?? '');
                $_SESSION['mobile'] = (string) ($user['mobile'] ?? '');
                $_SESSION['login_time'] = time();

                rememberEmailCookie((string) ($user['email'] ?? $loginEmail));
                $auth->rotateCsrfToken();

                header('Location: main.php', true, 303);
                exit;
            }

            $loginError = 'Invalid email or password.';
        } catch (AuthRateLimitException $e) {
            $minutes = max(1, (int) ceil($e->getRetryAfter() / 60));
            $loginError = 'Too many failed attempts. Please try again after '
                . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
        } catch (Throwable $e) {
            error_log('Login error: ' . $e->getMessage());
            $loginError = 'Unable to sign in right now. Please try again later.';
        }
    }

    $csrfToken = $auth->csrfToken();
}

include __DIR__ . '/header.php';
?>
<title>Login page</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="js/invoice.js"></script>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%);
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.login-wrapper {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
    width: 100%;
    max-width: 350px;
    min-height: 450px;
    padding: 40px 30px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.logo {
    text-align: left;
    margin-bottom: 30px;
}

.logo img {
    max-width: 150px;
}

.login-wrapper h4 {
    font-size: 24px;
    text-align: center;
    margin-bottom: 25px;
    color: #222;
}

.form-control {
    width: 100%;
    height: 45px;
    padding: 0 15px;
    border: 1px solid #d1d3d4;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 15px;
    background: #f9f9f9;
    transition: all 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: #8548d0;
    background: #fff;
    box-shadow: none;
}

.form-control[readonly] {
    background: #eef1f7;
    color: #444;
    cursor: default;
}

.email-edit-link {
    display: inline-block;
    margin: -10px 0 16px;
}

.password-field {
    position: relative;
}

.password-field .form-control {
    padding-right: 48px;
}

.password-toggle {
    position: absolute;
    top: 0;
    right: 0;
    width: 48px;
    height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #6b7280;
    cursor: pointer;
    font-size: 15px;
}

.password-toggle:hover,
.password-toggle:focus {
    color: #8548d0;
    outline: none;
}

.password-toggle:focus-visible {
    box-shadow: inset 0 0 0 2px #8548d0;
}

.btn-primary {
    background: linear-gradient(180deg, #29BFFF 0%, #8548D0 100%);
    color: white;
    border: none;
    padding: 12px;
    width: 100%;
    font-size: 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.3s ease;
}

.btn-primary:hover,
.btn-primary:focus {
    opacity: 0.95;
    color: #fff;
}

.btn-primary:disabled {
    opacity: 0.65;
    cursor: wait;
}

.below-actions {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 15px;
    gap: 10px;
    flex-wrap: wrap;
}

.inline-link {
    color: #8548D0;
    text-decoration: none;
    font-weight: 500;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
}

.inline-link:hover {
    color: #5e2fa0;
    text-decoration: underline;
}

.inline-btn {
    background: none;
    border: none;
    color: limegreen;
    font-weight: 500;
    cursor: pointer;
    padding: 0;
    font-size: 14px;
}

.divider {
    color: #999;
}

.alert {
    background: #ffe0e0;
    color: #a70000;
    border-left: 5px solid #f44336;
    padding: 10px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 14px;
}

/* Forgot-password modal */
.auth-modal {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.3);
}

.auth-modal .modal-header {
    border-bottom: 0;
    padding: 22px 24px 4px;
}

.auth-modal .modal-title {
    font-size: 21px;
    font-weight: 700;
    color: #1e3c72;
}

.auth-modal .modal-body {
    padding: 10px 24px 26px;
}

.auth-modal .close {
    outline: none;
}

.auth-label {
    display: block;
    margin-bottom: 6px;
    color: #333;
    font-size: 13px;
    font-weight: 600;
}

.auth-help {
    margin-bottom: 16px;
    color: #666;
    font-size: 13px;
    line-height: 1.5;
}

.auth-alert {
    display: none;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 7px;
    font-size: 13px;
    line-height: 1.45;
}

.auth-alert.error {
    display: block;
    color: #a70000;
    background: #ffe0e0;
    border-left: 4px solid #f44336;
}

.auth-alert.success {
    display: block;
    color: #146c2e;
    background: #e6f7ec;
    border-left: 4px solid #28a745;
}

.otp-input {
    height: 54px;
    font-size: 24px;
    font-weight: 600;
    letter-spacing: 10px;
    text-align: center;
}

.auth-links {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
    flex-wrap: wrap;
}

.auth-link-button {
    padding: 0;
    border: none;
    background: none;
    color: #8548D0;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
}

.auth-link-button:hover {
    color: #5e2fa0;
    text-decoration: underline;
}

.auth-link-button:disabled {
    color: #aaa;
    cursor: not-allowed;
    text-decoration: none;
}

.auth-success-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    margin: 6px auto 16px;
    border-radius: 50%;
    background: #e6f7ec;
    color: #146c2e;
    font-size: 30px;
    font-weight: 700;
}

@media (max-width: 480px) {
    body {
        align-items: flex-start;
        padding-top: 30px;
    }

    .login-wrapper {
        min-height: auto;
        max-width: 100%;
        padding: 30px 20px;
    }

    .auth-modal .modal-body {
        padding: 10px 20px 24px;
    }
}
</style>

<body>
    <div class="login-wrapper">
        <div class="logo">
            <img src="<?php echo asset_url('img/PCS-Logo.png'); ?>" alt="PCS Logo">
        </div>
        <h4><b>Login Here</b></h4>
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($loginError !== '') { ?>
            <div class="alert"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>
            <input name="email" id="loginEmail" type="email" class="form-control" placeholder="Email address"
                autocomplete="username" value="<?php echo htmlspecialchars($loginEmail, ENT_QUOTES, 'UTF-8'); ?>" required>
            <div class="password-field">
                <input type="password" class="form-control" id="loginPassword" name="pwd" placeholder="Password"
                    autocomplete="current-password" required>
                <button type="button" class="password-toggle" data-target="loginPassword" aria-label="Show password"
                    aria-pressed="false" title="Show password">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                </button>
            </div>
            <button type="submit" name="login" class="btn-primary">Login</button>
            <div class="below-actions">
                <button type="button" class="inline-link" data-toggle="modal" data-target="#forgotModal">
                    Forgotten Password?
                </button>
            </div>
        </form>
    </div>

    <div class="modal fade" id="forgotModal" tabindex="-1" role="dialog" aria-labelledby="forgotModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content auth-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotModalLabel">Forgot Password</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="authAlert" class="auth-alert" role="alert" aria-live="polite"></div>

                    <section id="stepEmail">
                        <p class="auth-help">Enter the email registered with your account. We will send a 6-digit OTP
                            to that email.</p>
                        <form id="forgotEmailForm" novalidate>
                            <label class="auth-label" for="forgotEmail">Email address</label>
                            <input type="email" id="forgotEmail" class="form-control" placeholder="you@example.com"
                                autocomplete="email"
                                value="<?php echo htmlspecialchars($loginEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $loginEmail !== '' ? 'readonly' : ''; ?> required>
                            <button type="button" id="editForgotEmailBtn" class="auth-link-button email-edit-link"
                                style="display:<?php echo $loginEmail !== '' ? 'inline-block' : 'none'; ?>;">Change
                                email</button>
                            <button type="submit" id="sendOtpBtn" class="btn-primary">Send OTP</button>
                        </form>
                    </section>

                    <section id="stepOtp" style="display:none;">
                        <p class="auth-help">Enter the OTP sent to <strong id="otpEmailMask"></strong>. It expires in
                            10 minutes.</p>
                        <form id="otpForm" novalidate>
                            <label class="auth-label" for="otpCode">6-digit OTP</label>
                            <input type="text" id="otpCode" class="form-control otp-input" inputmode="numeric"
                                autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required>
                            <button type="submit" id="verifyOtpBtn" class="btn-primary">Verify OTP</button>
                            <div class="auth-links">
                                <button type="button" id="resendOtpBtn" class="auth-link-button" disabled>Resend OTP</button>
                                <button type="button" id="changeEmailBtn" class="auth-link-button">Change email</button>
                            </div>
                        </form>
                    </section>

                    <section id="stepReset" style="display:none;">
                        <p class="auth-help">Create a new password. Use at least 8 characters, including a letter and
                            a number.</p>
                        <form id="resetPasswordForm" novalidate>
                            <label class="auth-label" for="newPassword">New password</label>
                            <div class="password-field">
                                <input type="password" id="newPassword" class="form-control"
                                    autocomplete="new-password" minlength="8" required>
                                <button type="button" class="password-toggle" data-target="newPassword"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <label class="auth-label" for="confirmPassword">Confirm new password</label>
                            <div class="password-field">
                                <input type="password" id="confirmPassword" class="form-control"
                                    autocomplete="new-password" minlength="8" required>
                                <button type="button" class="password-toggle" data-target="confirmPassword"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <button type="submit" id="resetPasswordBtn" class="btn-primary">Update Password</button>
                        </form>
                    </section>

                    <section id="stepDone" style="display:none; text-align:center;">
                        <div class="auth-success-icon">&#10003;</div>
                        <p id="doneMessage" class="auth-help"></p>
                        <button type="button" class="btn-primary" data-dismiss="modal">Back to Login</button>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var csrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var authEndpoint = 'auth.php';
        var currentEmail = '';
        var cooldownUntil = 0;
        var cooldownTimer = null;

        function byId(id) {
            return document.getElementById(id);
        }

        function bindPasswordToggles() {
            var toggles = document.querySelectorAll('.password-toggle');

            Array.prototype.forEach.call(toggles, function (button) {
                button.addEventListener('click', function () {
                    var input = byId(button.getAttribute('data-target'));
                    if (!input) {
                        return;
                    }

                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    button.setAttribute('aria-pressed', show ? 'true' : 'false');
                    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    button.setAttribute('title', show ? 'Hide password' : 'Show password');

                    var icon = button.querySelector('i');
                    if (icon) {
                        icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                    }
                });
            });
        }

        bindPasswordToggles();

        function setAlert(message, type) {
            var box = byId('authAlert');
            if (!message) {
                box.textContent = '';
                box.className = 'auth-alert';
                box.style.display = 'none';
                return;
            }
            box.textContent = message;
            box.className = 'auth-alert ' + (type || 'error');
            box.style.display = 'block';
        }

        function showStep(stepId) {
            ['stepEmail', 'stepOtp', 'stepReset', 'stepDone'].forEach(function (id) {
                byId(id).style.display = id === stepId ? 'block' : 'none';
            });

            var titles = {
                stepEmail: 'Forgot Password',
                stepOtp: 'Enter OTP',
                stepReset: 'Set New Password',
                stepDone: 'Password Updated'
            };
            byId('forgotModalLabel').textContent = titles[stepId] || 'Forgot Password';
        }

        function setLoading(button, loading, idleLabel) {
            if (!button) {
                return;
            }
            if (loading) {
                if (!button.dataset.idleLabel) {
                    button.dataset.idleLabel = idleLabel || button.textContent;
                }
                button.disabled = true;
                button.textContent = 'Please wait...';
            } else {
                button.disabled = false;
                button.textContent = idleLabel || button.dataset.idleLabel || button.textContent;
                delete button.dataset.idleLabel;
            }
        }

        function maskEmail(email) {
            var parts = email.split('@');
            if (parts.length !== 2) {
                return email;
            }
            var name = parts[0];
            var visible = name.length <= 2 ? name.slice(0, 1) : name.slice(0, 2);
            return visible + '***@' + parts[1];
        }

        function startCooldown(seconds) {
            var button = byId('resendOtpBtn');
            cooldownUntil = Date.now() + seconds * 1000;
            if (cooldownTimer) {
                clearInterval(cooldownTimer);
            }

            function tick() {
                var left = Math.ceil((cooldownUntil - Date.now()) / 1000);
                if (left <= 0) {
                    button.disabled = false;
                    button.textContent = 'Resend OTP';
                    clearInterval(cooldownTimer);
                    cooldownTimer = null;
                    return;
                }
                button.disabled = true;
                button.textContent = 'Resend OTP (' + left + 's)';
            }

            tick();
            cooldownTimer = setInterval(tick, 500);
        }

        function stopCooldown() {
            if (cooldownTimer) {
                clearInterval(cooldownTimer);
                cooldownTimer = null;
            }
            cooldownUntil = 0;
            var button = byId('resendOtpBtn');
            button.disabled = true;
            button.textContent = 'Resend OTP';
        }

        function postAuth(action, fields) {
            var body = new FormData();
            body.append('action', action);
            body.append('csrf_token', csrfToken);
            Object.keys(fields || {}).forEach(function (key) {
                body.append(key, fields[key]);
            });

            return fetch(authEndpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (error) {
                        throw new Error('Unexpected server response. Please refresh the page and try again.');
                    }
                });
            });
        }

        function looksLikeEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }

        byId('forgotEmailForm').addEventListener('submit', function (event) {
            event.preventDefault();
            var email = byId('forgotEmail').value.trim();

            if (!looksLikeEmail(email)) {
                setAlert('Please enter a valid email address.', 'error');
                return;
            }

            setAlert('');
            var button = byId('sendOtpBtn');
            setLoading(button, true, 'Send OTP');

            postAuth('request_otp', { email: email }).then(function (data) {
                if (data.status === 1) {
                    currentEmail = email;
                    byId('otpEmailMask').textContent = maskEmail(email);
                    byId('otpCode').value = '';
                    showStep('stepOtp');
                    setAlert(data.message, 'success');
                    startCooldown(60);
                    byId('otpCode').focus();
                    return;
                }

                setAlert(data.message || 'Unable to send OTP.', 'error');
                if (data.code === 'rate_limit' && data.retry_after) {
                    startCooldown(Math.max(1, parseInt(data.retry_after, 10)));
                }
            }).catch(function (error) {
                setAlert(error.message, 'error');
            }).then(function () {
                setLoading(button, false, 'Send OTP');
            });
        });

        byId('otpCode').addEventListener('input', function (event) {
            event.target.value = event.target.value.replace(/\D+/g, '').slice(0, 6);
        });

        byId('otpForm').addEventListener('submit', function (event) {
            event.preventDefault();
            var otp = byId('otpCode').value.trim();

            if (!/^\d{6}$/.test(otp)) {
                setAlert('Please enter the 6-digit OTP.', 'error');
                return;
            }

            setAlert('');
            var button = byId('verifyOtpBtn');
            setLoading(button, true, 'Verify OTP');

            postAuth('verify_otp', { otp: otp }).then(function (data) {
                if (data.status === 1) {
                    showStep('stepReset');
                    setAlert(data.message, 'success');
                    byId('newPassword').value = '';
                    byId('confirmPassword').value = '';
                    byId('newPassword').focus();
                    return;
                }

                setAlert(data.message || 'Invalid OTP.', 'error');
                if (data.code === 'restart') {
                    stopCooldown();
                    showStep('stepEmail');
                    byId('forgotEmail').value = currentEmail;
                }
            }).catch(function (error) {
                setAlert(error.message, 'error');
            }).then(function () {
                setLoading(button, false, 'Verify OTP');
            });
        });

        byId('resetPasswordForm').addEventListener('submit', function (event) {
            event.preventDefault();
            var password = byId('newPassword').value;
            var confirmation = byId('confirmPassword').value;

            if (password.length < 8) {
                setAlert('Password must be at least 8 characters long.', 'error');
                return;
            }
            if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) {
                setAlert('Password must contain at least one letter and one number.', 'error');
                return;
            }
            if (password !== confirmation) {
                setAlert('New password and confirmation do not match.', 'error');
                return;
            }

            setAlert('');
            var button = byId('resetPasswordBtn');
            setLoading(button, true, 'Update Password');

            postAuth('reset_password', {
                password: password,
                confirm_password: confirmation
            }).then(function (data) {
                if (data.status === 1) {
                    showStep('stepDone');
                    byId('doneMessage').textContent = data.message;
                    setAlert('');
                    byId('loginEmail').value = currentEmail;
                    byId('loginPassword').value = '';
                    byId('loginPassword').focus();
                    return;
                }

                setAlert(data.message || 'Unable to update password.', 'error');
                if (data.code === 'restart') {
                    stopCooldown();
                    showStep('stepEmail');
                    byId('forgotEmail').value = currentEmail;
                    byId('forgotEmail').focus();
                }
            }).catch(function (error) {
                setAlert(error.message, 'error');
            }).then(function () {
                setLoading(button, false, 'Update Password');
            });
        });

        byId('resendOtpBtn').addEventListener('click', function () {
            if (!currentEmail || Date.now() < cooldownUntil) {
                return;
            }

            setAlert('');
            byId('resendOtpBtn').disabled = true;
            byId('resendOtpBtn').textContent = 'Sending...';

            postAuth('request_otp', { email: currentEmail }).then(function (data) {
                if (data.status === 1) {
                    byId('otpCode').value = '';
                    setAlert(data.message, 'success');
                    startCooldown(60);
                    byId('otpCode').focus();
                    return;
                }

                setAlert(data.message || 'Unable to resend OTP.', 'error');
                if (data.code === 'rate_limit' && data.retry_after) {
                    startCooldown(Math.max(1, parseInt(data.retry_after, 10)));
                } else {
                    startCooldown(5);
                }
            }).catch(function (error) {
                setAlert(error.message, 'error');
                startCooldown(5);
            });
        });

        byId('changeEmailBtn').addEventListener('click', function () {
            setAlert('');
            stopCooldown();
            showStep('stepEmail');
            setEmailLocked(false);
            byId('forgotEmail').value = currentEmail;
            byId('forgotEmail').focus();
            byId('forgotEmail').select();
        });

        function setEmailLocked(locked) {
            byId('forgotEmail').readOnly = !!locked;
            byId('editForgotEmailBtn').style.display = locked ? 'inline-block' : 'none';
        }

        function syncForgotEmailWithLogin() {
            var loginEmail = byId('loginEmail').value.trim();

            if (loginEmail === '') {
                setEmailLocked(false);
                return;
            }

            // Keep the reset email exactly the same as the login email.
            byId('forgotEmail').value = loginEmail;
            setEmailLocked(true);
        }

        byId('editForgotEmailBtn').addEventListener('click', function () {
            setEmailLocked(false);
            byId('forgotEmail').focus();
            byId('forgotEmail').select();
        });

        $(function () {
            $('#forgotModal').on('show.bs.modal', function () {
                syncForgotEmailWithLogin();
            });

            $('#forgotModal').on('shown.bs.modal', function () {
                var emailStep = byId('stepEmail');
                if (emailStep.style.display !== 'none') {
                    byId('forgotEmail').focus();
                }
            });

            $('#forgotModal').on('hidden.bs.modal', function () {
                setAlert('');
                stopCooldown();
                showStep('stepEmail');
                byId('otpCode').value = '';
                byId('newPassword').value = '';
                byId('confirmPassword').value = '';
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            });

            <?php if ($showForgotModal) { ?>
            $('#forgotModal').modal('show');
            <?php } ?>
        });
    })();
    </script>

<?php include __DIR__ . '/footer.php'; ?>
