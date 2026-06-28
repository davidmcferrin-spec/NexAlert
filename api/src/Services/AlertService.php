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
use NexAlert\Config\Env;

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

        if ($createdByUser !== null && empty($request->token)) {
            SendScopeService::assertUserCanTarget($db, $request->user, $targetRows, $orgId);
        }

        $ackRequired = $alertType === 'ack_required' || (bool) $request->input('ack_required', false);
        $ackDeadline = $request->input('ack_deadline_minutes') !== null
            ? (int) $request->input('ack_deadline_minutes') : null;
        $escalationUserId = $request->input('escalation_user_id') !== null
            ? (int) $request->input('escalation_user_id') : null;
        $escalationGroupId = $request->input('escalation_group_id') !== null
            ? (int) $request->input('escalation_group_id') : null;

        if ($escalationUserId === 0) {
            $escalationUserId = null;
        }
        if ($escalationGroupId === 0) {
            $escalationGroupId = null;
        }
        if ($escalationUserId !== null && $escalationGroupId !== null) {
            Response::validationError([
                'escalation' => 'Specify either escalation_user_id or escalation_group_id, not both',
            ]);
        }
        if ($escalationGroupId !== null) {
            $escGroup = $db->fetchOne(
                'SELECT id FROM `groups` WHERE id = ? AND is_active = 1 AND owner_org_id = ?',
                [$escalationGroupId, $orgId]
            );
            if (!$escGroup) {
                Response::validationError(['escalation_group_id' => 'Invalid or inactive escalation group']);
            }
        }
        $pollQuestion = $request->input('poll_question') !== null
            ? trim((string) $request->input('poll_question')) : null;
        $pollOptions  = $request->input('poll_options');
        $ttlMinutes   = $request->input('ttl_minutes') !== null ? (int) $request->input('ttl_minutes') : null;

        if ($ttlMinutes !== null && $ttlMinutes <= 0) {
            Response::validationError(['ttl_minutes' => 'Must be a positive number of minutes']);
        }

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
        // expires_at is set when the alert finishes sending (sent_at + ttl_minutes)

        $pollOptionsJson = is_array($pollOptions)
            ? json_encode(array_values($pollOptions), JSON_THROW_ON_ERROR)
            : null;

        $sendAtParsed = self::parseSendAt($request->input('send_at'));
        $isScheduled  = $sendAtParsed !== null && $sendAtParsed['is_future'];
        $alertStatus  = $isScheduled ? 'scheduled' : 'sending';

        $alertId = $db->transaction(function (Database $db) use (
            $orgId, $createdByUser, $createdByToken, $alertType, $severity,
            $subject, $body, $bodyHtml, $channelsStr, $ttlMinutes,
            $ackRequired, $ackDeadline, $escalationUserId, $escalationGroupId, $pollQuestion, $pollOptionsJson,
            $externalRef, $targetRows, $userIds, $channels, $sendAtParsed, $alertStatus
        ): int {
            $sendAtDb = $sendAtParsed['utc'] ?? null;

            $db->execute(
                'INSERT INTO alerts (
                    created_by_user, created_by_token, org_id,
                    alert_type, severity, subject, body, body_html, channels,
                    send_at, ttl_minutes, expires_at,
                    ack_required, ack_deadline_minutes, escalation_user_id, escalation_group_id,
                    poll_question, poll_options,
                    status, external_ref
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $createdByUser, $createdByToken, $orgId,
                    $alertType, $severity, $subject, $body, $bodyHtml, $channelsStr,
                    $sendAtDb, $ttlMinutes ?: null,
                    $ackRequired ? 1 : 0, $ackDeadline, $escalationUserId, $escalationGroupId,
                    $pollQuestion, $pollOptionsJson,
                    $alertStatus, $externalRef,
                ]
            );
            $alertId = $db->lastInsertId();

            foreach ($targetRows as $target) {
                $conjJson = !empty($target['conj_terms']) && is_array($target['conj_terms'])
                    ? json_encode($target['conj_terms'])
                    : null;

                $db->execute(
                    'INSERT INTO alert_targets
                        (alert_id, target_org_id, target_node_id, target_group_id, target_tag_id, target_user_id, target_label, conj_terms)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $alertId,
                        $target['target_org_id'] ?? null,
                        $target['target_node_id'] ?? null,
                        $target['target_group_id'] ?? null,
                        $target['target_tag_id'] ?? null,
                        $target['target_user_id'] ?? null,
                        $target['target_label'] ?? null,
                        $conjJson,
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

        if ($isScheduled && $sendAtParsed !== null) {
            JobQueueService::pushAlertDispatchAt($alertId, $sendAtParsed['utc']);
        } else {
            JobQueueService::pushAlertDispatch($alertId);
        }

        $summary = self::fetchAlertSummary($db, $alertId, count($userIds));
        $summary['scheduled'] = $isScheduled;
        if ($sendAtParsed !== null) {
            $summary['send_at'] = $sendAtParsed['utc'];
        }

        return $summary;
    }

    /**
     * Parse send_at from API (ISO local datetime or Y-m-d H:i:s). Returns null if empty/immediate past.
     *
     * @return array{utc: string, is_future: bool}|null
     */
    private static function parseSendAt(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }

        $tzName = Env::get('APP_TIMEZONE', 'America/Chicago');
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $local = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $text, $tz)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, $tz)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $text, $tz);

        if ($local === false) {
            try {
                $local = new \DateTimeImmutable($text, $tz);
            } catch (\Throwable) {
                Response::validationError(['send_at' => 'Invalid datetime format']);
            }
        }

        $utc    = $local->setTimezone(new \DateTimeZone('UTC'));
        $utcStr = $utc->format('Y-m-d H:i:s');
        $isFuture = $utc->getTimestamp() > time() + 30;

        if (!$isFuture) {
            return null;
        }

        return ['utc' => $utcStr, 'is_future' => true];
    }

    /**
     * @return array{targets: list<array<string, mixed>>, errors: string[]}
     */
    private static function resolveInputTargets(Database $db, Request $request): array
    {
        $tree = $request->input('target_tree');
        if (is_array($tree) && ($tree['type'] ?? '') === 'group') {
            return self::compileTreeToTargets($db, $tree);
        }

        $structured = $request->input('targets_structured');
        if (is_array($structured) && $structured !== []) {
            if (($structured['type'] ?? '') === 'group') {
                return self::compileTreeToTargets($db, $structured);
            }

            $rows      = TargetExpressionService::structuredToFlatRows($structured);
            $ast       = \NexAlert\Services\TargetAstService::flatRowsToAst($rows);
            $dnf       = \NexAlert\Services\TargetAstService::astToDnf($ast);
            if ($dnf['errors'] !== []) {
                return ['targets' => [], 'errors' => $dnf['errors']];
            }
            $converted = TargetExpressionService::dnfToAlertTargets($db, $dnf['conjunctions']);

            return ['targets' => $converted['targets'], 'errors' => $converted['errors']];
        }

        $expression = trim((string) $request->input('targets', ''));
        if ($expression !== '') {
            $compiled = TargetExpressionService::compileExpressionAst($expression);
            if ($compiled['errors'] !== []) {
                return ['targets' => [], 'errors' => $compiled['errors']];
            }
            $dnf = \NexAlert\Services\TargetAstService::astToDnf($compiled['ast']);
            if ($dnf['errors'] !== []) {
                return ['targets' => [], 'errors' => $dnf['errors']];
            }
            $converted = TargetExpressionService::dnfToAlertTargets($db, $dnf['conjunctions']);

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
     * @param array<string, mixed> $tree
     * @return array{targets: list<array<string, mixed>>, errors: string[]}
     */
    private static function compileTreeToTargets(Database $db, array $tree): array
    {
        $compiled = TargetExpressionService::compileExpressionAst('', $tree);
        if ($compiled['errors'] !== []) {
            return ['targets' => [], 'errors' => $compiled['errors']];
        }
        $dnf = \NexAlert\Services\TargetAstService::astToDnf($compiled['ast']);
        if ($dnf['errors'] !== []) {
            return ['targets' => [], 'errors' => $dnf['errors']];
        }
        $converted = TargetExpressionService::dnfToAlertTargets($db, $dnf['conjunctions']);

        return ['targets' => $converted['targets'], 'errors' => $converted['errors']];
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
        $db = Database::getInstance();

        if (!PermissionService::hasPermission($db, $request->user, 'alert.send')) {
            Response::forbidden('alert.send permission required');
        }

        if (in_array($severity, ['critical', 'evacuation'], true)
            && !PermissionService::hasPermission($db, $request->user, 'alert.send.critical')) {
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

                if ($channel === 'push_web') {
                    $stats = self::queuePushDeliveries($db, $alertId, $userId, $stats);
                    continue;
                }

                if ($channel === 'in_app') {
                    $stats = self::queueInAppDelivery($db, $alertId, $userId, $stats);
                    continue;
                }

                if ($channel === 'push_fcm') {
                    $contact = self::findPrimaryContact($db, $userId, $channel);
                    $contactId = $contact ? (int) $contact['id'] : self::fallbackContactId($db, $userId);
                    if ($contactId === null) {
                        $stats['skipped']++;
                        continue;
                    }
                    $db->execute(
                        'INSERT INTO alert_deliveries
                            (alert_id, user_id, contact_id, channel, status, skip_reason)
                         VALUES (?, ?, ?, ?, \'skipped\', ?)',
                        [$alertId, $userId, $contactId, $channel, 'channel_not_implemented']
                    );
                    $stats['skipped']++;
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

        if ($channel === 'email' && !self::isDeliverableEmail((string) ($contact['contact_value'] ?? ''))) {
            return 'invalid_email_address';
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

        return null;
    }

    private static function isDeliverableEmail(string $addr): bool
    {
        $addr = trim($addr);
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = strtolower((string) substr(strrchr($addr, '@'), 1));

        return $domain !== ''
            && str_contains($domain, '.')
            && !in_array($domain, ['localhost', 'local', 'test', 'invalid'], true);
    }

    /**
     * @param array{queued: int, skipped: int} $stats
     * @return array{queued: int, skipped: int}
     */
    private static function queuePushDeliveries(
        Database $db,
        int $alertId,
        int $userId,
        array $stats
    ): array {
        if (!WebPushService::isConfigured()) {
            $stats['skipped']++;
            return $stats;
        }

        $subs = WebPushService::activeSubscriptionsForUser($db, $userId);
        if ($subs === []) {
            $stats['skipped']++;
            return $stats;
        }

        foreach ($subs as $sub) {
            $db->execute(
                'INSERT INTO alert_deliveries
                    (alert_id, user_id, contact_id, push_subscription_id, channel, status)
                 VALUES (?, ?, NULL, ?, \'push_web\', \'queued\')',
                [$alertId, $userId, (int) $sub['id']]
            );
            $stats['queued']++;
        }

        return $stats;
    }

    /**
     * @param array{queued: int, skipped: int} $stats
     * @return array{queued: int, skipped: int}
     */
    private static function queueInAppDelivery(
        Database $db,
        int $alertId,
        int $userId,
        array $stats
    ): array {
        $contactId = self::fallbackContactId($db, $userId);
        if ($contactId === null) {
            $stats['skipped']++;
            return $stats;
        }

        $db->execute(
            'INSERT INTO alert_deliveries
                (alert_id, user_id, contact_id, channel, status, sent_at)
             VALUES (?, ?, ?, \'in_app\', \'sent\', UTC_TIMESTAMP())',
            [$alertId, $userId, $contactId]
        );
        $stats['queued']++;

        return $stats;
    }

    private static function fallbackContactId(Database $db, int $userId): ?int
    {
        $id = $db->fetchValue(
            'SELECT id FROM user_contacts
             WHERE user_id = ? AND is_active = 1
             ORDER BY is_primary DESC, id ASC LIMIT 1',
            [$userId]
        );

        return $id !== false && $id !== null ? (int) $id : null;
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
            'SELECT id, target_label, target_org_id, target_node_id, target_group_id, target_tag_id, target_user_id, conj_terms
             FROM alert_targets WHERE alert_id = ?',
            [$alertId]
        );

        foreach ($targets as &$target) {
            if (!empty($target['conj_terms']) && is_string($target['conj_terms'])) {
                $decoded = json_decode($target['conj_terms'], true);
                $target['conj_terms'] = is_array($decoded) ? $decoded : null;
            }
        }
        unset($target);

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
            'send_at'          => $alert['send_at'],
            'external_ref'     => $alert['external_ref'],
            'created_at'       => $alert['created_at'],
            'sent_at'          => $alert['sent_at'],
            'ack_deadline_at'      => $alert['ack_deadline_at'] ?? null,
            'escalation_user_id'   => !empty($alert['escalation_user_id']) ? (int) $alert['escalation_user_id'] : null,
            'escalation_group_id'  => !empty($alert['escalation_group_id']) ? (int) $alert['escalation_group_id'] : null,
            'escalated_at'         => $alert['escalated_at'] ?? null,
            'ttl_minutes'          => $alert['ttl_minutes'] !== null ? (int) $alert['ttl_minutes'] : null,
            'expires_at'           => $alert['expires_at'],
            'poll_question'        => $alert['poll_question'],
            'poll_options'         => PollService::parsePollOptions($alert),
            'created_by_name'  => $alert['created_by_name'] ?? $alert['created_by_token_name'],
            'recipient_count'  => $recipientCount,
            'delivery_stats'   => $deliveryStats,
            'targets'          => $targets,
        ];
    }

    /**
     * Cancel a pending or in-flight alert and mark queued deliveries skipped.
     */
    public static function cancel(int $alertId): void
    {
        $db    = Database::getInstance();
        $alert = $db->fetchOne('SELECT id, status FROM alerts WHERE id = ?', [$alertId]);

        if (!$alert) {
            Response::notFound('Alert not found');
        }

        if (!in_array($alert['status'], ['draft', 'scheduled', 'sending'], true)) {
            Response::error('Only draft, scheduled, or sending alerts can be cancelled', 409);
        }

        $db->transaction(function (Database $db) use ($alertId): void {
            self::cancelPendingJobs($db, $alertId);
            $db->execute(
                "UPDATE alert_deliveries SET status = 'skipped', skip_reason = 'alert_cancelled'
                 WHERE alert_id = ? AND status = 'queued'",
                [$alertId]
            );
            $db->execute(
                "UPDATE alerts SET status = 'cancelled' WHERE id = ?",
                [$alertId]
            );
        });
    }

    /**
     * Re-queue dispatch for failed or cancelled deliveries.
     */
    public static function retry(int $alertId): void
    {
        $db    = Database::getInstance();
        $alert = $db->fetchOne('SELECT id, status FROM alerts WHERE id = ?', [$alertId]);

        if (!$alert) {
            Response::notFound('Alert not found');
        }

        if (!in_array($alert['status'], ['sending', 'sent', 'cancelled'], true)) {
            Response::error('This alert cannot be retried', 409);
        }

        $retryable = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM alert_deliveries
             WHERE alert_id = ? AND (
                status = 'failed'
                OR status = 'queued'
                OR (status = 'skipped' AND skip_reason IN ('alert_cancelled', 'dispatch_error'))
             )",
            [$alertId]
        );

        if ($retryable === 0 && $alert['status'] !== 'cancelled') {
            Response::error('No failed or pending deliveries to retry', 409);
        }

        $db->transaction(function (Database $db) use ($alertId): void {
            self::cancelPendingJobs($db, $alertId);
            $db->execute(
                "UPDATE alert_deliveries
                 SET status = 'queued', skip_reason = NULL, failed_at = NULL,
                     sent_at = NULL, provider_message_id = NULL, retry_count = retry_count + 1
                 WHERE alert_id = ? AND (
                    status IN ('failed', 'queued')
                    OR (status = 'skipped' AND skip_reason IN ('alert_cancelled', 'dispatch_error'))
                 )",
                [$alertId]
            );
            $db->execute(
                "UPDATE alerts SET status = 'sending', sent_at = NULL WHERE id = ?",
                [$alertId]
            );
            JobQueueService::pushAlertDispatch($alertId);
        });
    }

    /**
     * Permanently remove an alert and its delivery records (cascade).
     */
    public static function delete(int $alertId): void
    {
        $db    = Database::getInstance();
        $alert = $db->fetchOne('SELECT id, severity FROM alerts WHERE id = ?', [$alertId]);

        if (!$alert) {
            Response::notFound('Alert not found');
        }

        $db->transaction(function (Database $db) use ($alertId): void {
            self::cancelPendingJobs($db, $alertId);
            $db->execute('DELETE FROM alerts WHERE id = ?', [$alertId]);
        });
    }

    /**
     * Mark an alert expired — skip queued deliveries and cancel pending jobs.
     */
    public static function expire(int $alertId): void
    {
        $db    = Database::getInstance();
        $alert = $db->fetchOne('SELECT id, status FROM alerts WHERE id = ?', [$alertId]);

        if (!$alert || in_array($alert['status'], ['expired', 'cancelled', 'draft'], true)) {
            return;
        }

        $db->transaction(function (Database $db) use ($alertId): void {
            self::cancelPendingJobs($db, $alertId);
            $db->execute(
                "UPDATE alert_deliveries
                 SET status = 'skipped', skip_reason = 'alert_expired'
                 WHERE alert_id = ? AND status = 'queued'",
                [$alertId]
            );
            $db->execute(
                "UPDATE alerts SET status = 'expired' WHERE id = ? AND status NOT IN ('cancelled', 'expired')",
                [$alertId]
            );
        });
    }

    public static function cancelPendingJobs(Database $db, int $alertId): int
    {
        $stmt = $db->execute(
            "UPDATE jobs SET status = 'failed', error = 'alert_cancelled', failed_at = UTC_TIMESTAMP()
             WHERE queue = ? AND status IN ('pending', 'processing')
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.alert_id')) AS UNSIGNED) = ?",
            [JobQueueService::QUEUE_DISPATCH, $alertId]
        );

        return $stmt->rowCount();
    }
}
