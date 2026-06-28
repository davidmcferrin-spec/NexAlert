<?php
/**
 * NexAlert - Mail Service
 * PHPMailer wrapper for transactional email.
 * Phase 1: password reset and contact verification only.
 * Phase 2+: full alert delivery.
 *
 * Requires PHPMailer. Install via:
 *   cd /home/dh_w9tij7/nexalert.area51consulting.com
 *   php vendor/phpmailer/phpmailer/autoload.php (after composer install)
 *
 * Manual install (no Composer):
 *   Download PHPMailer src/ to api/lib/PHPMailer/
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Env;
use NexAlert\Config\Logger;

class MailService
{
    /**
     * Send a password reset email.
     */
    public static function sendPasswordReset(string $toEmail, string $resetUrl): void
    {
        $subject = '[NexAlert] Password Reset Request';
        $body    = self::renderTemplate('password_reset', [
            'reset_url'   => $resetUrl,
            'expires_in'  => '2 hours',
            'app_name'    => Env::get('APP_NAME', 'NexAlert'),
            'app_url'     => Env::get('APP_URL'),
        ]);

        self::send($toEmail, $subject, $body);
    }

    /**
     * Send an email address verification message.
     */
    public static function sendEmailVerification(string $toEmail, string $verifyUrl): void
    {
        $subject = '[NexAlert] Verify Your Email Address';
        $body    = self::renderTemplate('email_verify', [
            'verify_url' => $verifyUrl,
            'app_name'   => Env::get('APP_NAME', 'NexAlert'),
            'app_url'    => Env::get('APP_URL'),
        ]);

        self::send($toEmail, $subject, $body);
    }

    /**
     * Send SMS opt-in pre-notification email.
     * Warns the user that a Twilio opt-in SMS is coming.
     */
    public static function sendSmsOptInNotice(string $toEmail, string $phoneDisplay): void
    {
        $subject = '[NexAlert] Action Required: SMS Alert Enrollment';
        $body    = self::renderTemplate('sms_optin_notice', [
            'phone'    => $phoneDisplay,
            'app_name' => Env::get('APP_NAME', 'NexAlert'),
            'app_url'  => Env::get('APP_URL'),
        ]);

        self::send($toEmail, $subject, $body);
    }

    /**
     * Send an alert notification email to a recipient.
     *
     * @param array<string, mixed> $alert
     */
    public static function sendAlert(string $toEmail, array $alert, ?string $ackUrl = null): void
    {
        $severity = strtoupper((string) ($alert['severity'] ?? 'INFO'));
        $subject  = "[NexAlert {$severity}] " . ($alert['subject'] ?? 'Alert');

        $body = self::renderTemplate('alert_notification', [
            'alert'      => $alert,
            'ack_url'    => $ackUrl,
            'app_name'   => Env::get('APP_NAME', 'NexAlert'),
            'app_url'    => Env::get('APP_URL'),
        ]);

        self::send($toEmail, $subject, $body);
    }

    /**
     * Send SMS opt-in message body (used by dispatch worker via Twilio directly).
     */
    public static function smsOptInMessage(): string
    {
        $app = Env::get('APP_NAME', 'NexAlert');

        return "{$app}: Reply YES to receive emergency SMS alerts. Msg&data rates may apply. Reply STOP to cancel.";
    }

    /**
     * Core send method using PHPMailer.
     */
    public static function sendRaw(string $to, string $subject, string $htmlBody): void
    {
        self::send($to, $subject, $htmlBody);
    }

    /**
     * Core send method using PHPMailer.
     */
    private static function send(string $to, string $subject, string $htmlBody): void
    {
        // Locate PHPMailer - support both Composer and manual install
        $composerAutoload = __DIR__ . '/../../vendor/autoload.php';
        $manualSrc        = __DIR__ . '/../lib/PHPMailer/PHPMailer.php';

        if (file_exists($composerAutoload)) {
            require_once $composerAutoload;
        } elseif (file_exists($manualSrc)) {
            require_once $manualSrc;
            require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
            require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
        } else {
            Logger::error('PHPMailer not found. Install via Composer or place src in api/lib/PHPMailer/');
            throw new \RuntimeException('Mail library not available');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Env::require('MAIL_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = Env::require('MAIL_USERNAME');
            $mail->Password   = Env::require('MAIL_PASSWORD');
            $mail->SMTPSecure = Env::get('MAIL_ENCRYPTION', 'tls') === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = Env::int('MAIL_PORT', 587);
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(
                Env::require('MAIL_FROM_ADDRESS'),
                Env::get('MAIL_FROM_NAME', 'NexAlert')
            );
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody));

            $mail->send();

            Logger::info('Email sent', ['to' => $to, 'subject' => $subject]);
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            Logger::error('Email send failed', [
                'to'      => $to,
                'subject' => $subject,
                'error'   => $mail->ErrorInfo,
            ]);
            throw new \RuntimeException('Email send failed: ' . $mail->ErrorInfo, 0, $e);
        }
    }

    /**
     * Simple template renderer using PHP include.
     * Templates live in api/src/Templates/mail/
     */
    private static function renderTemplate(string $name, array $vars = []): string
    {
        $templatePath = __DIR__ . "/../Templates/mail/{$name}.php";

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Mail template not found: {$name}");
        }

        extract($vars, EXTR_SKIP);

        ob_start();
        include $templatePath;
        return ob_get_clean() ?: '';
    }
}
