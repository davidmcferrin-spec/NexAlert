<?php
/**
 * NexAlert - Role Controller
 *
 * GET /api/v1/roles → list assignable system roles
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\PermissionService;

class RoleController
{
    public static function list(Request $request): never
    {
        if (!PermissionService::isSuperAdmin($request->user)
            && !PermissionService::hasPermission(Database::getInstance(), $request->user, 'user.manage_roles')) {
            Response::forbidden('Role management permission required');
        }

        $db = Database::getInstance();

        $roles = $db->fetchAll(
            'SELECT r.id, r.name, r.display_name, r.description, r.is_system,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR \',\') AS permissions
             FROM roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             GROUP BY r.id, r.name, r.display_name, r.description, r.is_system
             ORDER BY r.name'
        );

        foreach ($roles as &$role) {
            $role['is_system'] = (int) $role['is_system'];
            $role['permissions'] = $role['permissions'] !== null && $role['permissions'] !== ''
                ? explode(',', (string) $role['permissions'])
                : [];
        }
        unset($role);

        Response::success(['roles' => $roles]);
    }
}
