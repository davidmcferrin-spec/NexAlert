<?php
/**
 * NexAlert - Alert Controller
 *
 * POST   /api/v1/alerts              → create alert (JWT + alert.send)
 * POST   /api/v1/alert               → create alert (system token)
 * GET    /api/v1/alerts              → list alerts
 * GET    /api/v1/alerts/{id}         → alert detail + delivery stats
 * POST   /api/v1/alerts/{id}/ack     → acknowledge alert
 * POST   /api/v1/alerts/{id}/cancel  → cancel in-flight alert
 * POST   /api/v1/alerts/{id}/retry   → re-queue failed deliveries
 * DELETE /api/v1/alerts/{id}         → delete alert from history
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AlertService;
use NexAlert\Services\AuditService;
use NexAlert\Services\PermissionService;

class AlertController
{
    public static function create(Request $request): never
    {
        $result = AlertService::create($request);
        Response::success($result, 'Alert queued for delivery', 201);
    }

    public static function list(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = PermissionService::isSuperAdmin($request->user);
        $canViewAll   = $isSuperAdmin || PermissionService::hasPermission($db, $request->user, 'alert.view_all');

        $search   = trim((string) $request->query('search', ''));
        $status   = trim((string) $request->query('status', ''));
        $severity = trim((string) $request->query('severity', ''));
        $orgId    = $request->query('org_id') !== null && $request->query('org_id') !== ''
            ? (int) $request->query('org_id') : null;
        $limit    = min((int) $request->query('limit', 50), 200);
        $offset   = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        if (!$canViewAll) {
            $where[]  = 'a.org_id = ?';
            $params[] = $request->user['org'];
        } elseif ($orgId !== null) {
            $where[]  = 'a.org_id = ?';
            $params[] = $orgId;
        }

        if ($status !== '' && $status !== 'all') {
            $where[]  = 'a.status = ?';
            $params[] = $status;
        }

        if ($severity !== '' && $severity !== 'all') {
            $where[]  = 'a.severity = ?';
            $params[] = $severity;
        }

        if ($search !== '') {
            $where[] = '(a.subject LIKE ? OR a.body LIKE ? OR a.external_ref LIKE ?)';
            $like    = "%{$search}%";
            $params  = array_merge($params, [$like, $like, $like]);
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM alerts a WHERE {$whereStr}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT a.id, a.org_id, a.alert_type, a.severity, a.subject, a.status,
                    a.channels, a.external_ref, a.created_at, a.sent_at,
                    o.display_name AS org_name,
                    u.display_name AS created_by_name,
                    st.name AS created_by_token_name,
                    (SELECT COUNT(DISTINCT ad.user_id) FROM alert_deliveries ad WHERE ad.alert_id = a.id) AS recipient_count,
                    (SELECT COUNT(*) FROM alert_deliveries ad WHERE ad.alert_id = a.id AND ad.status = 'sent') AS sent_count,
                    (SELECT COUNT(*) FROM alert_deliveries ad WHERE ad.alert_id = a.id AND ad.status = 'failed') AS failed_count
             FROM alerts a
             JOIN organizations o ON o.id = a.org_id
             LEFT JOIN users u ON u.id = a.created_by_user
             LEFT JOIN system_tokens st ON st.id = a.created_by_token
             WHERE {$whereStr}
             ORDER BY a.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        foreach ($rows as &$row) {
            $row['channels'] = explode(',', (string) ($row['channels'] ?? ''));
            $row['created_by_name'] = $row['created_by_name'] ?? $row['created_by_token_name'];
            unset($row['created_by_token_name']);
        }
        unset($row);

        Response::success([
            'alerts' => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    public static function get(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertAlertAccess($request, $id, $db);

        $summary = AlertService::fetchAlertSummary($db, $id);

        $summary['deliveries'] = $db->fetchAll(
            'SELECT ad.id, ad.user_id, ad.channel, ad.status, ad.skip_reason,
                    ad.sent_at, ad.failed_at, ad.provider_message_id,
                    u.display_name AS user_name, u.username,
                    uc.contact_value
             FROM alert_deliveries ad
             JOIN users u ON u.id = ad.user_id
             JOIN user_contacts uc ON uc.id = ad.contact_id
             WHERE ad.alert_id = ?
             ORDER BY u.last_name, u.first_name, ad.channel',
            [$id]
        );

        $summary['acks'] = $db->fetchAll(
            'SELECT aa.user_id, aa.ack_channel, aa.ack_at, aa.notes, u.display_name AS user_name
             FROM alert_acks aa
             JOIN users u ON u.id = aa.user_id
             WHERE aa.alert_id = ?
             ORDER BY aa.ack_at ASC',
            [$id]
        );

        Response::success($summary);
    }

    public static function ack(Request $request): never
    {
        $alertId = (int) $request->param('id');
        $db      = Database::getInstance();
        $userId  = (int) $request->user['uid'];

        self::assertAlertAccess($request, $alertId, $db);

        $alert = $db->fetchOne(
            'SELECT id, ack_required, alert_type, status FROM alerts WHERE id = ?',
            [$alertId]
        );
        if (!$alert) {
            Response::notFound('Alert not found');
        }

        if (!(int) $alert['ack_required'] && $alert['alert_type'] !== 'ack_required') {
            Response::error('This alert does not require acknowledgement', 409);
        }

        $isRecipient = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM alert_deliveries WHERE alert_id = ? AND user_id = ?',
            [$alertId, $userId]
        ) > 0;

        if (!$isRecipient && !PermissionService::isSuperAdmin($request->user)) {
            Response::forbidden('You are not a recipient of this alert');
        }

        $notes = trim((string) $request->input('notes', ''));

        try {
            $db->execute(
                'INSERT INTO alert_acks (alert_id, user_id, ack_channel, notes)
                 VALUES (?, ?, ?, ?)',
                [$alertId, $userId, 'web', $notes !== '' ? $notes : null]
            );
        } catch (\Throwable) {
            Response::error('Already acknowledged', 409);
        }

        AuditService::log('alert.acked', 'alert', (string) $alertId, [
            'user_id' => $userId,
        ], $userId);

        Response::success(null, 'Acknowledgement recorded');
    }

    public static function cancel(Request $request): never
    {
        $alertId = (int) $request->param('id');
        $db      = Database::getInstance();

        self::assertAlertSendAccess($request, $alertId, $db);

        AlertService::cancel($alertId);

        AuditService::log('alert.cancelled', 'alert', (string) $alertId, [], (int) $request->user['uid']);

        Response::success(null, 'Alert cancelled');
    }

    public static function retry(Request $request): never
    {
        $alertId = (int) $request->param('id');
        $db      = Database::getInstance();

        self::assertAlertSendAccess($request, $alertId, $db);

        AlertService::retry($alertId);

        AuditService::log('alert.retried', 'alert', (string) $alertId, [], (int) $request->user['uid']);

        Response::success(null, 'Alert re-queued for delivery');
    }

    public static function delete(Request $request): never
    {
        $alertId = (int) $request->param('id');
        $db      = Database::getInstance();

        self::assertAlertDeleteAccess($request, $alertId, $db);

        AlertService::delete($alertId);

        AuditService::log('alert.deleted', 'alert', (string) $alertId, [], (int) $request->user['uid']);

        Response::success(null, 'Alert deleted');
    }

    private static function assertAlertSendAccess(Request $request, int $alertId, Database $db): void
    {
        if (!PermissionService::hasPermission($db, $request->user, 'alert.send')) {
            Response::forbidden('alert.send permission required');
        }

        self::assertAlertOrgAccess($request, $alertId, $db);
    }

    private static function assertAlertDeleteAccess(Request $request, int $alertId, Database $db): void
    {
        $isSuperAdmin = PermissionService::isSuperAdmin($request->user);
        $canManage    = PermissionService::hasPermission($db, $request->user, 'alert.manage');

        $alert = $db->fetchOne('SELECT org_id, severity FROM alerts WHERE id = ?', [$alertId]);
        if (!$alert) {
            Response::notFound('Alert not found');
        }

        $isTest = $alert['severity'] === 'test';
        $canSend = PermissionService::hasPermission($db, $request->user, 'alert.send');

        if (!$isSuperAdmin && !$canManage && !($isTest && $canSend)) {
            Response::forbidden('Insufficient permission to delete alerts');
        }

        if (!$isSuperAdmin && !PermissionService::hasPermission($db, $request->user, 'alert.view_all')) {
            if ((int) $request->user['org'] !== (int) $alert['org_id']) {
                Response::forbidden('Access denied');
            }
        }
    }

    private static function assertAlertOrgAccess(Request $request, int $alertId, Database $db): void
    {
        if (PermissionService::isSuperAdmin($request->user)) {
            return;
        }

        if (PermissionService::hasPermission($db, $request->user, 'alert.view_all')) {
            return;
        }

        $orgId = (int) $db->fetchValue('SELECT org_id FROM alerts WHERE id = ?', [$alertId]);
        if ($orgId === 0) {
            Response::notFound('Alert not found');
        }

        if ((int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied');
        }
    }

    private static function assertAlertAccess(Request $request, int $alertId, Database $db): void
    {
        if (PermissionService::isSuperAdmin($request->user)) {
            return;
        }

        if (PermissionService::hasPermission($db, $request->user, 'alert.view_all')) {
            return;
        }

        $orgId = (int) $db->fetchValue('SELECT org_id FROM alerts WHERE id = ?', [$alertId]);
        if ($orgId === 0) {
            Response::notFound('Alert not found');
        }

        if ((int) $request->user['org'] !== $orgId) {
            $isRecipient = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM alert_deliveries WHERE alert_id = ? AND user_id = ?',
                [$alertId, (int) $request->user['uid']]
            ) > 0;

            if (!$isRecipient) {
                Response::forbidden('Access denied');
            }
        }
    }
}
