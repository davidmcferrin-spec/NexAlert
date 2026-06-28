<?php
/**
 * NexAlert - Permission Service
 * Loads and checks RBAC permissions from user_roles + role_permissions.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;

class PermissionService
{
    /**
     * @return list<string> Distinct permission names for an active user
     */
    public static function loadForUser(Database $db, int $userId): array
    {
        $rows = $db->fetchAll(
            'SELECT DISTINCT p.name
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             ORDER BY p.name',
            [$userId]
        );

        return array_column($rows, 'name');
    }

    /**
     * @param array<string, mixed> $user Request user payload (roles + optional permissions)
     */
    public static function isSuperAdmin(array $user): bool
    {
        return in_array('super_admin', $user['roles'] ?? [], true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function hasPermission(Database $db, array $user, string $permission): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        $cached = $user['permissions'] ?? [];
        if (in_array($permission, $cached, true)) {
            return true;
        }

        $granted = (int) $db->fetchValue(
            'SELECT COUNT(*)
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND p.name = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [(int) $user['uid'], $permission]
        );

        return $granted > 0;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function hasAnyPermission(Database $db, array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::hasPermission($db, $user, $permission)) {
                return true;
            }
        }

        return false;
    }
}
