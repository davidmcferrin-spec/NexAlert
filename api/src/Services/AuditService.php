<?php
/**
 * NexAlert - Audit Service
 * Thin wrapper around the audit_log table.
 * All writes are append-only. Never update or delete audit rows.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;
use NexAlert\Config\Logger;

class AuditService
{
    /**
     * Write an audit log entry.
     *
     * @param string      $action      e.g. 'user.created', 'alert.sent', 'auth.login_failed'
     * @param string|null $entityType  e.g. 'user', 'alert', 'tag'
     * @param string|null $entityId    String ID of the affected entity
     * @param array       $detail      Additional context (before/after, metadata)
     * @param int|null    $actorUserId Defaults to authenticated user if available via request context
     * @param int|null    $actorTokenId
     */
    public static function log(
        string  $action,
        ?string $entityType  = null,
        ?string $entityId    = null,
        array   $detail      = [],
        ?int    $actorUserId = null,
        ?int    $actorTokenId = null
    ): void {
        try {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['HTTP_X_REAL_IP']
                ?? $_SERVER['REMOTE_ADDR']
                ?? null;

            Database::getInstance()->execute(
                'INSERT INTO audit_log
                    (actor_user_id, actor_token_id, actor_ip, action, entity_type, entity_id, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $actorUserId,
                    $actorTokenId,
                    $ip,
                    $action,
                    $entityType,
                    $entityId,
                    !empty($detail) ? json_encode($detail) : null,
                ]
            );
        } catch (\Throwable $e) {
            // Audit log failure must never crash the main request
            Logger::error('AuditService write failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
