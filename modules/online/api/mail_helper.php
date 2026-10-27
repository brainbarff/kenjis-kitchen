<?php
/**
 * Kenji's Kitchen - Email Helper Module (Powered by PHPMailer)
 *
 * Sends transactional emails (OTPs, order receipts) using PHPMailer via SMTP
 * (Gmail SSL/TLS) configured in .env, with automated fallback if needed.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__, 3) . '/includes/phpmailer/Exception.php';
require_once dirname(__DIR__, 3) . '/includes/phpmailer/PHPMailer.php';
require_once dirname(__DIR__, 3) . '/includes/phpmailer/SMTP.php';

if (!function_exists('kk_load_mail_env')) {
    function kk_load_mail_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $envFile = dirname(__DIR__, 3) . '/.env';
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key !== '' && getenv($key) === false) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
        $loaded = true;
    }
}

/**
 * Send customer mail (OTP, order receipts) using PHPMailer via SMTP.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $text Plain text body
 * @param string $html Optional HTML body
 * @return array{ok: bool, message?: string}
 */
function send_app_email(string $to, string $subject, string $text, string $html = ''): array
{
    kk_load_mail_env();

    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid email address.'];
    }

    $emailService = strtolower(getenv('EMAIL_SERVICE') ?: ($_ENV['EMAIL_SERVICE'] ?? 'gmail'));
    $isTestEnv    = filter_var(getenv('TEST_ENVIRONMENT') ?: ($_ENV['TEST_ENVIRONMENT'] ?? false), FILTER_VALIDATE_BOOLEAN);
    $mailDisabled = filter_var(getenv('MAIL_DISABLE') ?: ($_ENV['MAIL_DISABLE'] ?? false), FILTER_VALIDATE_BOOLEAN)
        || $emailService === 'disabled';

    // Delivery mode configuration:
    // 'smtp' -> attempt actual outbound delivery via PHPMailer SMTP.
    // 'sink' (default if test environment or omitted) -> capture in local test mail sink.
    $deliveryMode = strtolower(trim((string)(getenv('MAIL_DELIVERY_MODE') ?: ($_ENV['MAIL_DELIVERY_MODE'] ?? ''))));

    // RFC 2606 / 6761 reserved test domains and automated test flags always safely use the test sink.
    $isTestDomain = (bool)preg_match('/(@example\.(?:com|org|net)|@test\.local|\.(?:test|example|invalid|localhost))$/i', $to);
    $hasSinkHeader = $isTestEnv && !empty($_SERVER['HTTP_X_TEST_MAIL_SINK']);
    $isAutomatedTest = $isTestDomain || $hasSinkHeader;

    $useSink = false;

    if ($mailDisabled) {
        if ($isTestEnv) {
            $useSink = true;
        } else {
            return ['ok' => false, 'message' => 'Email delivery is disabled by configuration.'];
        }
    } elseif ($isAutomatedTest) {
        // Prevent automated test fixtures or dummy addresses from generating outbound SMTP requests.
        $useSink = true;
    } elseif ($deliveryMode === 'smtp') {
        // Real SMTP explicitly enabled for manual browser testing
        $useSink = false;
    } elseif ($deliveryMode === 'sink' || $isTestEnv) {
        // Default safe sink in test environments
        $useSink = true;
    }

    if ($useSink) {
        $sinkFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kk_test_mail_sink.json';
        @file_put_contents($sinkFile, json_encode([
            'to' => $to,
            'subject' => $subject,
            'text' => $text,
            'time' => time()
        ], JSON_UNESCAPED_SLASHES));
        return ['ok' => true, 'message' => 'Email captured in test mail sink.'];
    }

    $emailUser = getenv('EMAIL_USER') ?: ($_ENV['EMAIL_USER'] ?? '');
    $emailPass = getenv('EMAIL_PASS') ?: ($_ENV['EMAIL_PASS'] ?? '');
    $emailFrom = getenv('EMAIL_FROM') ?: ($_ENV['EMAIL_FROM'] ?? '');
    $smtpHost = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? '');
    $smtpPort = (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 0));
    $smtpSecure = strtolower(getenv('SMTP_SECURE') ?: ($_ENV['SMTP_SECURE'] ?? ''));

    if ($smtpHost === '') {
        if ($emailService === 'gmail') {
            $smtpHost = 'smtp.gmail.com';
        }
    }
    if ($smtpPort <= 0) {
        $smtpPort = ($smtpSecure === 'tls' || $smtpSecure === 'starttls') ? 587 : 465;
    }
    if ($smtpSecure === '') {
        $smtpSecure = ($smtpPort === 587) ? 'tls' : 'ssl';
    }

    $cleanPass = preg_replace('/\s+/', '', $emailPass);

    // Extract sender name and email
    $fromAddress = $emailUser;
    $fromName = "Kenji's Kitchen";
    if (preg_match('/^(.*?)\s*<([^>]+)>$/', $emailFrom, $matches)) {
        $fromName = trim($matches[1], " \"'");
        $fromAddress = trim($matches[2]);
    } elseif ($emailFrom !== '') {
        $fromAddress = $emailFrom;
    }

    // 1. Send via PHPMailer
    if (class_exists(PHPMailer::class) && $smtpHost !== '' && $emailUser !== '' && $cleanPass !== '') {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $emailUser;
            $mail->Password   = $cleanPass;
            $mail->Port       = $smtpPort;
            $mail->Timeout    = 12;
            $mail->CharSet    = 'UTF-8';

            if ($smtpSecure === 'tls' || $smtpSecure === 'starttls' || $smtpPort === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            // Accommodate local dev environments without local CA bundle issues
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;

            if ($html !== '') {
                $mail->isHTML(true);
                $mail->Body    = $html;
                $mail->AltBody = $text !== '' ? $text : strip_tags($html);
            } else {
                $mail->isHTML(false);
                $mail->Body = $text;
            }

            $mail->send();
            return ['ok' => true];
        } catch (Exception $e) {
            $errorInfo = $mail->ErrorInfo ?: $e->getMessage();
            error_log("[PHPMailer Error] Could not send to {$to}: {$errorInfo}");

            // Distinguish authentication failure vs connection failure
            if (stripos($errorInfo, 'authenticate') !== false || stripos($errorInfo, 'password') !== false) {
                return [
                    'ok' => false,
                    'message' => 'The email provider rejected SMTP authentication. Check EMAIL_USER and EMAIL_PASS in .env.'
                ];
            }

            return [
                'ok' => false,
                'message' => 'Unable to send verification email: ' . $errorInfo
            ];
        }
    }

    return [
        'ok' => false,
        'message' => 'Email configuration is missing or incomplete in .env.'
    ];
}
