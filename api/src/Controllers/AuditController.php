<?php
/**
 * NexAlert - Audit Log Controller (read-only)
 *
 * GET /api/v1/audit → list audit log entries
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;

class AuditController
{
    public static function list(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        $action     = trim((string) $request->query('action', ''));
        $entityType = trim((string) $request->query('entity_type', ''));
        $search     = trim((string) $request->query('search', ''));
        $limit      = min((int) $request->query('limit', 50), 200);
        $offset     = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        if (!$isSuperAdmin) {
            $where[] = '(
                a.actor_user_id IN (SELECT id FROM users WHERE home_org_id = ?)
                OR JSON_UNQUOTE(JSON_EXTRACT(a.detail, "$.org_id")) = ?
                OR JSON_UNQUOTE(JSON_EXTRACT(a.detail, "$.home_org_id")) = ?
            )';
            $orgId   = (string) $request->user['org'];
            $params  = array_merge($params, [$request->user['org'], $orgId, $orgId]);
        }

        if ($action !== '') {
            $where[]  = 'a.action LIKE ?';
            $params[] = $action . '%';
        }

        if ($entityType !== '') {
            $where[]  = 'a.entity_type = ?';
            $params[] = $entityType;
        }

        if ($search !== '') {
            $where[]  = '(a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?)';
            $like     = "%{$search}%";
            $params   = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*)
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE {$whereStr}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT a.id, a.action, a.entity_type, a.entity_id, a.detail,
                    a.actor_user_id, a.actor_token_id, a.actor_ip, a.created_at,
                    u.display_name AS actor_name, u.username AS actor_username
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE {$whereStr}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        foreach ($rows as &$row) {
            if (is_string($row['detail'])) {
                $row['detail'] = json_decode($row['detail'], true);
            }
        }
        unset($row);

        Response::success([
            'entries' => $rows,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
    }
}
