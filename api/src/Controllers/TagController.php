<?php
/**
 * NexAlert - Tag Controller
 *
 * GET    /api/v1/tags                                    → list tags
 * POST   /api/v1/tags                                    → create tag
 * GET    /api/v1/tags/{id}                               → get tag detail
 * PUT    /api/v1/tags/{id}                               → update tag
 * DELETE /api/v1/tags/{id}                               → deactivate (soft) or ?hard=1&force=1 permanent delete
 * GET    /api/v1/tags/{id}/usage                         → usage counts before delete
 * GET    /api/v1/tags/{id}/requests                      → list approval requests
 * POST   /api/v1/tags/{id}/requests/{rid}/approve         → approve request
 * POST   /api/v1/tags/{id}/requests/{rid}/deny            → deny request
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AuditService;

class TagController
{
    public static function list(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        $search     = trim((string) $request->query('search', ''));
        $orgId      = $request->query('org_id') !== null && $request->query('org_id') !== ''
            ? (int) $request->query('org_id') : null;
        $active     = $request->query('active', '1');
        $system     = $request->query('system', 'all');
        $assignable = $request->query('assignable') === '1';
        $limit      = min((int) $request->query('limit', 50), 200);
        $offset     = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        if (!$isSuperAdmin) {
            $where[]  = '(t.owner_org_id IS NULL OR t.owner_org_id = ? OR t.created_by = ?)';
            $params[] = $request->user['org'];
            $params[] = $request->user['uid'];
        } elseif ($orgId !== null) {
            $where[]  = '(t.owner_org_id IS NULL OR t.owner_org_id = ?)';
            $params[] = $orgId;
        }

        if ($assignable) {
            $where[] = 't.is_system = 0';
            $where[] = 't.is_active = 1';
        } elseif ($active !== 'all') {
            $where[]  = 't.is_active = ?';
            $params[] = $active === '0' ? 0 : 1;
        }

        if ($system === '1') {
            $where[] = 't.is_system = 1';
        } elseif ($system === '0') {
            $where[] = 't.is_system = 0';
        }

        if ($search !== '') {
            $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.description LIKE ?)';
            $like    = "%{$search}%";
            $params  = array_merge($params, [$like, $like, $like]);
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tags t WHERE {$whereStr}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT t.id, t.name, t.slug, t.description, t.owner_org_id,
                    t.is_exclusive, t.tag_admin_id, t.allow_self_request, t.requires_approval,
                    t.is_active, t.is_system, t.created_at, t.updated_at,
                    o.display_name AS owner_org_name,
                    (SELECT COUNT(*) FROM tag_assignments ta
                     WHERE ta.tag_id = t.id AND ta.is_active = 1) AS assignment_count,
                    (SELECT COUNT(*) FROM tag_approval_requests tar
                     WHERE tar.tag_id = t.id AND tar.status = 'pending') AS pending_request_count
             FROM tags t
             LEFT JOIN organizations o ON o.id = t.owner_org_id
             WHERE {$whereStr}
             ORDER BY t.is_system DESC, t.name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $rows = array_map([self::class, 'normalizeTagRow'], $rows);

        Response::success([
            'tags'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    public static function create(Request $request): never
    {
        $missing = $request->validate(['name']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $name               = trim((string) $request->input('name'));
        $slug               = trim((string) $request->input('slug', ''));
        $description        = trim((string) $request->input('description', '')) ?: null;
        $ownerOrgId         = $request->input('owner_org_id') !== null && $request->input('owner_org_id') !== ''
            ? (int) $request->input('owner_org_id') : null;
        $isExclusive        = (bool) $request->input('is_exclusive', false);
        $tagAdminId         = $request->input('tag_admin_id') ? (int) $request->input('tag_admin_id') : null;
        $allowSelfRequest   = (bool) $request->input('allow_self_request', true);
        $requiresApproval   = (bool) $request->input('requires_approval', true);

        if ($ownerOrgId !== null) {
            self::assertOrgAccess($request, $ownerOrgId);
        }

        self::assertTagManage($request);

        if ($isExclusive) {
            self::assertExclusivePermission($request);
        }

        if ($slug === '') {
            $slug = self::slugify($name);
        } else {
            $slug = strtolower($slug);
        }

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            Response::validationError(['slug' => 'Only lowercase letters, numbers, hyphens, and underscores allowed']);
        }

        $db = Database::getInstance();

        $existing = $db->fetchOne(
            'SELECT id, name, is_active, is_system FROM tags WHERE slug = ?',
            [$slug]
        );

        if ($existing) {
            if ((int) $existing['is_system'] === 1) {
                Response::validationError([
                    'slug' => 'Slug already used by system tag "' . $existing['name']
                        . '" (auto-created from the org tree). Filter by System on the Tags page, or choose a different slug.',
                ]);
            }

            if ((int) $existing['is_active'] === 0) {
                $db->execute(
                    'UPDATE tags SET name = ?, description = ?, owner_org_id = ?, is_exclusive = ?,
                                     tag_admin_id = ?, allow_self_request = ?, requires_approval = ?,
                                     is_active = 1
                     WHERE id = ?',
                    [
                        $name, $description, $ownerOrgId,
                        $isExclusive ? 1 : 0, $tagAdminId,
                        $allowSelfRequest ? 1 : 0, $requiresApproval ? 1 : 0,
                        $existing['id'],
                    ]
                );

                AuditService::log('tag.reactivated', 'tag', (string) $existing['id'], [
                    'name' => $name,
                    'slug' => $slug,
                ], $request->user['uid']);

                Response::success(self::fetchTagDetail($db, (int) $existing['id']), 'Tag reactivated', 200);
            }

            Response::validationError([
                'slug' => 'Slug already used by active tag "' . $existing['name']
                    . '". Edit that tag instead of creating a duplicate.',
            ]);
        }

        $id = $db->transaction(function (Database $db) use (
            $name, $slug, $description, $ownerOrgId, $isExclusive, $tagAdminId,
            $allowSelfRequest, $requiresApproval, $request
        ): int {
            $db->execute(
                'INSERT INTO tags (name, slug, description, owner_org_id, is_exclusive, tag_admin_id,
                                   allow_self_request, requires_approval, is_active, is_system, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)',
                [
                    $name, $slug, $description, $ownerOrgId,
                    $isExclusive ? 1 : 0, $tagAdminId,
                    $allowSelfRequest ? 1 : 0, $requiresApproval ? 1 : 0,
                    $request->user['uid'],
                ]
            );
            $id = $db->lastInsertId();

            AuditService::log('tag.created', 'tag', (string) $id, [
                'name' => $name,
                'slug' => $slug,
            ], $request->user['uid']);

            return $id;
        });

        $tag = self::fetchTagDetail($db, $id);
        Response::success($tag, 'Tag created', 201);
    }

    public static function get(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertTagAccess($request, $id, $db);

        $tag = self::fetchTagDetail($db, $id);
        if (!$tag) {
            Response::notFound('Tag not found');
        }

        Response::success($tag);
    }

    public static function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertTagAccess($request, $id, $db);
        self::assertTagManage($request);

        $tag = $db->fetchOne('SELECT * FROM tags WHERE id = ?', [$id]);
        if (!$tag) {
            Response::notFound('Tag not found');
        }

        if ((int) $tag['is_system'] === 1) {
            Response::error('System tags generated from the org tree cannot be edited', 409);
        }

        $name             = trim((string) $request->input('name', $tag['name']));
        $description      = $request->input('description') !== null
            ? (trim((string) $request->input('description')) ?: null)
            : $tag['description'];
        $isExclusive      = $request->input('is_exclusive') !== null
            ? (bool) $request->input('is_exclusive')
            : (bool) $tag['is_exclusive'];
        $tagAdminId       = $request->input('tag_admin_id') !== null
            ? ($request->input('tag_admin_id') ? (int) $request->input('tag_admin_id') : null)
            : ($tag['tag_admin_id'] ? (int) $tag['tag_admin_id'] : null);
        $allowSelfRequest = $request->input('allow_self_request') !== null
            ? (bool) $request->input('allow_self_request')
            : (bool) $tag['allow_self_request'];
        $requiresApproval = $request->input('requires_approval') !== null
            ? (bool) $request->input('requires_approval')
            : (bool) $tag['requires_approval'];

        if ($isExclusive && !(bool) $tag['is_exclusive']) {
            self::assertExclusivePermission($request);
        }

        $sets    = ['name = ?', 'description = ?', 'is_exclusive = ?', 'tag_admin_id = ?',
                    'allow_self_request = ?', 'requires_approval = ?'];
        $params  = [
            $name, $description,
            $isExclusive ? 1 : 0, $tagAdminId,
            $allowSelfRequest ? 1 : 0, $requiresApproval ? 1 : 0,
        ];

        if ($request->input('is_active') !== null) {
            $sets[]   = 'is_active = ?';
            $params[] = (bool) $request->input('is_active') ? 1 : 0;
        }

        $params[] = $id;

        $db->execute(
            'UPDATE tags SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        AuditService::log('tag.updated', 'tag', (string) $id, [
            'before' => ['name' => $tag['name']],
            'after'  => ['name' => $name],
        ], $request->user['uid']);

        Response::success(self::fetchTagDetail($db, $id), 'Tag updated');
    }

    public static function usage(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertTagAccess($request, $id, $db);

        $tag = $db->fetchOne('SELECT id, name FROM tags WHERE id = ?', [$id]);
        if (!$tag) {
            Response::notFound('Tag not found');
        }

        Response::success(self::tagUsage($db, $id));
    }

    public static function delete(Request $request): never
    {
        $id    = (int) $request->param('id');
        $hard  = $request->query('hard') === '1';
        $force = $request->query('force') === '1';
        $db    = Database::getInstance();

        self::assertTagAccess($request, $id, $db);
        self::assertTagManage($request);

        $tag = $db->fetchOne('SELECT id, name, is_system, is_active FROM tags WHERE id = ?', [$id]);
        if (!$tag) {
            Response::notFound('Tag not found');
        }

        if ((int) $tag['is_system'] === 1) {
            Response::error($hard ? 'System tags cannot be deleted' : 'System tags cannot be deactivated', 409);
        }

        if (!$hard) {
            $db->execute('UPDATE tags SET is_active = 0 WHERE id = ?', [$id]);
            AuditService::log('tag.deactivated', 'tag', (string) $id, [], $request->user['uid']);
            Response::success(null, 'Tag deactivated');
        }

        $usage = self::tagUsage($db, $id);
        if ($usage['total'] > 0 && !$force) {
            Response::json([
                'success' => false,
                'error'   => 'Tag is in use',
                'usage'   => $usage,
            ], 409);
        }

        $db->transaction(function (Database $db) use ($id): void {
            $db->execute('UPDATE tag_assignments SET is_active = 0 WHERE tag_id = ?', [$id]);
            $db->execute('DELETE FROM tags WHERE id = ?', [$id]);
        });

        AuditService::log('tag.deleted', 'tag', (string) $id, [
            'name'  => $tag['name'],
            'force' => $force,
            'usage' => $usage,
        ], $request->user['uid']);

        Response::success(null, 'Tag permanently deleted');
    }

    public static function listRequests(Request $request): never
    {
        $id     = (int) $request->param('id');
        $db     = Database::getInstance();
        $status = (string) $request->query('status', 'pending');

        self::assertTagAccess($request, $id, $db);

        $where  = ['tar.tag_id = ?'];
        $params = [$id];

        if ($status !== 'all') {
            $where[]  = 'tar.status = ?';
            $params[] = $status;
        }

        $whereStr = implode(' AND ', $where);

        $requests = $db->fetchAll(
            "SELECT tar.id, tar.tag_id, tar.user_id, tar.requested_by, tar.justification,
                    tar.status, tar.reviewed_by, tar.reviewed_at, tar.review_notes, tar.created_at,
                    u.display_name AS user_display_name, u.username AS user_username,
                    rb.display_name AS requested_by_name,
                    rv.display_name AS reviewed_by_name
             FROM tag_approval_requests tar
             JOIN users u ON u.id = tar.user_id
             JOIN users rb ON rb.id = tar.requested_by
             LEFT JOIN users rv ON rv.id = tar.reviewed_by
             WHERE {$whereStr}
             ORDER BY tar.created_at DESC",
            $params
        );

        Response::success(['requests' => $requests]);
    }

    public static function approveRequest(Request $request): never
    {
        self::reviewRequest($request, 'approved');
    }

    public static function denyRequest(Request $request): never
    {
        self::reviewRequest($request, 'denied');
    }

    // -----------------------------------------------------------------------

    private static function reviewRequest(Request $request, string $decision): never
    {
        $tagId = (int) $request->param('id');
        $rid   = (int) $request->param('rid');
        $db    = Database::getInstance();

        self::assertTagAccess($request, $tagId, $db);
        self::assertApprovePermission($request, $tagId, $db);

        $req = $db->fetchOne(
            'SELECT * FROM tag_approval_requests WHERE id = ? AND tag_id = ?',
            [$rid, $tagId]
        );
        if (!$req) {
            Response::notFound('Request not found');
        }
        if ($req['status'] !== 'pending') {
            Response::error('Request has already been reviewed', 409);
        }

        $notes = trim((string) $request->input('review_notes', '')) ?: null;

        $db->transaction(function (Database $db) use ($req, $tagId, $rid, $decision, $notes, $request): void {
            $db->execute(
                'UPDATE tag_approval_requests
                 SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                 WHERE id = ?',
                [$decision, $request->user['uid'], $notes, $rid]
            );

            if ($decision === 'approved') {
                $existing = $db->fetchValue(
                    'SELECT id FROM tag_assignments WHERE tag_id = ? AND user_id = ? AND is_active = 1',
                    [$tagId, $req['user_id']]
                );
                if (!$existing) {
                    $db->execute(
                        "INSERT INTO tag_assignments (tag_id, user_id, assignment_type, assigned_by, assigned_at)
                         VALUES (?, ?, 'approved_request', ?, NOW())",
                        [$tagId, $req['user_id'], $request->user['uid']]
                    );
                }
            }

            AuditService::log(
                $decision === 'approved' ? 'tag.request_approved' : 'tag.request_denied',
                'tag',
                (string) $tagId,
                ['request_id' => $rid, 'user_id' => $req['user_id']],
                $request->user['uid']
            );
        });

        Response::success(null, $decision === 'approved' ? 'Request approved' : 'Request denied');
    }

    private static function fetchTagDetail(Database $db, int $id): ?array
    {
        $tag = $db->fetchOne(
            'SELECT t.*, o.display_name AS owner_org_name,
                    ua.display_name AS tag_admin_name
             FROM tags t
             LEFT JOIN organizations o ON o.id = t.owner_org_id
             LEFT JOIN users ua ON ua.id = t.tag_admin_id
             WHERE t.id = ?',
            [$id]
        );
        if (!$tag) {
            return null;
        }

        $tag['assignment_count'] = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tag_assignments WHERE tag_id = ? AND is_active = 1',
            [$id]
        );
        $tag['pending_request_count'] = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tag_approval_requests WHERE tag_id = ? AND status = 'pending'",
            [$id]
        );

        return self::normalizeTagRow($tag);
    }

    /**
     * @return array{assignments: int, alert_targets: int, pending_requests: int, total: int}
     */
    private static function tagUsage(Database $db, int $tagId): array
    {
        $assignments = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM tag_assignments WHERE tag_id = ? AND is_active = 1',
            [$tagId]
        );
        $alertTargets = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM alert_targets WHERE target_tag_id = ?',
            [$tagId]
        );
        $pendingRequests = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM tag_approval_requests WHERE tag_id = ? AND status = 'pending'",
            [$tagId]
        );

        return [
            'assignments'      => $assignments,
            'alert_targets'    => $alertTargets,
            'pending_requests' => $pendingRequests,
            'total'            => $assignments + $alertTargets + $pendingRequests,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function normalizeTagRow(array $row): array
    {
        foreach (['is_active', 'is_system', 'is_exclusive', 'allow_self_request', 'requires_approval'] as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = (int) $row[$col];
            }
        }
        if (isset($row['assignment_count'])) {
            $row['assignment_count'] = (int) $row['assignment_count'];
        }
        if (isset($row['pending_request_count'])) {
            $row['pending_request_count'] = (int) $row['pending_request_count'];
        }

        return $row;
    }

    private static function assertTagManage(Request $request): void
    {
        if (in_array('super_admin', $request->user['roles'] ?? [], true)) {
            return;
        }

        $db = Database::getInstance();
        $granted = (int) $db->fetchValue(
            'SELECT COUNT(*)
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND p.name IN (\'tag.manage\', \'tag.manage_exclusive\')
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [$request->user['uid']]
        );

        if ($granted > 0) {
            return;
        }

        Response::forbidden('Tag management permission required');
    }

    private static function assertTagAccess(Request $request, int $tagId, Database $db): void
    {
        if (in_array('super_admin', $request->user['roles'] ?? [], true)) {
            return;
        }

        $tag = $db->fetchOne('SELECT owner_org_id, created_by FROM tags WHERE id = ?', [$tagId]);
        if (!$tag) {
            Response::notFound('Tag not found');
        }

        if ((int) ($tag['created_by'] ?? 0) === (int) $request->user['uid']) {
            return;
        }

        $ownerOrgId = $tag['owner_org_id'] !== null ? (int) $tag['owner_org_id'] : null;
        if ($ownerOrgId === null || $ownerOrgId === (int) $request->user['org']) {
            return;
        }

        Response::forbidden('Access denied to this tag');
    }

    private static function assertOrgAccess(Request $request, int $orgId): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && (int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied to this organization');
        }
    }

    private static function assertExclusivePermission(Request $request): void
    {
        if (in_array('super_admin', $request->user['roles'] ?? [], true)) {
            return;
        }

        $perms = $request->user['permissions'] ?? [];
        if (!in_array('tag.manage_exclusive', $perms, true)) {
            Response::forbidden('Exclusive tag management requires tag.manage_exclusive permission');
        }
    }

    private static function assertApprovePermission(Request $request, int $tagId, Database $db): void
    {
        if (in_array('super_admin', $request->user['roles'] ?? [], true)) {
            return;
        }

        $perms = $request->user['permissions'] ?? [];
        if (in_array('tag.approve_requests', $perms, true)) {
            return;
        }

        $tag = $db->fetchOne('SELECT tag_admin_id FROM tags WHERE id = ?', [$tagId]);
        if ($tag && (int) ($tag['tag_admin_id'] ?? 0) === $request->user['uid']) {
            return;
        }

        Response::forbidden('Tag approval permission required');
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s_-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', trim($slug));
        return substr($slug, 0, 100);
    }
}
