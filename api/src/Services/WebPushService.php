<?php
/**
 * NexAlert - Web Push Service
 * VAPID subscription management (delivery via dispatch worker).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Env;

class WebPushService
{
    private const MAX_FAILURES = 5;

    public static function getPublicKey(): string
    {
        $key = trim(Env::get('VAPID_PUBLIC_KEY', ''));
        if ($key === '') {
            Response::error('Web Push is not configured on this server', 503);
        }

        return $key;
    }

    public static function isConfigured(): bool
    {
        return trim(Env::get('VAPID_PUBLIC_KEY', '')) !== ''
            && trim(Env::get('VAPID_PRIVATE_KEY', '')) !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listSubscriptions(Database $db, int $userId): array
    {
        return $db->fetchAll(
            'SELECT id, device_label, user_agent, is_active, last_used_at, created_at
             FROM push_subscriptions
             WHERE user_id = ? AND is_active = 1
             ORDER BY created_at DESC',
            [$userId]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function subscribe(Database $db, int $userId, array $payload): array
    {
        if (!self::isConfigured()) {
            Response::error('Web Push is not configured on this server', 503);
        }

        $endpoint = trim((string) ($payload['endpoint'] ?? ''));
        $p256dh   = trim((string) ($payload['p256dh'] ?? ''));
        $auth     = trim((string) ($payload['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            Response::validationError(['subscription' => 'endpoint, p256dh, and auth are required']);
        }

        $existing = $db->fetchOne(
            'SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?',
            [$userId, $endpoint]
        );

        $label = trim((string) ($payload['device_label'] ?? ''));
        $ua    = trim((string) ($payload['user_agent'] ?? ''));
        if ($label === '' && $ua !== '') {
            $label = self::labelFromUserAgent($ua);
        }

        if ($existing) {
            $db->execute(
                'UPDATE push_subscriptions
                 SET p256dh = ?, auth_key = ?, device_label = ?, user_agent = ?,
                     is_active = 1, failed_count = 0
                 WHERE id = ?',
                [$p256dh, $auth, $label ?: null, $ua ?: null, (int) $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $db->execute(
                'INSERT INTO push_subscriptions
                    (user_id, endpoint, p256dh, auth_key, device_label, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $endpoint, $p256dh, $auth, $label ?: null, $ua ?: null]
            );
            $id = $db->lastInsertId();
        }

        AuditService::log('push.subscribed', 'push_subscription', (string) $id, [
            'device_label' => $label,
        ], $userId);

        return $db->fetchOne(
            'SELECT id, device_label, user_agent, is_active, created_at
             FROM push_subscriptions WHERE id = ?',
            [$id]
        ) ?: ['id' => $id];
    }

    public static function unsubscribe(Database $db, int $userId, int $subscriptionId): void
    {
        $row = $db->fetchOne(
            'SELECT id FROM push_subscriptions WHERE id = ? AND user_id = ?',
            [$subscriptionId, $userId]
        );
        if (!$row) {
            Response::notFound('Subscription not found');
        }

        $db->execute(
            'UPDATE push_subscriptions SET is_active = 0 WHERE id = ?',
            [$subscriptionId]
        );

        AuditService::log('push.unsubscribed', 'push_subscription', (string) $subscriptionId, [], $userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activeSubscriptionsForUser(Database $db, int $userId): array
    {
        return $db->fetchAll(
            'SELECT id, endpoint, p256dh, auth_key
             FROM push_subscriptions
             WHERE user_id = ? AND is_active = 1',
            [$userId]
        );
    }

    public static function recordSuccess(Database $db, int $subscriptionId): void
    {
        $db->execute(
            'UPDATE push_subscriptions SET last_used_at = NOW(), failed_count = 0 WHERE id = ?',
            [$subscriptionId]
        );
    }

    public static function recordFailure(Database $db, int $subscriptionId, bool $gone = false): void
    {
        if ($gone) {
            $db->execute(
                'UPDATE push_subscriptions SET is_active = 0, failed_count = failed_count + 1 WHERE id = ?',
                [$subscriptionId]
            );

            return;
        }

        $db->execute(
            'UPDATE push_subscriptions
             SET failed_count = failed_count + 1,
                 is_active = IF(failed_count + 1 >= ?, 0, is_active)
             WHERE id = ?',
            [self::MAX_FAILURES, $subscriptionId]
        );
    }

    private static function labelFromUserAgent(string $ua): string
    {
        $browser = 'Browser';
        if (stripos($ua, 'Edg/') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'Chrome/') !== false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Firefox/') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'Safari/') !== false) {
            $browser = 'Safari';
        }

        $os = 'Device';
        if (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Mac OS') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = 'iOS';
        }

        return $browser . ' on ' . $os;
    }
}
