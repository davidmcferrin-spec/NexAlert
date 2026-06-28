<?php
/**
 * NexAlert - Profile Controller (user self-service)
 *
 * GET  /api/v1/profile              → own profile + contacts + prefs
 * PUT  /api/v1/profile              → update display name, timezone
 * GET  /api/v1/profile/contacts     → list contacts
 * POST /api/v1/profile/contacts     → add contact
 * PUT  /api/v1/profile/contacts/{id} → update contact value
 * DELETE /api/v1/profile/contacts/{id} → remove contact
 * POST /api/v1/profile/contacts/{id}/verify → resend verification
 * POST /api/v1/profile/sms-optin    → request SMS opt-in for phone contact
 * GET  /api/v1/profile/notifications → notification prefs
 * PUT  /api/v1/profile/notifications → update prefs
 * GET  /api/v1/profile/alerts       → alerts sent to me
 * POST /api/v1/profile/change-password → change password while logged in
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Env;
use NexAlert\Services\AuditService;
use NexAlert\Services\MailService;
use NexAlert\Services\NotificationService;
use NexAlert\Services\PollService;
use NexAlert\Services\RowNormalizer;
use NexAlert\Services\SmsConsentService;
use NexAlert\Services\WebPushService;

class ProfileController
{
    public static function get(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];

        $user = $db->fetchOne(
            'SELECT u.id, u.username, u.display_name, u.first_name, u.last_name,
                    u.timezone, u.preferred_language, u.home_org_id,
                    o.display_name AS home_org_name
             FROM users u
             JOIN organizations o ON o.id = u.home_org_id
             WHERE u.id = ?',
            [$userId]
        );

        if (!$user) {
            Response::notFound('User not found');
        }

        $user['contacts'] = self::fetchContacts($db, $userId);
        $user['notification_prefs'] = self::fetchNotificationPrefs($db, $userId);

        Response::success($user);
    }

    public static function update(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];

        $displayName = trim((string) $request->input('display_name', ''));
        $timezone    = trim((string) $request->input('timezone', ''));

        if ($displayName === '') {
            Response::validationError(['display_name' => 'Required']);
        }

        $db->execute(
            'UPDATE users SET display_name = ?, timezone = COALESCE(NULLIF(?, \'\'), timezone) WHERE id = ?',
            [$displayName, $timezone, $userId]
        );

        AuditService::log('profile.updated', 'user', (string) $userId, [], $userId);

        Response::success(null, 'Profile updated');
    }

    public static function listContacts(Request $request): never
    {
        $db = Database::getInstance();
        Response::success(['contacts' => self::fetchContacts($db, (int) $request->user['uid'])]);
    }

    public static function addContact(Request $request): never
    {
        $db      = Database::getInstance();
        $userId  = (int) $request->user['uid'];
        $channel = strtolower(trim((string) $request->input('channel', '')));
        $value   = trim((string) $request->input('contact_value', ''));
        $label   = trim((string) $request->input('label', '')) ?: null;

        if (!in_array($channel, ['email', 'sms'], true)) {
            Response::validationError(['channel' => 'Must be email or sms']);
        }

        if ($value === '') {
            Response::validationError(['contact_value' => 'Required']);
        }

        if ($channel === 'sms') {
            $value = UserController::normalizePhone($value);
        }

        $isPrimary = (bool) $request->input('is_primary', false);

        if ($isPrimary) {
            $db->execute(
                'UPDATE user_contacts SET is_primary = 0 WHERE user_id = ? AND channel = ?',
                [$userId, $channel]
            );
        }

        $db->execute(
            'INSERT INTO user_contacts (user_id, channel, contact_value, label, is_primary, is_verified)
             VALUES (?, ?, ?, ?, ?, 0)',
            [$userId, $channel, $value, $label, $isPrimary ? 1 : 0]
        );
        $contactId = $db->lastInsertId();

        if ($channel === 'email') {
            self::sendContactVerification($db, $userId, $contactId, $value);
        }

        if ($channel === 'sms') {
            SmsConsentService::ensureConsentRecord($db, $userId, $contactId, $value, $userId);
        }

        AuditService::log('profile.contact_added', 'user_contact', (string) $contactId, [
            'channel' => $channel,
        ], $userId);

        Response::success(['id' => $contactId], 'Contact added', 201);
    }

    /**
     * PUT /api/v1/profile/contacts/{id}
     * Body: { "contact_value": "..." }
     */
    public static function updateContact(Request $request): never
    {
        $db        = Database::getInstance();
        $userId    = (int) $request->user['uid'];
        $contactId = (int) $request->param('id');

        $contact = $db->fetchOne(
            'SELECT id, channel, contact_value FROM user_contacts
             WHERE id = ? AND user_id = ? AND is_active = 1',
            [$contactId, $userId]
        );
        if (!$contact) {
            Response::notFound('Contact not found');
        }

        $channel = (string) $contact['channel'];
        $value   = trim((string) $request->input('contact_value', ''));

        if ($value === '') {
            Response::validationError(['contact_value' => 'Required']);
        }

        if ($channel === 'sms') {
            $value = UserController::normalizePhone($value);
        } elseif (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            Response::validationError(['contact_value' => 'Invalid email address']);
        }

        if ($value === $contact['contact_value']) {
            Response::success(null, 'No changes');
        }

        if ($channel === 'email') {
            $db->execute(
                'UPDATE user_contacts SET contact_value = ?, is_verified = 0, verified_at = NULL WHERE id = ?',
                [$value, $contactId]
            );
            self::sendContactVerification($db, $userId, $contactId, $value);
        } else {
            $consent = $db->fetchOne(
                'SELECT id, status FROM user_sms_consent WHERE contact_id = ?',
                [$contactId]
            );
            if ($consent && $consent['status'] === 'stopped') {
                Response::error('This number opted out via STOP. Remove it and add a new phone number.', 409);
            }

            $db->execute(
                'UPDATE user_contacts SET contact_value = ? WHERE id = ?',
                [$value, $contactId]
            );

            if ($consent) {
                $db->execute(
                    "UPDATE user_sms_consent
                     SET phone_e164 = ?, status = 'pending', confirmed_at = NULL, opt_in_sent_at = NULL
                     WHERE contact_id = ?",
                    [$value, $contactId]
                );
            } else {
                SmsConsentService::ensureConsentRecord($db, $userId, $contactId, $value, $userId);
            }
        }

        AuditService::log('profile.contact_updated', 'user_contact', (string) $contactId, [
            'channel' => $channel,
        ], $userId);

        Response::success(['contacts' => self::fetchContacts($db, $userId)], 'Contact updated');
    }

    public static function deleteContact(Request $request): never
    {
        $db        = Database::getInstance();
        $userId    = (int) $request->user['uid'];
        $contactId = (int) $request->param('id');

        $contact = $db->fetchOne(
            'SELECT id FROM user_contacts WHERE id = ? AND user_id = ?',
            [$contactId, $userId]
        );
        if (!$contact) {
            Response::notFound('Contact not found');
        }

        $db->execute('UPDATE user_contacts SET is_active = 0 WHERE id = ?', [$contactId]);

        AuditService::log('profile.contact_removed', 'user_contact', (string) $contactId, [], $userId);

        Response::success(null, 'Contact removed');
    }

    public static function resendVerification(Request $request): never
    {
        $db        = Database::getInstance();
        $userId    = (int) $request->user['uid'];
        $contactId = (int) $request->param('id');

        $contact = $db->fetchOne(
            'SELECT id, channel, contact_value, is_verified FROM user_contacts
             WHERE id = ? AND user_id = ? AND is_active = 1',
            [$contactId, $userId]
        );

        if (!$contact) {
            Response::notFound('Contact not found');
        }

        if ((int) $contact['is_verified'] === 1) {
            Response::error('Contact already verified', 409);
        }

        if ($contact['channel'] !== 'email') {
            Response::error('Only email contacts can be verified this way', 409);
        }

        self::sendContactVerification($db, $userId, $contactId, $contact['contact_value']);

        Response::success(null, 'Verification email sent');
    }

    public static function requestSmsOptIn(Request $request): never
    {
        $db        = Database::getInstance();
        $userId    = (int) $request->user['uid'];
        $contactId = (int) $request->input('contact_id', 0);

        $contact = $db->fetchOne(
            'SELECT c.id, c.contact_value
             FROM user_contacts c
             WHERE c.id = ? AND c.user_id = ? AND c.channel = \'sms\' AND c.is_active = 1',
            [$contactId, $userId]
        );

        if (!$contact) {
            Response::notFound('SMS contact not found');
        }

        $consent = $db->fetchOne(
            'SELECT status FROM user_sms_consent WHERE contact_id = ?',
            [$contactId]
        );

        if ($consent && $consent['status'] === 'confirmed') {
            Response::error('SMS already confirmed for this number', 409);
        }

        if ($consent && $consent['status'] === 'stopped') {
            Response::error('Number opted out via STOP — contact admin to re-enroll', 409);
        }

        SmsConsentService::initiateOptIn($db, $userId, $contactId, $userId);

        Response::success(null, 'SMS opt-in request queued — check your phone for a YES reply prompt');
    }

    public static function getNotificationPrefs(Request $request): never
    {
        $db = Database::getInstance();
        Response::success([
            'prefs' => self::fetchNotificationPrefs($db, (int) $request->user['uid']),
        ]);
    }

    public static function updateNotificationPrefs(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];
        $prefs  = $request->input('prefs');

        if (!is_array($prefs)) {
            Response::validationError(['prefs' => 'Must be an array']);
        }

        $severities = ['test', 'info', 'notice', 'warning', 'critical', 'evacuation'];

        foreach ($prefs as $pref) {
            if (!is_array($pref) || empty($pref['severity'])) {
                continue;
            }
            $sev = (string) $pref['severity'];
            if (!in_array($sev, $severities, true)) {
                continue;
            }

            $db->execute(
                'INSERT INTO user_notification_prefs
                    (user_id, severity, channel_email, channel_sms, channel_push, channel_in_app)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    channel_email = VALUES(channel_email),
                    channel_sms = VALUES(channel_sms),
                    channel_push = VALUES(channel_push),
                    channel_in_app = VALUES(channel_in_app)',
                [
                    $userId,
                    $sev,
                    !empty($pref['channel_email']) ? 1 : 0,
                    !empty($pref['channel_sms']) ? 1 : 0,
                    !empty($pref['channel_push']) ? 1 : 0,
                    !empty($pref['channel_in_app']) ? 1 : 0,
                ]
            );
        }

        AuditService::log('profile.prefs_updated', 'user', (string) $userId, [], $userId);

        Response::success(['prefs' => self::fetchNotificationPrefs($db, $userId)], 'Preferences saved');
    }

    public static function myAlerts(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];
        $limit  = min((int) $request->query('limit', 50), 100);
        $offset = (int) $request->query('offset', 0);

        $total = (int) $db->fetchValue(
            'SELECT COUNT(DISTINCT a.id)
             FROM alerts a
             JOIN alert_deliveries ad ON ad.alert_id = a.id
             WHERE ad.user_id = ?',
            [$userId]
        );

        $alerts = $db->fetchAll(
            'SELECT DISTINCT a.id, a.subject, a.body, a.severity, a.alert_type, a.status,
                    a.ack_required, a.created_at, a.sent_at, a.expires_at, a.created_by_user,
                    a.poll_question, a.poll_options,
                    ct.is_open AS chat_is_open,
                    (SELECT COUNT(*) FROM alert_acks aa WHERE aa.alert_id = a.id AND aa.user_id = ?) AS i_acked,
                    (SELECT COUNT(*) FROM poll_responses pr WHERE pr.alert_id = a.id AND pr.user_id = ?) AS i_voted
             FROM alerts a
             JOIN alert_deliveries ad ON ad.alert_id = a.id
             LEFT JOIN chat_threads ct ON ct.alert_id = a.id
             WHERE ad.user_id = ?
             ORDER BY a.created_at DESC
             LIMIT ? OFFSET ?',
            [$userId, $userId, $userId, $limit, $offset]
        );

        foreach ($alerts as &$alert) {
            $alert['poll_options'] = PollService::parsePollOptions($alert);
            $alert['is_expired']   = PollService::isExpired($alert);
            $alert['is_originator'] = (int) ($alert['created_by_user'] ?? 0) === $userId;
            $alert['can_chat'] = in_array($alert['alert_type'], ['chat', 'group_chat'], true)
                && !$alert['is_expired']
                && in_array($alert['status'], ['sending', 'sent'], true)
                && (int) ($alert['chat_is_open'] ?? 1) === 1;
        }
        unset($alert);

        Response::success(['alerts' => $alerts, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public static function pushVapidKey(Request $request): never
    {
        Response::success([
            'public_key'  => WebPushService::getPublicKey(),
            'configured'  => WebPushService::isConfigured(),
        ]);
    }

    public static function listPushSubscriptions(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];

        Response::success([
            'subscriptions' => WebPushService::listSubscriptions($db, $userId),
            'configured'    => WebPushService::isConfigured(),
        ]);
    }

    public static function subscribePush(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];

        $sub = WebPushService::subscribe($db, $userId, [
            'endpoint'     => $request->input('endpoint'),
            'p256dh'       => $request->input('p256dh') ?? $request->input('keys.p256dh'),
            'auth'         => $request->input('auth') ?? $request->input('keys.auth'),
            'device_label' => $request->input('device_label'),
            'user_agent'   => $request->input('user_agent') ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);

        Response::success($sub, 'Push subscription saved');
    }

    public static function unsubscribePush(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];
        $subId  = (int) $request->param('id');

        WebPushService::unsubscribe($db, $userId, $subId);

        Response::success(null, 'Push subscription removed');
    }

    public static function updates(Request $request): never
    {
        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];
        $since  = trim((string) $request->query('since', ''));

        $data = NotificationService::getUpdates($db, $userId, $since);

        Response::success($data);
    }

    public static function changePassword(Request $request): never
    {
        $missing = $request->validate(['current_password', 'password', 'password_confirm']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $current = (string) $request->input('current_password');
        $password = (string) $request->input('password');
        $confirm  = (string) $request->input('password_confirm');

        if ($password !== $confirm) {
            Response::validationError(['password_confirm' => 'Passwords do not match']);
        }

        if (strlen($password) < 12) {
            Response::validationError(['password' => 'Password must be at least 12 characters']);
        }

        $db     = Database::getInstance();
        $userId = (int) $request->user['uid'];

        $hash = $db->fetchValue(
            'SELECT local_password_hash FROM users WHERE id = ? AND is_active = 1',
            [$userId]
        );

        if (!$hash || !password_verify($current, (string) $hash)) {
            Response::error('Current password is incorrect', 400);
        }

        $cost    = Env::int('BCRYPT_COST', 12);
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);

        $db->execute(
            'UPDATE users SET local_password_hash = ? WHERE id = ?',
            [$newHash, $userId]
        );

        AuditService::log('profile.password_changed', 'user', (string) $userId, [], $userId);

        Response::success(null, 'Password updated');
    }

    public static function verifyEmailToken(Request $request): never
    {
        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            Response::validationError(['token' => 'Required']);
        }

        $db   = Database::getInstance();
        $hash = hash('sha256', $token);

        $row = $db->fetchOne(
            'SELECT ut.id, ut.user_id, ut.payload, ut.expires_at, ut.used_at
             FROM user_tokens ut
             WHERE ut.token_hash = ? AND ut.token_type = \'contact_verify\'',
            [$hash]
        );

        if (!$row || ($row['used_at'] !== null) || strtotime($row['expires_at']) < time()) {
            Response::error('Invalid or expired verification link', 400);
        }

        $payload   = json_decode((string) ($row['payload'] ?? '{}'), true) ?: [];
        $contactId = (int) ($payload['contact_id'] ?? 0);

        $db->transaction(function (Database $db) use ($row, $contactId): void {
            $db->execute(
                'UPDATE user_contacts SET is_verified = 1, verified_at = NOW() WHERE id = ? AND user_id = ?',
                [$contactId, (int) $row['user_id']]
            );
            $db->execute('UPDATE user_tokens SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
        });

        Response::success(null, 'Email verified');
    }

    /** @return list<array<string, mixed>> */
    private static function fetchContacts(Database $db, int $userId): array
    {
        $contacts = $db->fetchAll(
            'SELECT c.id, c.channel, c.contact_value, c.label, c.is_primary, c.is_verified, c.verified_at,
                    sc.status AS sms_consent_status
             FROM user_contacts c
             LEFT JOIN user_sms_consent sc ON sc.contact_id = c.id
             WHERE c.user_id = ? AND c.is_active = 1
             ORDER BY c.channel, c.is_primary DESC',
            [$userId]
        );

        return RowNormalizer::mapFlags($contacts, ['is_primary', 'is_verified']);
    }

    /** @return list<array<string, mixed>> */
    private static function fetchNotificationPrefs(Database $db, int $userId): array
    {
        $prefs = $db->fetchAll(
            'SELECT severity, channel_email, channel_sms, channel_push, channel_in_app, system_override
             FROM user_notification_prefs WHERE user_id = ? ORDER BY FIELD(severity,
                \'test\',\'info\',\'notice\',\'warning\',\'critical\',\'evacuation\')',
            [$userId]
        );

        return RowNormalizer::mapFlags($prefs, [
            'channel_email', 'channel_sms', 'channel_push', 'channel_in_app', 'system_override',
        ]);
    }

    private static function sendContactVerification(Database $db, int $userId, int $contactId, string $email): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash     = hash('sha256', $rawToken);

        $db->execute(
            'INSERT INTO user_tokens (user_id, token_type, token_hash, payload, expires_at)
             VALUES (?, \'contact_verify\', ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR))',
            [
                $userId,
                $hash,
                json_encode(['contact_id' => $contactId, 'channel' => 'email'], JSON_THROW_ON_ERROR),
            ]
        );

        $verifyUrl = rtrim(Env::get('APP_URL'), '/') . '/profile/verify-email?token=' . urlencode($rawToken);
        MailService::sendEmailVerification($email, $verifyUrl);
    }
}
