<?php
require __DIR__ . '/mail_config.php';
require __DIR__ . '/vendor/autoload.php';

$mail = new PHPMailer\PHPMailer\PHPMailer(true);

$mail->SMTPDebug = 2;
$mail->Debugoutput = 'html';

$mail->isSMTP();
$mail->Host = SMTP_HOST;
$mail->SMTPAuth = true;
$mail->Username = SMTP_USER;
$mail->Password = SMTP_PASSWORD;
$mail->SMTPSecure = SMTP_SECURE;
$mail->Port = SMTP_PORT;

try {
    $mail->smtpConnect();
    $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
    $mail->addAddress(SMTP_USER);
    $mail->Subject = 'PCS Invoice SMTP Test';
    $mail->Body = 'SMTP test email sent successfully.';

    if (!$mail->send()) {
        throw new RuntimeException($mail->ErrorInfo);
    }

    echo '<h2>SMTP send succeeded</h2>';
    $mail->smtpClose();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>SMTP test failed</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}