<?php
/**
 * NexAlert - SMS Consent Service
 * Creates consent records and queues the Twilio opt-in flow.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;

class SmsConsentService
{
    /**
     * Ensure a user_sms_consent row exists for an SMS contact.
     */
    public static function ensureConsentRecord(
        Database $db,
        int $userId,
        int $contactId,
        string $phoneE164,
        ?int $initiatedBy
    ): void {
        $existing = $db->fetchOne(
            'SELECT id FROM user_sms_consent WHERE contact_id = ?',
            [$contactId]
        );
        if ($existing) {
            return;
        }

        $db->execute(
            'INSERT INTO user_sms_consent (user_id, contact_id, phone_e164, status, initiated_by)
             VALUES (?, ?, ?, \'pending\', ?)',
            [$userId, $contactId, $phoneE164, $initiatedBy ?? $userId]
        );
    }

    /**
     * Send pre-notification email (if available) and queue Twilio opt-in SMS.
     */
    public static function initiateOptIn(
        Database $db,
        int $userId,
        int $contactId,
        ?int $initiatedBy = null
    ): void {
        $contact = $db->fetchOne(
            'SELECT c.id, c.contact_value, c.channel
             FROM user_contacts c
             WHERE c.id = ? AND c.user_id = ? AND c.channel = \'sms\' AND c.is_active = 1',
            [$contactId, $userId]
        );
        if (!$contact) {
            return;
        }

        $phone = $contact['contact_value'];
        self::ensureConsentRecord($db, $userId, $contactId, $phone, $initiatedBy);

        $consent = $db->fetchOne(
            'SELECT id, status FROM user_sms_consent WHERE contact_id = ?',
            [$contactId]
        );
        if (!$consent || $consent['status'] === 'confirmed') {
            return;
        }
        if ($consent['status'] === 'stopped') {
            return;
        }

        $email = $db->fetchValue(
            'SELECT contact_value FROM user_contacts
             WHERE user_id = ? AND channel = \'email\' AND is_primary = 1 AND is_verified = 1 AND is_active = 1
             LIMIT 1',
            [$userId]
        );

        if ($email) {
            MailService::sendSmsOptInNotice($email, $phone);
            $db->execute(
                'UPDATE user_sms_consent
                 SET status = \'invite_sent\', invite_sent_at = NOW(), invite_count = invite_count + 1
                 WHERE contact_id = ? AND status NOT IN (\'confirmed\', \'stopped\')',
                [$contactId]
            );
        }

        JobQueueService::push('sms_optin', ['contact_id' => $contactId, 'user_id' => $userId]);

        AuditService::log('sms.opt_in_initiated', 'user_sms_consent', (string) $consent['id'], [
            'contact_id' => $contactId,
            'user_id'    => $userId,
        ], $initiatedBy ?? $userId);
    }
}
