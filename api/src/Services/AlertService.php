<?php
/**
 * NexAlert - Alert Service
 * Creates alerts, resolves targets, builds delivery records, enqueues dispatch.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;

class AlertService
{
    private const VALID_TYPES = ['simple', 'ack_required', 'poll', 'chat', 'group_chat'];
    private const VALID_SEVERITIES = ['test', 'info', 'notice', 'warning', 'critical', 'evacuation'];
    private const VALID_CHANNELS = ['email', 'sms', 'push_web', 'push_fcm', 'in_app'];
    private const FORCE_CHANNELS = ['critical', 'evacuation'];

    /**
     * @return array<string, mixed>
     */
    public static function create(Request $request): array
    {
        $db = Database::getInstance();

        $subject   = trim((string) $request->input('subject', ''));
        $body      = trim((string) $request->input('body', ''));
        $bodyHtml  = $request->input('body_html') !== null ? trim((string) $request->input('body_html')) : null;
        $alertType = strtolower(trim((string) $request->input('alert_type', 'simple')));
        $severity  = strtolower(trim((string) $request->input('severity', 'info')));
        $externalRef = $request->input('external_ref') !== null
            ? trim((string) $request->input('external_ref')) : null;

        if ($subject === '' || $body === '') {
            $errors = [];
            if ($subject === '') {
                $errors['subject'] = 'Required';
            }
            if ($body === '') {
                $errors['body'] = 'Required';
            }
            Response::validationError($errors);
        }

        if (!in_array($alertType, self::VALID_TYPES, true)) {
            Response::validationError(['alert_type' => 'Invalid alert type']);
        }

        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            Response::validationError(['severity' => 'Invalid severity']);
        }

        $channels = self::normalizeChannels($request->input('channels'));
        if ($channels === []) {
            Response::validationError(['channels' => 'At least one channel is required']);
        }

        [$orgId, $createdByUser, $createdByToken] = self::resolveOrigin($request, $severity, $alertType);

        $targetResult = self::resolveInputTargets($db, $request);
        if ($targetResult['errors'] !== []) {
            Response::validationError(['targets' => implode('; ', $targetResult['errors'])]);
        }

        $targetRows = $targetResult['targets'];
        if ($targetRows === []) {
            Response::validationError(['targets' => 'At least one target is required']);
        }

        $userIds = TagService::resolveTargets($db, $targetRows);
        if ($userIds === []) {
            Response::validationError(['targets' => 'No active recipients match the target expression']);
        }

        $ackRequired = $alertType === 'ack_required' || (bool) $request->input('ack_required', false);
        $ackDeadline = $request->input('ack_deadline_minutes') !== null
            ? (int) $request->input('ack_deadline_minutes') : null;
        $escalationUserId = $request->input('escalation_user_id') !== null
            ? (int) $request->input('escalation_user_id') : null;
        $pollQuestion = $request->input('poll_question') !== null
            ? trim((string) $request->input('poll_question')) : null;
        $pollOptions  = $request->input('poll_options');
        $ttlMinutes   = $request->input('ttl_minutes') !== null ? (int) $request->input('ttl_minutes') : null;

        if ($alertType === 'poll') {
            if ($pollQuestion === null || $pollQuestion === '') {
                Response::validationError(['poll_question' => 'Required for poll alerts']);
            }
            if (!is_array($pollOptions) || count($pollOptions) < 2) {
                Response::validationError(['poll_options' => 'At least two poll options required']);
            }
        }

        if ($alertType === 'ack_required') {
            $ackRequired = true;
        }

        $channelsStr = implode(',', $channels);
        $expiresAt   = $ttlMinutes !== null && $ttlMinutes > 0
            ? date('Y-m-d H:i:s', time() + $ttlMinutes * 60)
            : null;

        $pollOptionsJson = is_array($pollOptions)
            ? json_encode(array_values($pollOptions), JSON_THROW_ON_ERROR)
            : null;

        $alertId = $db->transaction(function (Database $db) use (
            $orgId, $createdByUser, $createdByToken, $alertType, $severity,
            $subject, $body, $bodyHtml, $channelsStr, $ttlMinutes, $expiresAt,
            $ackRequired, $ackDeadline, $escalationUserId, $pollQuestion, $pollOptionsJson,
            $externalRef, $targetRows, $userIds, $channels
        ): int {
            $db->execute(
                'INSERT INTO alerts (
                    created_by_user, created_by_token, org_id,
                    alert_type, severity, subject, body, body_html, channels,
                    ttl_minutes, expires_at,
                    ack_required, ack_deadline_minutes, escalation_user_id,
                    poll_question, poll_options,
                    status, external_ref, sent_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'sending\', ?, NOW())',
                [
                    $createdByUser, $createdByToken, $orgId,
                    $alertType, $severity, $subject, $body, $bodyHtml, $channelsStr,
                    $ttlMinutes ?: null, $expiresAt,
                    $ackRequired ? 1 : 0, $ackDeadline, $escalationUserId,
                    $pollQuestion, $pollOptionsJson,
                    $externalRef,
                ]
            );
            $alertId = $db->lastInsertId();

            foreach ($targetRows as $target) {
                $db->execute(
                    'INSERT INTO alert_targets
                        (alert_id, target_org_id, target_node_id, target_group_id, target_tag_id, target_user_id, target_label)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $alertId,
                        $target['target_org_id'] ?? null,
                        $target['target_node_id'] ?? null,
                        $target['target_group_id'] ?? null,
                        $target['target_tag_id'] ?? null,
                        $target['target_user_id'] ?? null,
                        $target['target_label'] ?? null,
                    ]
                );
            }

            if (in_array($alertType, ['chat', 'group_chat'], true)) {
                $db->execute(
                    'INSERT INTO chat_threads (alert_id, thread_type, is_open) VALUES (?, ?, 1)',
                    [$alertId, $alertType]
                );
            }

            $deliveryStats = self::createDeliveries($db, $alertId, $userIds, $channels, $severity);

            AuditService::log('alert.created', 'alert', (string) $alertId, [
                'severity'    => $severity,
                'alert_type'  => $alertType,
                'recipients'  => count($userIds),
                'deliveries'  => $deliveryStats,
            ], $createdByUser, $createdByToken);

            return $alertId;
        });

        JobQueueService::pushAlertDispatch($alertId);

        return self::fetchAlertSummary($db, $alertId, count($userIds));
    }

    /**
     * @return array{targets: list<array<string, mixed>>, errors: string[]}
     */
    private static function resolveInputTargets(Database $db, Request $request): array
    {
        $structured = $request->input('targets_structured');
        if (is_array($structured) && $structured !== []) {
            $rows     = TargetExpressionService::structuredToRows($structured);
            $converted = TargetExpressionService::rowsToAlertTargets($db, $rows);

            return ['targets' => $converted['targets'], 'errors' => $converted['errors']];
        }

        $expression = trim((string) $request->input('targets', ''));
        if ($expression !== '') {
            $parsed    = TargetExpressionService::parse($expression);
            if ($parsed['errors'] !== []) {
                return ['targets' => [], 'errors' => $parsed['errors']];
            }
            $converted = TargetExpressionService::rowsToAlertTargets($db, $parsed['rows']);

            return ['targets' => $converted['targets'], 'errors' => $converted['errors']];
        }

        $rawTargets = $request->input('target_rows');
        if (is_array($rawTargets) && $rawTargets !== []) {
            $targets = [];
            $errors  = [];
            foreach ($rawTargets as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $hasDim = !empty($row['target_org_id']) || !empty($row['target_node_id'])
                    || !empty($row['target_group_id']) || !empty($row['target_tag_id'])
                    || !empty($row['target_user_id']);
                if (!$hasDim) {
                    $errors[] = 'Target row ' . ($i + 1) . ' has no dimensions';
                    continue;
                }
                $targets[] = [
                    'target_org_id'   => !empty($row['target_org_id']) ? (int) $row['target_org_id'] : null,
                    'target_node_id'  => !empty($row['target_node_id']) ? (int) $row['target_node_id'] : null,
                    'target_group_id' => !empty($row['target_group_id']) ? (int) $row['target_group_id'] : null,
                    'target_tag_id'   => !empty($row['target_tag_id']) ? (int) $row['target_tag_id'] : null,
                    'target_user_id'  => !empty($row['target_user_id']) ? (int) $row['target_user_id'] : null,
                    'target_label'    => $row['target_label'] ?? null,
                ];
            }

            return ['targets' => $targets, 'errors' => $errors];
        }

        return ['targets' => [], 'errors' => ['targets expression or structured targets required']];
    }

    /**
     * @return array{0: int, 1: ?int, 2: ?int}
     */
    private static function resolveOrigin(Request $request, string $severity, string $alertType): array
    {
        if (!empty($request->token)) {
            $token = $request->token;
            self::assertTokenAllows($token, $severity, $alertType);

            return [(int) $token['owner_org_id'], null, (int) $token['id']];
        }

        if (empty($request->user)) {
            Response::unauthorized('Authentication required');
        }

        self::assertUserCanSend($request, $severity);

        $orgId = $request->input('org_id') !== null && $request->input('org_id') !== ''
            ? (int) $request->input('org_id')
            : (int) $request->user['org'];

        return [$orgId, (int) $request->user['uid'], null];
    }

    /** @param array<string, mixed> $token */
    private static function assertTokenAllows(array $token, string $severity, string $alertType): void
    {
        $allowedSeverity = array_filter(explode(',', (string) ($token['allowed_severity'] ?? '')));
        if ($allowedSeverity !== [] && !in_array($severity, $allowedSeverity, true)) {
            Response::forbidden("Token not permitted to send severity: {$severity}");
        }

        $allowedTypes = array_filter(explode(',', (string) ($token['allowed_alert_types'] ?? '')));
        if ($allowedTypes !== [] && !in_array($alertType, $allowedTypes, true)) {
            Response::forbidden("Token not permitted to send alert type: {$alertType}");
        }
    }

    private static function assertUserCanSend(Request $request, string $severity): void
    {
        if (in_array('super_admin', $request->user['roles'] ?? [], true)) {
            return;
        }

        $perms = $request->user['permissions'] ?? [];
        if (!in_array('alert.send', $perms, true)) {
            Response::forbidden('alert.send permission required');
        }

        if (in_array($severity, ['critical', 'evacuation'], true)
            && !in_array('alert.send.critical', $perms, true)) {
            Response::forbidden('alert.send.critical permission required for critical/evacuation alerts');
        }
    }

    /**
     * @param list<string> $channels
     * @return list<string>
     */
    private static function normalizeChannels(mixed $input): array
    {
        if (is_string($input)) {
            $input = array_filter(array_map('trim', explode(',', $input)));
        }
        if (!is_array($input)) {
            return [];
        }

        $out = [];
        foreach ($input as $ch) {
            $ch = strtolower(trim((string) $ch));
            if (in_array($ch, self::VALID_CHANNELS, true) && !in_array($ch, $out, true)) {
                $out[] = $ch;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @param list<string> $channels
     * @return array{queued: int, skipped: int}
     */
    private static function createDeliveries(
        Database $db,
        int $alertId,
        array $userIds,
        array $channels,
        string $severity
    ): array {
        $stats = ['queued' => 0, 'skipped' => 0];

        foreach ($userIds as $userId) {
            foreach ($channels as $channel) {
                if (!self::userWantsChannel($db, $userId, $channel, $severity)) {
                    continue;
                }

                $contact = self::findPrimaryContact($db, $userId, $channel);
                if ($contact === null) {
                    $stats['skipped']++;
                    continue;
                }

                $skipReason = self::deliverySkipReason($db, $userId, $contact, $channel);
                if ($skipReason !== null) {
                    $db->execute(
                        'INSERT INTO alert_deliveries
                            (alert_id, user_id, contact_id, channel, status, skip_reason)
                         VALUES (?, ?, ?, ?, \'skipped\', ?)',
                        [$alertId, $userId, (int) $contact['id'], $channel, $skipReason]
                    );
                    $stats['skipped']++;
                    continue;
                }

                $db->execute(
                    'INSERT INTO alert_deliveries
                        (alert_id, user_id, contact_id, channel, status)
                     VALUES (?, ?, ?, ?, \'queued\')',
                    [$alertId, $userId, (int) $contact['id'], $channel]
                );
                $stats['queued']++;
            }
        }

        return $stats;
    }

    private static function userWantsChannel(Database $db, int $userId, string $channel, string $severity): bool
    {
        if (in_array($severity, self::FORCE_CHANNELS, true)) {
            return true;
        }

        $col = match ($channel) {
            'email'    => 'channel_email',
            'sms'      => 'channel_sms',
            'push_web', 'push_fcm' => 'channel_push',
            'in_app'   => 'channel_in_app',
            default    => null,
        };

        if ($col === null) {
            return false;
        }

        $pref = $db->fetchOne(
            "SELECT {$col} AS enabled, system_override FROM user_notification_prefs
             WHERE user_id = ? AND severity = ?",
            [$userId, $severity]
        );

        if (!$pref) {
            return $channel !== 'sms';
        }

        if ((int) ($pref['system_override'] ?? 0) === 1) {
            return true;
        }

        return (int) ($pref['enabled'] ?? 0) === 1;
    }

    /** @return array<string, mixed>|null */
    private static function findPrimaryContact(Database $db, int $userId, string $channel): ?array
    {
        $contactChannel = in_array($channel, ['push_web', 'push_fcm'], true) ? $channel : $channel;

        $contact = $db->fetchOne(
            'SELECT id, contact_value, is_verified, is_primary
             FROM user_contacts
             WHERE user_id = ? AND channel = ? AND is_active = 1
             ORDER BY is_primary DESC, id ASC
             LIMIT 1',
            [$userId, $contactChannel]
        );

        return $contact ?: null;
    }

    /** @param array<string, mixed> $contact */
    private static function deliverySkipReason(Database $db, int $userId, array $contact, string $channel): ?string
    {
        if ($channel === 'email' && (int) ($contact['is_verified'] ?? 0) !== 1) {
            return 'contact_unverified';
        }

        if ($channel === 'sms') {
            $consent = $db->fetchValue(
                "SELECT status FROM user_sms_consent
                 WHERE user_id = ? AND contact_id = ?",
                [$userId, (int) $contact['id']]
            );
            if ($consent !== 'confirmed') {
                return 'sms_not_consented';
            }
        }

        if (in_array($channel, ['push_web', 'push_fcm', 'in_app'], true)) {
            return 'channel_not_implemented';
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function fetchAlertSummary(Database $db, int $alertId, ?int $recipientCount = null): array
    {
        $alert = $db->fetchOne(
            'SELECT a.*, o.display_name AS org_name,
                    u.display_name AS created_by_name,
                    st.name AS created_by_token_name
             FROM alerts a
             JOIN organizations o ON o.id = a.org_id
             LEFT JOIN users u ON u.id = a.created_by_user
             LEFT JOIN system_tokens st ON st.id = a.created_by_token
             WHERE a.id = ?',
            [$alertId]
        );

        if (!$alert) {
            Response::notFound('Alert not found');
        }

        $deliveryStats = $db->fetchOne(
            'SELECT
                SUM(status = \'queued\') AS queued,
                SUM(status = \'sent\') AS sent,
                SUM(status = \'delivered\') AS delivered,
                SUM(status = \'failed\') AS failed,
                SUM(status = \'skipped\') AS skipped
             FROM alert_deliveries WHERE alert_id = ?',
            [$alertId]
        );

        $targets = $db->fetchAll(
            'SELECT id, target_label, target_org_id, target_node_id, target_group_id, target_tag_id, target_user_id
             FROM alert_targets WHERE alert_id = ?',
            [$alertId]
        );

        return [
            'id'               => (int) $alert['id'],
            'org_id'           => (int) $alert['org_id'],
            'org_name'         => $alert['org_name'],
            'alert_type'       => $alert['alert_type'],
            'severity'         => $alert['severity'],
            'subject'          => $alert['subject'],
            'body'             => $alert['body'],
            'channels'         => explode(',', (string) $alert['channels']),
            'status'           => $alert['status'],
            'external_ref'     => $alert['external_ref'],
            'created_at'       => $alert['created_at'],
            'sent_at'          => $alert['sent_at'],
            'created_by_name'  => $alert['created_by_name'] ?? $alert['created_by_token_name'],
            'recipient_count'  => $recipientCount,
            'delivery_stats'   => $deliveryStats,
            'targets'          => $targets,
        ];
    }
}
