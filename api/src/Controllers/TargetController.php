<?php
/**
 * NexAlert - Target Controller
 *
 * POST /api/v1/targets/preview   → resolve expression and list recipients
 * GET  /api/v1/targets/entities  → autocomplete for builder (orgs, nodes, tags, groups, users)
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AuditService;
use NexAlert\Services\TargetExpressionService;

class TargetController
{
    public static function preview(Request $request): never
    {
        $db         = Database::getInstance();
        $expression = trim((string) $request->input('expression', ''));
        $structured = $request->input('targets');
        $tree       = $request->input('target_tree');
        $rows       = null;

        if (is_array($tree) && in_array($tree['type'] ?? '', ['group', 'target'], true)) {
            $result = TargetExpressionService::preview($db, null, null, $tree);
        } elseif (is_array($structured) && $structured !== []) {
            if (in_array($structured['type'] ?? '', ['group', 'target'], true)) {
                $result = TargetExpressionService::preview($db, null, null, $structured);
            } else {
                $rows   = TargetExpressionService::structuredToRows($structured);
                $result = TargetExpressionService::preview($db, $expression !== '' ? $expression : null, $rows);
            }
        } else {
            $result = TargetExpressionService::preview($db, $expression !== '' ? $expression : null, null);
        }

        if ($result['valid'] ?? false) {
            AuditService::log('target.preview', 'target', 'preview', [
                'expression'      => $result['expression'],
                'recipient_count' => $result['counts']['total_unique'] ?? 0,
            ], $request->user['uid']);
        }

        Response::success($result);
    }

    public static function entities(Request $request): never
    {
        $db    = Database::getInstance();
        $query = trim((string) $request->query('q', ''));
        $orgId = $request->query('org_id') !== null && $request->query('org_id') !== ''
            ? (int) $request->query('org_id')
            : null;
        $limit = min((int) $request->query('limit', 20), 50);

        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && $orgId === null) {
            $orgId = (int) $request->user['org'];
        }

        $entities = TargetExpressionService::searchEntities($db, $query, $orgId, $limit);

        Response::success($entities);
    }
}
