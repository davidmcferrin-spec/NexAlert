<?php
/**
 * NexAlert - Job Queue Service
 * MySQL-backed queue for alert dispatch (Phase 2).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;

class JobQueueService
{
    public const QUEUE_DISPATCH = 'dispatch';

    /**
     * @param array<string, mixed> $payload
     */
    public static function push(string $type, array $payload, string $queue = self::QUEUE_DISPATCH): int
    {
        $db = Database::getInstance();
        $db->execute(
            'INSERT INTO jobs (queue, payload, status, available_at)
             VALUES (?, ?, \'pending\', UTC_TIMESTAMP())',
            [
                $queue,
                json_encode(['type' => $type, 'data' => $payload], JSON_THROW_ON_ERROR),
            ]
        );

        return $db->lastInsertId();
    }

    public static function pushAlertDispatch(int $alertId): int
    {
        return self::push('dispatch_alert', ['alert_id' => $alertId]);
    }
}
