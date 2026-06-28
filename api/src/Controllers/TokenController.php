<?php
/**
 * NexAlert - System Token Controller
 *
 * GET    /api/v1/tokens          → list tokens
 * POST   /api/v1/tokens          → create token (raw value returned once)
 * GET    /api/v1/tokens/{id}     → get token detail
 * PUT    /api/v1/tokens/{id}     → update token
 * DELETE /api/v1/tokens/{id}     → deactivate token
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AuditService;

class TokenController
{
    private const SEVERITIES   = ['test', 'info', 'notice', 'warning', 'critical', 'evacuation'];
    private const ALERT_TYPES  = ['simple', 'ack_required', 'poll', 'chat', 'group_chat'];

    public static function list(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        $active = $request->query('active', '1');
        $limit  = min((int) $request->query('limit', 50), 200);
        $offset = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        if (!$isSuperAdmin) {
            $where[]  = 't.owner_org_id = ?';
            $params[] = $request->user['org'];
        }

        if ($active !== 'all') {
            $where[]  = 't.is_active = ?';
            $params[] = $active === '0' ? 0 : 1;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM system_tokens t WHERE {$whereStr}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT t.id, t.name, t.owner_org_id, t.allowed_severity, t.allowed_alert_types,
                    t.ip_allowlist, t.is_active, t.last_used_at, t.last_used_ip,
                    t.expires_at, t.created_at,
                    o.name AS owner_org_name
             FROM system_tokens t
             JOIN organizations o ON o.id = t.owner_org_id
             WHERE {$whereStr}
             ORDER BY t.name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        Response::success([
            'tokens' => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    public static function create(Request $request): never
    {
        $missing = $request->validate(['name', 'owner_org_id']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $name           = trim((string) $request->input('name'));
        $ownerOrgId     = (int) $request->input('owner_org_id');
        $allowedSeverity = self::parseSetInput($request->input('allowed_severity'), self::SEVERITIES, self::SEVERITIES);
        $allowedTypes   = self::parseSetInput(
            $request->input('allowed_alert_types'),
            self::ALERT_TYPES,
            ['simple', 'ack_required']
        );
        $ipAllowlist    = trim((string) $request->input('ip_allowlist', '')) ?: null;
        $expiresAt      = trim((string) $request->input('expires_at', '')) ?: null;

        self::assertOrgAccess($request, $ownerOrgId);

        $db      = Database::getInstance();
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $id = $db->transaction(function (Database $db) use (
            $name, $ownerOrgId, $tokenHash, $allowedSeverity, $allowedTypes,
            $ipAllowlist, $expiresAt, $request
        ): int {
            $db->execute(
                'INSERT INTO system_tokens
                    (name, token_hash, owner_org_id, allowed_severity, allowed_alert_types,
                     ip_allowlist, expires_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $name, $tokenHash, $ownerOrgId,
                    implode(',', $allowedSeverity),
                    implode(',', $allowedTypes),
                    $ipAllowlist, $expiresAt, $request->user['uid'],
                ]
            );
            $id = $db->lastInsertId();

            AuditService::log('system_token.created', 'system_token', (string) $id, [
                'name'         => $name,
                'owner_org_id' => $ownerOrgId,
            ], $request->user['uid']);

            return $id;
        });

        $token = $db->fetchOne(
            'SELECT t.id, t.name, t.owner_org_id, t.allowed_severity, t.allowed_alert_types,
                    t.ip_allowlist, t.is_active, t.expires_at, t.created_at,
                    o.name AS owner_org_name
             FROM system_tokens t
             JOIN organizations o ON o.id = t.owner_org_id
             WHERE t.id = ?',
            [$id]
        );
        $token['raw_token'] = $rawToken;

        Response::success($token, 'Token created — copy the raw token now; it will not be shown again', 201);
    }

    public static function get(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertTokenAccess($request, $id, $db);

        $token = $db->fetchOne(
            'SELECT t.id, t.name, t.owner_org_id, t.allowed_severity, t.allowed_alert_types,
                    t.ip_allowlist, t.is_active, t.last_used_at, t.last_used_ip,
                    t.expires_at, t.created_at,
                    o.name AS owner_org_name
             FROM system_tokens t
             JOIN organizations o ON o.id = t.owner_org_id
             WHERE t.id = ?',
            [$id]
        );

        if (!$token) {
            Response::notFound('Token not found');
        }

        Response::success($token);
    }

    public static function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertTokenAccess($request, $id, $db);

        $token = $db->fetchOne('SELECT * FROM system_tokens WHERE id = ?', [$id]);
        if (!$token) {
            Response::notFound('Token not found');
        }

        $name = trim((string) $request->input('name', $token['name']));
        $allowedSeverity = $request->input('allowed_severity') !== null
            ? implode(',', self::parseSetInput($request->input('allowed_severity'), self::SEVERITIES, self::SEVERITIES))
            : $token['allowed_severity'];
        $allowedTypes = $request->input('allowed_alert_types') !== null
            ? implode(',', self::parseSetInput($request->input('allowed_alert_types'), self::ALERT_TYPES, self::ALERT_TYPES))
            : $token['allowed_alert_types'];
        $ipAllowlist = $request->input('ip_allowlist') !== null
            ? (trim((string) $request->input('ip_allowlist')) ?: null)
            : $token['ip_allowlist'];
        $expiresAt = $request->input('expires_at') !== null
            ? (trim((string) $request->input('expires_at')) ?: null)
            : $token['expires_at'];
        $isActive = $request->input('is_active') !== null
            ? ($request->input('is_active') ? 1 : 0)
            : (int) $token['is_active'];

        $db->execute(
            'UPDATE system_tokens
             SET name = ?, allowed_severity = ?, allowed_alert_types = ?,
                 ip_allowlist = ?, expires_at = ?, is_active = ?
             WHERE id = ?',
            [$name, $allowedSeverity, $allowedTypes, $ipAllowlist, $expiresAt, $isActive, $id]
        );

        AuditService::log('system_token.updated', 'system_token', (string) $id, [
            'name' => $name,
        ], $request->user['uid']);

        $updated = $db->fetchOne(
            'SELECT t.id, t.name, t.owner_org_id, t.allowed_severity, t.allowed_alert_types,
                    t.ip_allowlist, t.is_active, t.last_used_at, t.expires_at, t.created_at,
                    o.name AS owner_org_name
             FROM system_tokens t
             JOIN organizations o ON o.id = t.owner_org_id
             WHERE t.id = ?',
            [$id]
        );

        Response::success($updated, 'Token updated');
    }

    public static function delete(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertTokenAccess($request, $id, $db);

        $token = $db->fetchOne('SELECT id FROM system_tokens WHERE id = ?', [$id]);
        if (!$token) {
            Response::notFound('Token not found');
        }

        $db->execute('UPDATE system_tokens SET is_active = 0 WHERE id = ?', [$id]);

        AuditService::log('system_token.deactivated', 'system_token', (string) $id, [], $request->user['uid']);

        Response::success(null, 'Token deactivated');
    }

    /**
     * @param mixed $input array or comma-separated string
     * @param string[] $allowed
     * @param string[] $default
     * @return string[]
     */
    private static function parseSetInput(mixed $input, array $allowed, array $default): array
    {
        if ($input === null || $input === '') {
            return $default;
        }

        $values = is_array($input)
            ? $input
            : array_map('trim', explode(',', (string) $input));

        $filtered = array_values(array_intersect($values, $allowed));

        return $filtered ?: $default;
    }

    private static function assertOrgAccess(Request $request, int $orgId): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && (int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied to this organization');
        }
    }

    private static function assertTokenAccess(Request $request, int $tokenId, Database $db): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if ($isSuperAdmin) {
            return;
        }

        $token = $db->fetchOne('SELECT owner_org_id FROM system_tokens WHERE id = ?', [$tokenId]);
        if (!$token || (int) $token['owner_org_id'] !== (int) $request->user['org']) {
            Response::forbidden('Access denied to this token');
        }
    }
}
