<?php
/**
 * NexAlert - Group Controller
 *
 * GET    /api/v1/groups                              → list groups
 * POST   /api/v1/groups                              → create group
 * GET    /api/v1/groups/{id}                         → get group detail
 * PUT    /api/v1/groups/{id}                         → update group
 * DELETE /api/v1/groups/{id}                         → deactivate group
 * POST   /api/v1/groups/{id}/members                 → add user member
 * DELETE /api/v1/groups/{id}/members/{user_id}       → remove member
 * POST   /api/v1/groups/{id}/children                → add child group
 * DELETE /api/v1/groups/{id}/children/{child_id}     → remove child group
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AuditService;

class GroupController
{
    public static function list(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        $search  = trim((string) $request->query('search', ''));
        $orgId   = $request->query('org_id') ? (int) $request->query('org_id') : null;
        $active  = $request->query('active', '1');
        $limit   = min((int) $request->query('limit', 50), 200);
        $offset  = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        if (!$isSuperAdmin) {
            $where[]  = 'g.owner_org_id = ?';
            $params[] = $request->user['org'];
        } elseif ($orgId) {
            $where[]  = 'g.owner_org_id = ?';
            $params[] = $orgId;
        }

        if ($active !== 'all') {
            $where[]  = 'g.is_active = ?';
            $params[] = $active === '0' ? 0 : 1;
        }

        if ($search !== '') {
            $where[] = '(g.name LIKE ? OR g.slug LIKE ? OR g.description LIKE ?)';
            $like    = "%{$search}%";
            $params  = array_merge($params, [$like, $like, $like]);
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM `groups` g WHERE {$whereStr}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT g.id, g.owner_org_id, g.name, g.slug, g.description, g.is_active,
                    g.created_at, g.updated_at,
                    o.display_name AS owner_org_name,
                    (SELECT COUNT(*) FROM group_memberships gm
                     WHERE gm.group_id = g.id AND gm.is_active = 1) AS member_count,
                    (SELECT COUNT(*) FROM group_children gc
                     WHERE gc.parent_group_id = g.id) AS child_group_count
             FROM `groups` g
             LEFT JOIN organizations o ON o.id = g.owner_org_id
             WHERE {$whereStr}
             ORDER BY g.name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $rows = array_map([self::class, 'normalizeGroupRow'], $rows);

        Response::success([
            'groups' => $rows,
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

        $name        = trim((string) $request->input('name'));
        $ownerOrgId  = (int) $request->input('owner_org_id');
        $slug        = trim((string) $request->input('slug', ''));
        $description = trim((string) $request->input('description', '')) ?: null;

        self::assertOrgAccess($request, $ownerOrgId);

        if ($slug === '') {
            $slug = self::slugify($name);
        } else {
            $slug = strtolower($slug);
        }

        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            Response::validationError(['slug' => 'Only lowercase letters, numbers, hyphens, and underscores allowed']);
        }

        $db = Database::getInstance();

        if ($db->fetchValue(
            'SELECT id FROM `groups` WHERE owner_org_id = ? AND slug = ?',
            [$ownerOrgId, $slug]
        )) {
            Response::validationError(['slug' => 'Slug already in use for this organization']);
        }

        $id = $db->transaction(function (Database $db) use ($name, $slug, $description, $ownerOrgId, $request): int {
            $db->execute(
                'INSERT INTO `groups` (owner_org_id, name, slug, description, created_by)
                 VALUES (?, ?, ?, ?, ?)',
                [$ownerOrgId, $name, $slug, $description, $request->user['uid']]
            );
            $id = $db->lastInsertId();

            AuditService::log('group.created', 'group', (string) $id, [
                'name'         => $name,
                'slug'         => $slug,
                'owner_org_id' => $ownerOrgId,
            ], $request->user['uid']);

            return $id;
        });

        $group = self::fetchGroupDetail($db, $id);
        Response::success($group, 'Group created', 201);
    }

    public static function get(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertGroupAccess($request, $id, $db);

        $group = self::fetchGroupDetail($db, $id);
        if (!$group) {
            Response::notFound('Group not found');
        }

        Response::success($group);
    }

    public static function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertGroupAccess($request, $id, $db);

        $group = $db->fetchOne('SELECT * FROM `groups` WHERE id = ?', [$id]);
        if (!$group) {
            Response::notFound('Group not found');
        }

        $name        = trim((string) $request->input('name', $group['name']));
        $description = $request->input('description') !== null
            ? (trim((string) $request->input('description')) ?: null)
            : $group['description'];

        $db->execute(
            'UPDATE `groups` SET name = ?, description = ?' .
            ($request->input('is_active') !== null
                ? ', is_active = ?'
                : '') .
            ' WHERE id = ?',
            $request->input('is_active') !== null
                ? [$name, $description, (bool) $request->input('is_active') ? 1 : 0, $id]
                : [$name, $description, $id]
        );

        AuditService::log('group.updated', 'group', (string) $id, [
            'before' => ['name' => $group['name']],
            'after'  => ['name' => $name],
        ], $request->user['uid']);

        $updated = self::fetchGroupDetail($db, $id);
        Response::success($updated, 'Group updated');
    }

    public static function delete(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertGroupAccess($request, $id, $db);

        $group = $db->fetchOne('SELECT id, is_active FROM `groups` WHERE id = ?', [$id]);
        if (!$group) {
            Response::notFound('Group not found');
        }

        $db->execute('UPDATE `groups` SET is_active = 0 WHERE id = ?', [$id]);

        AuditService::log('group.deactivated', 'group', (string) $id, [], $request->user['uid']);

        Response::success(null, 'Group deactivated');
    }

    public static function addMember(Request $request): never
    {
        $groupId = (int) $request->param('id');
        $userId  = (int) $request->input('user_id', 0);
        $db      = Database::getInstance();

        if (!$userId) {
            Response::validationError(['user_id' => 'Required']);
        }

        self::assertGroupAccess($request, $groupId, $db);

        $group = $db->fetchOne('SELECT id, is_active FROM `groups` WHERE id = ?', [$groupId]);
        if (!$group || !$group['is_active']) {
            Response::notFound('Group not found');
        }

        $user = $db->fetchOne('SELECT id FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if (!$user) {
            Response::notFound('User not found');
        }

        if ($db->fetchValue(
            'SELECT id FROM group_memberships WHERE group_id = ? AND user_id = ? AND is_active = 1',
            [$groupId, $userId]
        )) {
            Response::error('User is already a member of this group', 409);
        }

        $db->execute(
            'INSERT INTO group_memberships (group_id, user_id, added_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_active = 1, added_by = VALUES(added_by), added_at = NOW()',
            [$groupId, $userId, $request->user['uid']]
        );

        AuditService::log('group.member_added', 'group', (string) $groupId, [
            'user_id' => $userId,
        ], $request->user['uid']);

        Response::success(null, 'Member added', 201);
    }

    public static function removeMember(Request $request): never
    {
        $groupId = (int) $request->param('id');
        $userId  = (int) $request->param('user_id');
        $db      = Database::getInstance();

        self::assertGroupAccess($request, $groupId, $db);

        $membership = $db->fetchOne(
            'SELECT id FROM group_memberships WHERE group_id = ? AND user_id = ? AND is_active = 1',
            [$groupId, $userId]
        );
        if (!$membership) {
            Response::notFound('Membership not found');
        }

        $db->execute(
            'UPDATE group_memberships SET is_active = 0 WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId]
        );

        AuditService::log('group.member_removed', 'group', (string) $groupId, [
            'user_id' => $userId,
        ], $request->user['uid']);

        Response::success(null, 'Member removed');
    }

    public static function addChildGroup(Request $request): never
    {
        $parentId = (int) $request->param('id');
        $childId  = (int) $request->input('child_group_id', 0);
        $db       = Database::getInstance();

        if (!$childId) {
            Response::validationError(['child_group_id' => 'Required']);
        }

        self::assertGroupAccess($request, $parentId, $db);
        self::assertGroupAccess($request, $childId, $db);

        if ($parentId === $childId) {
            Response::validationError(['child_group_id' => 'A group cannot contain itself']);
        }

        $parent = $db->fetchOne('SELECT id, is_active FROM `groups` WHERE id = ?', [$parentId]);
        $child  = $db->fetchOne('SELECT id, is_active FROM `groups` WHERE id = ?', [$childId]);
        if (!$parent || !$parent['is_active'] || !$child || !$child['is_active']) {
            Response::notFound('Group not found');
        }

        if ($db->fetchValue(
            'SELECT 1 FROM group_children WHERE parent_group_id = ? AND child_group_id = ?',
            [$parentId, $childId]
        )) {
            Response::error('Child group is already linked', 409);
        }

        if (self::wouldCreateCycle($db, $parentId, $childId)) {
            Response::validationError(['child_group_id' => 'Adding this child would create a circular group reference']);
        }

        $db->execute(
            'INSERT INTO group_children (parent_group_id, child_group_id, added_by) VALUES (?, ?, ?)',
            [$parentId, $childId, $request->user['uid']]
        );

        AuditService::log('group.child_added', 'group', (string) $parentId, [
            'child_group_id' => $childId,
        ], $request->user['uid']);

        Response::success(null, 'Child group added', 201);
    }

    public static function removeChildGroup(Request $request): never
    {
        $parentId = (int) $request->param('id');
        $childId  = (int) $request->param('child_id');
        $db       = Database::getInstance();

        self::assertGroupAccess($request, $parentId, $db);

        $link = $db->fetchValue(
            'SELECT 1 FROM group_children WHERE parent_group_id = ? AND child_group_id = ?',
            [$parentId, $childId]
        );
        if (!$link) {
            Response::notFound('Child group link not found');
        }

        $db->execute(
            'DELETE FROM group_children WHERE parent_group_id = ? AND child_group_id = ?',
            [$parentId, $childId]
        );

        AuditService::log('group.child_removed', 'group', (string) $parentId, [
            'child_group_id' => $childId,
        ], $request->user['uid']);

        Response::success(null, 'Child group removed');
    }

    private static function fetchGroupDetail(Database $db, int $id): ?array
    {
        $group = $db->fetchOne(
            'SELECT g.*, o.display_name AS owner_org_name
             FROM `groups` g
             LEFT JOIN organizations o ON o.id = g.owner_org_id
             WHERE g.id = ?',
            [$id]
        );

        if (!$group) {
            return null;
        }

        $group['members'] = $db->fetchAll(
            'SELECT gm.id, gm.user_id, gm.added_at,
                    u.username, u.display_name, u.first_name, u.last_name
             FROM group_memberships gm
             JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ? AND gm.is_active = 1
             ORDER BY u.last_name, u.first_name',
            [$id]
        );

        $group['child_groups'] = $db->fetchAll(
            'SELECT gc.child_group_id AS id, g.name, g.slug, g.is_active
             FROM group_children gc
             JOIN `groups` g ON g.id = gc.child_group_id
             WHERE gc.parent_group_id = ?
             ORDER BY g.name',
            [$id]
        );

        $group['parent_groups'] = $db->fetchAll(
            'SELECT gc.parent_group_id AS id, g.name, g.slug
             FROM group_children gc
             JOIN `groups` g ON g.id = gc.parent_group_id
             WHERE gc.child_group_id = ?
             ORDER BY g.name',
            [$id]
        );

        return self::normalizeGroupRow($group);
    }

    /** @param array<string, mixed> $row */
    private static function normalizeGroupRow(array $row): array
    {
        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = (int) $row['is_active'];
        }
        if (isset($row['member_count'])) {
            $row['member_count'] = (int) $row['member_count'];
        }
        if (isset($row['child_group_count'])) {
            $row['child_group_count'] = (int) $row['child_group_count'];
        }

        return $row;
    }

    /**
     * Returns true if adding parent → child would create a cycle.
     */
    private static function wouldCreateCycle(Database $db, int $parentId, int $childId): bool
    {
        $visited = [];
        $queue   = [$childId];

        while (!empty($queue)) {
            $current = array_shift($queue);
            if ($current === $parentId) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $children = $db->fetchAll(
                'SELECT child_group_id FROM group_children WHERE parent_group_id = ?',
                [$current]
            );
            foreach ($children as $row) {
                $queue[] = (int) $row['child_group_id'];
            }
        }

        return false;
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s_-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', trim($slug));

        return substr($slug, 0, 100);
    }

    private static function assertOrgAccess(Request $request, int $orgId): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && (int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied to this organization');
        }
    }

    private static function assertGroupAccess(Request $request, int $groupId, Database $db): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if ($isSuperAdmin) {
            return;
        }

        $group = $db->fetchOne('SELECT owner_org_id FROM `groups` WHERE id = ?', [$groupId]);
        if (!$group || (int) $group['owner_org_id'] !== (int) $request->user['org']) {
            Response::forbidden('Access denied to this group');
        }
    }
}
