<?php
/**
 * NexAlert - Organization Controller
 *
 * GET    /api/v1/orgs          → list (super_admin sees all; org_admin sees own)
 * POST   /api/v1/orgs          → create (super_admin only)
 * GET    /api/v1/orgs/{id}     → get one
 * PUT    /api/v1/orgs/{id}     → update
 * DELETE /api/v1/orgs/{id}     → deactivate (soft delete)
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AuditService;
use NexAlert\Services\RowNormalizer;

class OrgController
{
    /**
     * GET /api/v1/orgs
     * super_admin: all orgs
     * org_admin:   their home org only
     */
    public static function list(Request $request): never
    {
        $db        = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        $search  = trim((string) $request->query('search', ''));
        $active  = $request->query('active', '1');
        $limit   = min((int) $request->query('limit', 50), 200);
        $offset  = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        if (!$isSuperAdmin) {
            $where[]  = 'o.id = ?';
            $params[] = $request->user['org'];
        }

        if ($search !== '') {
            $where[]  = '(o.name LIKE ? OR o.slug LIKE ? OR o.display_name LIKE ?)';
            $like     = "%{$search}%";
            $params   = array_merge($params, [$like, $like, $like]);
        }

        if ($active !== 'all') {
            $where[]  = 'o.is_active = ?';
            $params[] = $active === '0' ? 0 : 1;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM organizations o WHERE {$whereStr}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT o.id, o.name, o.slug, o.display_name, o.logo_url,
                    o.primary_color, o.is_active, o.created_at, o.updated_at,
                    (SELECT COUNT(*) FROM users u
                     WHERE u.home_org_id = o.id AND u.is_active = 1) AS user_count,
                    (SELECT COUNT(*) FROM org_nodes n
                     WHERE n.org_id = o.id AND n.is_active = 1) AS node_count
             FROM organizations o
             WHERE {$whereStr}
             ORDER BY o.display_name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $rows = RowNormalizer::mapFlags($rows, ['is_active', 'user_count', 'node_count']);

        Response::success([
            'orgs'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * POST /api/v1/orgs
     */
    public static function create(Request $request): never
    {
        $missing = $request->validate(['name', 'slug', 'display_name']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $name        = trim((string) $request->input('name'));
        $slug        = strtolower(trim((string) $request->input('slug')));
        $displayName = trim((string) $request->input('display_name'));
        $logoUrl     = trim((string) $request->input('logo_url', '')) ?: null;
        $color       = trim((string) $request->input('primary_color', '')) ?: null;

        // Validate slug format
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            Response::validationError(['slug' => 'Only lowercase letters, numbers, hyphens, and underscores allowed']);
        }

        // Validate hex color if provided
        if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            Response::validationError(['primary_color' => 'Must be a valid hex color e.g. #1a2b3c']);
        }

        $db = Database::getInstance();

        // Check slug uniqueness
        if ($db->fetchValue('SELECT id FROM organizations WHERE slug = ?', [$slug])) {
            Response::validationError(['slug' => 'Slug already in use']);
        }

        $id = $db->transaction(function (Database $db) use ($name, $slug, $displayName, $logoUrl, $color, $request): int {
            $db->execute(
                'INSERT INTO organizations (name, slug, display_name, logo_url, primary_color)
                 VALUES (?, ?, ?, ?, ?)',
                [$name, $slug, $displayName, $logoUrl, $color]
            );
            $id = $db->lastInsertId();

            // Auto-create the root org_node for this organization
            $db->execute(
                "INSERT INTO org_nodes (org_id, parent_id, node_type, name, slug, path, depth)
                 VALUES (?, NULL, 'org', ?, ?, ?, 0)",
                [$id, $name, $slug, "/{$id}/"]
            );

            AuditService::log('org.created', 'org', (string) $id, [
                'name' => $name,
                'slug' => $slug,
            ], $request->user['uid']);

            return $id;
        });

        $org = $db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]);

        Response::success($org, 'Organization created', 201);
    }

    /**
     * GET /api/v1/orgs/{id}
     */
    public static function get(Request $request): never
    {
        $id  = (int) $request->param('id');
        $db  = Database::getInstance();

        self::assertAccess($request, $id);

        $org = $db->fetchOne(
            'SELECT o.*,
                    (SELECT COUNT(*) FROM users u
                     WHERE u.home_org_id = o.id AND u.is_active = 1) AS user_count,
                    (SELECT COUNT(*) FROM org_nodes n
                     WHERE n.org_id = o.id AND n.is_active = 1) AS node_count
             FROM organizations o
             WHERE o.id = ?',
            [$id]
        );

        if (!$org) {
            Response::notFound('Organization not found');
        }

        $org = RowNormalizer::flags($org, ['is_active', 'user_count', 'node_count']);

        // Include root nodes
        $org['nodes'] = RowNormalizer::mapFlags(
            $db->fetchAll(
                'SELECT id, parent_id, node_type, name, slug, path, depth, is_active
                 FROM org_nodes WHERE org_id = ? ORDER BY path ASC',
                [$id]
            ),
            ['is_active']
        );

        Response::success($org);
    }

    /**
     * PUT /api/v1/orgs/{id}
     */
    public static function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertAccess($request, $id);

        $org = $db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$org) {
            Response::notFound('Organization not found');
        }

        $displayName = trim((string) $request->input('display_name', $org['display_name']));
        $logoUrl     = $request->input('logo_url') !== null
            ? (trim((string) $request->input('logo_url')) ?: null)
            : $org['logo_url'];
        $color       = $request->input('primary_color') !== null
            ? (trim((string) $request->input('primary_color')) ?: null)
            : $org['primary_color'];
        $name        = trim((string) $request->input('name', $org['name']));

        if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            Response::validationError(['primary_color' => 'Must be a valid hex color e.g. #1a2b3c']);
        }

        $db->execute(
            'UPDATE organizations SET name = ?, display_name = ?, logo_url = ?, primary_color = ? WHERE id = ?',
            [$name, $displayName, $logoUrl, $color, $id]
        );

        AuditService::log('org.updated', 'org', (string) $id, [
            'before' => ['name' => $org['name'], 'display_name' => $org['display_name']],
            'after'  => ['name' => $name, 'display_name' => $displayName],
        ], $request->user['uid']);

        $updated = $db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]);
        Response::success($updated, 'Organization updated');
    }

    /**
     * DELETE /api/v1/orgs/{id}
     * Soft delete — sets is_active = 0. Refuses if org has active users.
     */
    public static function delete(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertAccess($request, $id);

        $org = $db->fetchOne('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$org) {
            Response::notFound('Organization not found');
        }

        // Refuse if active users still belong to this org
        $activeUsers = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM users WHERE home_org_id = ? AND is_active = 1',
            [$id]
        );
        if ($activeUsers > 0) {
            Response::error("Cannot deactivate: {$activeUsers} active user(s) still assigned to this organization.", 409);
        }

        $db->execute('UPDATE organizations SET is_active = 0 WHERE id = ?', [$id]);

        AuditService::log('org.deactivated', 'org', (string) $id, [], $request->user['uid']);

        Response::success(null, 'Organization deactivated');
    }

    /**
     * Assert the requesting user can access/modify this org.
     * super_admin: any org. org_admin/others: home org only.
     */
    private static function assertAccess(Request $request, int $orgId): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && (int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied to this organization');
        }
    }
}
