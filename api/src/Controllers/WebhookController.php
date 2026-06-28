<?php
/**
 * NexAlert - Webhook Controller
 * POST /api/v1/webhooks/twilio/sms — inbound SMS (STOP/YES/NO, Phase 4 chat)
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Logger;
use NexAlert\Services\AuditService;
use NexAlert\Services\ChatService;

class WebhookController
{
    public static function twilioSms(Request $request): never
    {
        $from = trim((string) $request->input('From', ''));
        $body = strtoupper(trim((string) $request->input('Body', '')));

        if ($from === '') {
            self::twimlResponse('Invalid request.');
        }

        $db = Database::getInstance();
        $phone = self::normalizeE164($from);

        $consent = $db->fetchOne(
            'SELECT sc.id, sc.user_id, sc.contact_id, sc.status
             FROM user_sms_consent sc
             WHERE sc.phone_e164 = ?',
            [$phone]
        );

        if (!$consent) {
            Logger::info('Twilio SMS from unknown number', ['from' => $phone, 'body' => $body]);
            self::twimlResponse('This number is not registered with NexAlert.');
        }

        if (in_array($body, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            $db->execute(
                'UPDATE user_sms_consent SET status = \'stopped\', stopped_at = NOW() WHERE id = ?',
                [(int) $consent['id']]
            );
            AuditService::log('sms.stopped', 'user_sms_consent', (string) $consent['id'], [
                'phone' => $phone,
            ], (int) $consent['user_id']);

            self::twimlResponse('You have been unsubscribed from NexAlert SMS. Reply START to re-enroll.');
        }

        if (in_array($body, ['YES', 'Y', 'START'], true)) {
            if ($consent['status'] === 'stopped') {
                self::twimlResponse('Contact your administrator to re-enroll after STOP.');
            }

            $db->execute(
                'UPDATE user_sms_consent SET status = \'confirmed\', confirmed_at = NOW() WHERE id = ?',
                [(int) $consent['id']]
            );
            AuditService::log('sms.confirmed', 'user_sms_consent', (string) $consent['id'], [
                'phone' => $phone,
            ], (int) $consent['user_id']);

            self::twimlResponse('You are now subscribed to NexAlert SMS alerts. Reply STOP to unsubscribe.');
        }

        if (in_array($body, ['NO', 'N'], true)) {
            $db->execute(
                'UPDATE user_sms_consent SET status = \'denied\', denied_at = NOW() WHERE id = ?',
                [(int) $consent['id']]
            );
            self::twimlResponse('SMS alerts declined. You will not receive SMS from NexAlert.');
        }

        $messageSid = trim((string) $request->input('MessageSid', ''));
        $originalBody = trim((string) $request->input('Body', ''));
        if (ChatService::handleInboundSms(
            $db,
            (int) $consent['user_id'],
            $originalBody,
            $messageSid !== '' ? $messageSid : null
        )) {
            self::twimlResponse('');
        }

        Logger::info('Twilio inbound SMS (unhandled)', ['from' => $phone, 'body' => $body]);
        self::twimlResponse('');
    }

    private static function normalizeE164(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }

        return str_starts_with($phone, '+') ? $phone : '+' . $digits;
    }

    private static function twimlResponse(string $message): never
    {
        header('Content-Type: text/xml; charset=utf-8');
        $escaped = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        if ($message !== '') {
            echo '<Message>' . $escaped . '</Message>';
        }
        echo '</Response>';
        exit;
    }
}
