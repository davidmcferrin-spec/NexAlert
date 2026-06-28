<?php
/**
 * NexAlert - Chat Service
 * Thread messages for chat and group_chat alert types.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Response;
use NexAlert\Config\Database;

class ChatService
{
    /**
     * @return array<string, mixed>
     */
    public static function getThreadContext(Database $db, int $alertId, int $userId): array
    {
        $alert = $db->fetchOne(
            'SELECT a.id, a.alert_type, a.status, a.expires_at, a.created_by_user, a.subject
             FROM alerts a WHERE a.id = ?',
            [$alertId]
        );

        if (!$alert || !in_array($alert['alert_type'], ['chat', 'group_chat'], true)) {
            Response::error('This alert is not a chat thread', 409);
        }

        if (PollService::isExpired($alert)) {
            Response::error('This chat has expired', 410);
        }

        $thread = $db->fetchOne(
            'SELECT id, thread_type, is_open, closed_at FROM chat_threads WHERE alert_id = ?',
            [$alertId]
        );
        if (!$thread) {
            Response::error('Chat thread not found', 404);
        }

        $originatorId = (int) ($alert['created_by_user'] ?? 0);
        $isOriginator = $originatorId > 0 && $originatorId === $userId;

        if (!$isOriginator) {
            $isRecipient = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM alert_deliveries
                 WHERE alert_id = ? AND user_id = ?',
                [$alertId, $userId]
            ) > 0;
            if (!$isRecipient) {
                Response::forbidden('You are not a participant in this chat');
            }
        }

        return [
            'alert'        => $alert,
            'thread'       => $thread,
            'is_originator'=> $isOriginator,
            'originator_id'=> $originatorId,
        ];
    }

    /**
     * @return array{messages: list<array<string, mixed>>, thread: array<string, mixed>}
     */
    public static function listMessages(
        Database $db,
        int $alertId,
        int $userId,
        int $limit = 100,
        ?string $since = null
    ): array {
        $ctx = self::getThreadContext($db, $alertId, $userId);
        $threadId = (int) $ctx['thread']['id'];
        $limit = min(max($limit, 1), 200);

        $params = [$threadId];
        $sinceSql = '';
        if ($since !== null && trim($since) !== '') {
            $sinceSql = ' AND cm.created_at > ?';
            $params[] = NotificationService::normalizeSincePublic(trim($since));
        }
        $params[] = $limit;

        $rows = $db->fetchAll(
            'SELECT cm.id, cm.user_id, cm.source_channel, cm.body, cm.created_at,
                    u.display_name AS user_name, u.username
             FROM chat_messages cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.thread_id = ? AND cm.is_deleted = 0' . $sinceSql . '
             ORDER BY cm.created_at ASC
             LIMIT ?',
            $params
        );

        $filtered = self::filterVisibleMessages(
            $rows,
            (string) $ctx['thread']['thread_type'],
            $userId,
            (int) $ctx['originator_id'],
            (bool) $ctx['is_originator']
        );

        return [
            'thread'   => [
                'id'         => $threadId,
                'thread_type'=> $ctx['thread']['thread_type'],
                'is_open'    => (int) $ctx['thread']['is_open'],
                'is_originator' => $ctx['is_originator'],
            ],
            'messages' => $filtered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sendMessage(
        Database $db,
        int $alertId,
        int $userId,
        string $body,
        string $channel = 'web',
        ?string $twilioSid = null
    ): array {
        $body = trim($body);
        if ($body === '') {
            Response::validationError(['body' => 'Required']);
        }

        $ctx = self::getThreadContext($db, $alertId, $userId);

        if (!(int) $ctx['thread']['is_open']) {
            Response::error('This chat thread is closed', 409);
        }

        if (!in_array($ctx['alert']['status'], ['sending', 'sent'], true)) {
            Response::error('Chat is not open for messages', 409);
        }

        $threadId = (int) $ctx['thread']['id'];

        if ($twilioSid !== null && $twilioSid !== '') {
            $dup = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM chat_messages WHERE twilio_message_sid = ?',
                [$twilioSid]
            );
            if ($dup > 0) {
                $listed = self::listMessages($db, $alertId, $userId);

                return end($listed['messages']) ?: ['id' => 0];
            }
        }

        $sourceChannel = in_array($channel, ['web', 'sms', 'app'], true) ? $channel : 'web';

        self::insertMessage($db, $threadId, $alertId, $userId, $sourceChannel, $body, $twilioSid, $ctx);

        return $db->fetchOne(
            'SELECT cm.id, cm.user_id, cm.source_channel, cm.body, cm.created_at,
                    u.display_name AS user_name, u.username
             FROM chat_messages cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.thread_id = ?
             ORDER BY cm.id DESC LIMIT 1',
            [$threadId]
        ) ?: ['id' => 0];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function insertMessage(
        Database $db,
        int $threadId,
        int $alertId,
        int $userId,
        string $sourceChannel,
        string $body,
        ?string $twilioSid,
        array $ctx
    ): void {
        $db->execute(
            'INSERT INTO chat_messages (thread_id, user_id, source_channel, body, twilio_message_sid)
             VALUES (?, ?, ?, ?, ?)',
            [$threadId, $userId, $sourceChannel, $body, $twilioSid]
        );

        AuditService::log('chat.message_sent', 'alert', (string) $alertId, [
            'thread_id'      => $threadId,
            'source_channel' => $sourceChannel,
        ], $userId);

        NotificationService::notifyChatParticipants($db, $alertId, $userId, $body, $ctx);
    }

    public static function closeThread(Database $db, int $alertId, int $userId): void
    {
        $ctx = self::getThreadContext($db, $alertId, $userId);
        if (!$ctx['is_originator']) {
            Response::forbidden('Only the alert originator can close this chat');
        }

        $db->execute(
            'UPDATE chat_threads SET is_open = 0, closed_at = NOW(), closed_by = ?
             WHERE alert_id = ?',
            [$userId, $alertId]
        );

        AuditService::log('chat.closed', 'alert', (string) $alertId, [], $userId);
    }

    /**
     * Route inbound SMS to an open chat thread for this user (no HTTP response side effects).
     */
    public static function handleInboundSms(Database $db, int $userId, string $body, ?string $twilioSid): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }

        $row = $db->fetchOne(
            'SELECT ct.id AS thread_id, ct.alert_id, ct.is_open, a.alert_type, a.status,
                    a.expires_at, a.created_by_user, a.subject
             FROM chat_threads ct
             JOIN alerts a ON a.id = ct.alert_id
             JOIN alert_deliveries ad ON ad.alert_id = a.id AND ad.user_id = ?
             WHERE ct.is_open = 1
               AND a.alert_type IN (\'chat\', \'group_chat\')
               AND a.status IN (\'sending\', \'sent\')
               AND (a.expires_at IS NULL OR a.expires_at > UTC_TIMESTAMP())
             ORDER BY a.created_at DESC
             LIMIT 1',
            [$userId]
        );

        if (!$row) {
            return false;
        }

        if (PollService::isExpired($row)) {
            return false;
        }

        $threadId = (int) $row['thread_id'];
        $alertId  = (int) $row['alert_id'];

        if ($twilioSid !== null && $twilioSid !== '') {
            $dup = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM chat_messages WHERE twilio_message_sid = ?',
                [$twilioSid]
            );
            if ($dup > 0) {
                return true;
            }
        }

        $ctx = [
            'alert'         => [
                'id'              => $alertId,
                'subject'         => $row['subject'] ?? '',
                'alert_type'      => $row['alert_type'],
                'status'          => $row['status'],
                'expires_at'      => $row['expires_at'],
                'created_by_user' => $row['created_by_user'],
            ],
            'thread'        => [
                'id'          => $threadId,
                'thread_type' => $row['alert_type'],
                'is_open'     => 1,
            ],
            'originator_id' => (int) ($row['created_by_user'] ?? 0),
        ];

        self::insertMessage($db, $threadId, $alertId, $userId, 'sms', $body, $twilioSid, $ctx);

        return true;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private static function filterVisibleMessages(
        array $messages,
        string $threadType,
        int $viewerId,
        int $originatorId,
        bool $isOriginator
    ): array {
        if ($threadType === 'group_chat' || $isOriginator) {
            return $messages;
        }

        return array_values(array_filter(
            $messages,
            static fn (array $m): bool => (int) $m['user_id'] === $viewerId
                || (int) $m['user_id'] === $originatorId
        ));
    }
}
