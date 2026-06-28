<?php
/**
 * NexAlert - Notification Service
 * In-app update feed, chat reply notifications (push + email).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;
use NexAlert\Config\Env;

class NotificationService
{
    /**
     * Poll endpoint: new alerts and chat messages since a client timestamp.
     *
     * @return array{server_time: string, items: list<array<string, mixed>>}
     */
    public static function getUpdates(Database $db, int $userId, string $since): array
    {
        $since = self::normalizeSince($since);
        $items = [];

        $alerts = $db->fetchAll(
            'SELECT DISTINCT a.id, a.subject, a.severity, a.alert_type, ad.sent_at, ad.channel
             FROM alerts a
             JOIN alert_deliveries ad ON ad.alert_id = a.id AND ad.user_id = ?
             WHERE ad.sent_at IS NOT NULL
               AND ad.sent_at > ?
               AND ad.user_id = ?
             ORDER BY ad.sent_at ASC
             LIMIT 20',
            [$userId, $since, $userId]
        );

        foreach ($alerts as $row) {
            $items[] = [
                'id'      => 'alert-' . $row['id'] . '-' . strtotime((string) $row['sent_at']),
                'type'    => 'alert',
                'alert_id'=> (int) $row['id'],
                'title'   => '[' . strtoupper((string) $row['severity']) . '] ' . ($row['subject'] ?? 'Alert'),
                'body'    => 'New alert delivered via ' . ($row['channel'] ?? 'unknown'),
                'url'     => '/profile?alert=' . (int) $row['id'],
                'at'      => $row['sent_at'],
            ];
        }

        $chatRows = $db->fetchAll(
            'SELECT cm.id, cm.body, cm.created_at, cm.user_id, cm.source_channel,
                    a.id AS alert_id, a.subject, ct.thread_type, a.created_by_user
             FROM chat_messages cm
             JOIN chat_threads ct ON ct.id = cm.thread_id
             JOIN alerts a ON a.id = ct.alert_id
             WHERE cm.is_deleted = 0
               AND cm.created_at > ?
               AND cm.user_id != ?
               AND (
                    a.created_by_user = ?
                    OR EXISTS (
                        SELECT 1 FROM alert_deliveries ad
                        WHERE ad.alert_id = a.id AND ad.user_id = ?
                    )
               )
             ORDER BY cm.created_at ASC
             LIMIT 30',
            [$since, $userId, $userId, $userId]
        );

        foreach ($chatRows as $row) {
            $threadType   = (string) $row['thread_type'];
            $originatorId = (int) ($row['created_by_user'] ?? 0);
            $authorId     = (int) $row['user_id'];

            if ($threadType === 'chat' && $userId !== $originatorId && $authorId !== $originatorId && $authorId !== $userId) {
                continue;
            }

            $author = $db->fetchOne('SELECT display_name FROM users WHERE id = ?', [$authorId]);
            $name   = $author['display_name'] ?? 'Someone';
            $preview = mb_strlen((string) $row['body']) > 100
                ? mb_substr((string) $row['body'], 0, 97) . '...'
                : (string) $row['body'];

            $items[] = [
                'id'       => 'chat-' . $row['id'],
                'type'     => 'chat',
                'alert_id' => (int) $row['alert_id'],
                'title'    => 'Chat: ' . ($row['subject'] ?? 'Alert'),
                'body'     => $name . ' (' . $row['source_channel'] . '): ' . $preview,
                'url'      => '/profile?alert=' . (int) $row['alert_id'],
                'at'       => $row['created_at'],
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return [
            'server_time' => gmdate('Y-m-d H:i:s'),
            'items'       => $items,
        ];
    }

    /**
     * Notify other chat participants via push job (+ optional email).
     *
     * @param array<string, mixed> $ctx From ChatService::getThreadContext
     */
    public static function notifyChatParticipants(
        Database $db,
        int $alertId,
        int $senderId,
        string $messageBody,
        array $ctx
    ): void {
        $originatorId = (int) ($ctx['originator_id'] ?? 0);
        $threadType   = (string) ($ctx['thread']['thread_type'] ?? 'chat');
        $subject      = (string) ($ctx['alert']['subject'] ?? 'Alert');

        $sender = $db->fetchOne('SELECT display_name, username FROM users WHERE id = ?', [$senderId]);
        $senderName = $sender['display_name'] ?? $sender['username'] ?? 'Someone';

        $targetIds = self::chatNotifyTargets($db, $alertId, $senderId, $originatorId, $threadType);
        if ($targetIds === []) {
            return;
        }

        $appUrl  = rtrim(Env::get('APP_URL', ''), '/');
        $url     = $appUrl . '/profile?alert=' . $alertId;
        $title   = 'Chat reply: ' . $subject;
        $preview = mb_strlen($messageBody) > 120 ? mb_substr($messageBody, 0, 117) . '...' : $messageBody;
        $body    = $senderName . ': ' . $preview;

        foreach ($targetIds as $uid) {
            if (WebPushService::isConfigured()) {
                JobQueueService::pushPushNotify($uid, $title, $body, $url);
            }

            $email = $db->fetchValue(
                'SELECT uc.contact_value FROM user_contacts uc
                 WHERE uc.user_id = ? AND uc.channel = \'email\' AND uc.is_primary = 1
                   AND uc.is_verified = 1 AND uc.is_active = 1
                 LIMIT 1',
                [$uid]
            );

            if ($email) {
                try {
                    MailService::sendChatReplyNotify((string) $email, $subject, $senderName, $messageBody, $url);
                } catch (\Throwable $e) {
                    // Non-fatal — push may still deliver
                }
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function chatNotifyTargets(
        Database $db,
        int $alertId,
        int $senderId,
        int $originatorId,
        string $threadType
    ): array {
        $ids = [];

        if ($threadType === 'group_chat') {
            $rows = $db->fetchAll(
                'SELECT DISTINCT user_id FROM alert_deliveries WHERE alert_id = ?',
                [$alertId]
            );
            foreach ($rows as $row) {
                $ids[] = (int) $row['user_id'];
            }
            if ($originatorId > 0) {
                $ids[] = $originatorId;
            }
        } elseif ($senderId === $originatorId) {
            $rows = $db->fetchAll(
                'SELECT DISTINCT user_id FROM alert_deliveries WHERE alert_id = ?',
                [$alertId]
            );
            foreach ($rows as $row) {
                $ids[] = (int) $row['user_id'];
            }
        } elseif ($originatorId > 0) {
            $ids[] = $originatorId;
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0 && $id !== $senderId)));

        return $ids;
    }

    /**
     * @return array{active_subscriptions: int, inactive_subscriptions: int, push_failed_7d: int, push_sent_7d: int}
     */
    public static function pushStats(Database $db, bool $isSuperAdmin, int $orgId): array
    {
        if ($isSuperAdmin) {
            $active = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1'
            );
            $inactive = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 0'
            );
            $failed = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alert_deliveries
                 WHERE channel = 'push_web' AND status = 'failed'
                   AND failed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
            );
            $sent = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alert_deliveries
                 WHERE channel = 'push_web' AND status IN ('sent', 'delivered')
                   AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
            );
        } else {
            $active = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM push_subscriptions ps
                 JOIN users u ON u.id = ps.user_id
                 WHERE ps.is_active = 1 AND u.home_org_id = ?',
                [$orgId]
            );
            $inactive = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM push_subscriptions ps
                 JOIN users u ON u.id = ps.user_id
                 WHERE ps.is_active = 0 AND u.home_org_id = ?',
                [$orgId]
            );
            $failed = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alert_deliveries ad
                 JOIN alerts a ON a.id = ad.alert_id
                 WHERE ad.channel = 'push_web' AND ad.status = 'failed'
                   AND a.org_id = ?
                   AND ad.failed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
                [$orgId]
            );
            $sent = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alert_deliveries ad
                 JOIN alerts a ON a.id = ad.alert_id
                 WHERE ad.channel = 'push_web' AND ad.status IN ('sent', 'delivered')
                   AND a.org_id = ?
                   AND ad.sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
                [$orgId]
            );
        }

        return [
            'active_subscriptions'   => $active,
            'inactive_subscriptions' => $inactive,
            'push_failed_7d'         => $failed,
            'push_sent_7d'           => $sent,
        ];
    }

    private static function normalizeSince(string $since): string
    {
        $since = trim($since);
        if ($since === '') {
            return gmdate('Y-m-d H:i:s', time() - 300);
        }

        $ts = strtotime($since);
        if ($ts === false) {
            return gmdate('Y-m-d H:i:s', time() - 300);
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function normalizeSincePublic(string $since): string
    {
        return self::normalizeSince($since);
    }
}
