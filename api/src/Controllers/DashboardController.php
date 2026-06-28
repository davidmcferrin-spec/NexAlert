<?php
/**
 * NexAlert - Dashboard Controller
 *
 * GET /api/v1/dashboard/stats → aggregate counts for admin dashboard
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;

class DashboardController
{
    public static function stats(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        $orgId        = (int) $request->user['org'];

        if ($isSuperAdmin) {
            $orgs = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM organizations WHERE is_active = 1'
            );
            $users = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM users WHERE is_active = 1'
            );
            $alertsSent = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alerts WHERE status IN ('sent', 'sending')"
            );
            $tokens = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM system_tokens WHERE is_active = 1'
            );
            $pendingJobs = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM jobs WHERE status = 'pending' AND queue = 'dispatch'"
            );
        } else {
            $orgs = 1;
            $users = (int) $db->fetchValue(
                'SELECT COUNT(DISTINCT u.id)
                 FROM users u
                 JOIN user_org_memberships m ON m.user_id = u.id
                 WHERE u.is_active = 1 AND m.org_id = ?',
                [$orgId]
            );
            $alertsSent = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alerts WHERE org_id = ? AND status IN ('sent', 'sending')",
                [$orgId]
            );
            $tokens = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM system_tokens WHERE owner_org_id = ? AND is_active = 1',
                [$orgId]
            );
            $pendingJobs = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM alerts WHERE org_id = ? AND status = 'sending'",
                [$orgId]
            );
        }

        Response::success([
            'orgs'         => $orgs,
            'users'        => $users,
            'alerts_sent'  => $alertsSent,
            'tokens'       => $tokens,
            'pending_jobs' => $pendingJobs,
        ]);
    }
}
