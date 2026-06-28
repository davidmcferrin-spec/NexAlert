<?php
/**
 * NexAlert - User profile data (tags, memberships, tag self-requests).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Response;
use NexAlert\Config\Database;

class UserProfileDataService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchMemberships(Database $db, int $userId): array
    {
        $memberships = $db->fetchAll(
            'SELECT m.id, m.org_id, m.org_node_id, m.position_title, m.is_active, m.joined_at,
                    o.name AS org_name, o.display_name AS org_display_name, o.slug AS org_slug,
                    n.name AS node_name, n.node_type, n.path
             FROM user_org_memberships m
             JOIN organizations o ON o.id = m.org_id
             JOIN org_nodes n ON n.id = m.org_node_id
             WHERE m.user_id = ? AND m.is_active = 1
             ORDER BY o.name, n.path',
            [$userId]
        );

        return self::enrichMembershipsWithPaths($db, $memberships);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchTags(Database $db, int $userId): array
    {
        return $db->fetchAll(
            'SELECT ta.id, ta.tag_id, ta.assignment_type, ta.assigned_at, ta.is_active,
                    t.name, t.slug, t.is_system, t.is_exclusive,
                    n.name AS source_node_name
             FROM tag_assignments ta
             JOIN tags t ON t.id = ta.tag_id
             LEFT JOIN org_nodes n ON n.id = ta.source_node_id
             WHERE ta.user_id = ? AND ta.is_active = 1
             ORDER BY t.name ASC',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchTagRequests(Database $db, int $userId): array
    {
        return $db->fetchAll(
            'SELECT tar.id, tar.tag_id, tar.justification, tar.status, tar.review_notes,
                    tar.created_at, tar.reviewed_at,
                    t.name AS tag_name, t.slug AS tag_slug
             FROM tag_approval_requests tar
             JOIN tags t ON t.id = tar.tag_id
             WHERE tar.user_id = ?
             ORDER BY tar.created_at DESC
             LIMIT 50',
            [$userId]
        );
    }

    /**
     * Tags the user may request from their profile.
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchRequestableTags(Database $db, int $userId): array
    {
        $homeOrgId = $db->fetchValue('SELECT home_org_id FROM users WHERE id = ?', [$userId]);
        if ($homeOrgId === false || $homeOrgId === null) {
            return [];
        }

        return $db->fetchAll(
            'SELECT t.id, t.name, t.slug, t.description, t.requires_approval, t.owner_org_id,
                    o.display_name AS owner_org_name
             FROM tags t
             LEFT JOIN organizations o ON o.id = t.owner_org_id
             WHERE t.is_active = 1
               AND t.is_system = 0
               AND t.is_exclusive = 0
               AND t.allow_self_request = 1
               AND (t.owner_org_id IS NULL OR t.owner_org_id = ?)
               AND NOT EXISTS (
                   SELECT 1 FROM tag_assignments ta
                   WHERE ta.tag_id = t.id AND ta.user_id = ? AND ta.is_active = 1
               )
               AND NOT EXISTS (
                   SELECT 1 FROM tag_approval_requests tar
                   WHERE tar.tag_id = t.id AND tar.user_id = ? AND tar.status = \'pending\'
               )
             ORDER BY t.name ASC',
            [(int) $homeOrgId, $userId, $userId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function submitTagRequest(
        Database $db,
        int $userId,
        int $tagId,
        ?string $justification
    ): array {
        $tag = $db->fetchOne(
            'SELECT t.*, u.home_org_id
             FROM tags t
             CROSS JOIN users u
             WHERE t.id = ? AND u.id = ? AND t.is_active = 1',
            [$tagId, $userId]
        );

        if (!$tag) {
            Response::notFound('Tag not found');
        }

        if ((int) $tag['is_system'] === 1) {
            Response::validationError(['tag_id' => 'System tags cannot be requested']);
        }
        if ((int) $tag['is_exclusive'] === 1) {
            Response::validationError(['tag_id' => 'This tag must be assigned by an administrator']);
        }
        if ((int) $tag['allow_self_request'] !== 1) {
            Response::validationError(['tag_id' => 'This tag does not allow self-requests']);
        }
        if ($tag['owner_org_id'] !== null && (int) $tag['owner_org_id'] !== (int) $tag['home_org_id']) {
            Response::validationError(['tag_id' => 'Tag is not available for your organization']);
        }

        if ($db->fetchValue(
            'SELECT id FROM tag_assignments WHERE tag_id = ? AND user_id = ? AND is_active = 1',
            [$tagId, $userId]
        )) {
            Response::error('You already have this tag', 409);
        }

        if ($db->fetchValue(
            "SELECT id FROM tag_approval_requests WHERE tag_id = ? AND user_id = ? AND status = 'pending'",
            [$tagId, $userId]
        )) {
            Response::error('You already have a pending request for this tag', 409);
        }

        $justification = $justification !== null ? trim($justification) : '';
        $justification = $justification !== '' ? $justification : null;

        if ((int) $tag['requires_approval'] === 0) {
            $db->transaction(function (Database $db) use ($tagId, $userId): void {
                $db->execute(
                    "INSERT INTO tag_assignments (tag_id, user_id, assignment_type, assigned_by, assigned_at)
                     VALUES (?, ?, 'approved_request', ?, NOW())",
                    [$tagId, $userId, $userId]
                );
                AuditService::log('tag.self_assigned', 'user', (string) $userId, [
                    'tag_id' => $tagId,
                ], $userId);
            });

            return [
                'status'   => 'assigned',
                'message'  => 'Tag added to your profile',
                'tag_id'   => $tagId,
            ];
        }

        $db->execute(
            'INSERT INTO tag_approval_requests (tag_id, user_id, requested_by, justification, status)
             VALUES (?, ?, ?, ?, \'pending\')',
            [$tagId, $userId, $userId, $justification]
        );
        $requestId = $db->lastInsertId();

        AuditService::log('tag.request_created', 'tag', (string) $tagId, [
            'request_id' => $requestId,
            'user_id'    => $userId,
        ], $userId);

        return [
            'status'     => 'pending',
            'message'    => 'Request submitted for approval',
            'request_id' => $requestId,
            'tag_id'     => $tagId,
        ];
    }

    public static function cancelTagRequest(Database $db, int $userId, int $requestId): void
    {
        $req = $db->fetchOne(
            'SELECT id, tag_id, status FROM tag_approval_requests WHERE id = ? AND user_id = ?',
            [$requestId, $userId]
        );

        if (!$req) {
            Response::notFound('Request not found');
        }
        if ($req['status'] !== 'pending') {
            Response::error('Only pending requests can be cancelled', 409);
        }

        $db->execute(
            "UPDATE tag_approval_requests SET status = 'denied', reviewed_by = ?, reviewed_at = NOW(),
                    review_notes = 'Cancelled by requester'
             WHERE id = ?",
            [$userId, $requestId]
        );

        AuditService::log('tag.request_cancelled', 'tag', (string) $req['tag_id'], [
            'request_id' => $requestId,
        ], $userId);
    }

    public static function removeManualTag(Database $db, int $userId, int $tagId): void
    {
        $assignment = $db->fetchOne(
            "SELECT id, assignment_type FROM tag_assignments
             WHERE tag_id = ? AND user_id = ? AND is_active = 1
               AND assignment_type IN ('manual', 'approved_request')",
            [$tagId, $userId]
        );

        if (!$assignment) {
            Response::notFound('This tag cannot be removed from your profile');
        }

        $db->execute(
            'UPDATE tag_assignments SET is_active = 0 WHERE tag_id = ? AND user_id = ?',
            [$tagId, $userId]
        );

        AuditService::log('tag.removed', 'user', (string) $userId, ['tag_id' => $tagId], $userId);
    }

    /**
     * @param array<int, array<string, mixed>> $memberships
     * @return array<int, array<string, mixed>>
     */
    private static function enrichMembershipsWithPaths(Database $db, array $memberships): array
    {
        if ($memberships === []) {
            return $memberships;
        }

        $allNodeIds = [];
        foreach ($memberships as $membership) {
            foreach (self::pathNodeIds($membership) as $nodeId) {
                $allNodeIds[$nodeId] = true;
            }
        }

        $nodeMap = [];
        if ($allNodeIds !== []) {
            $ids = array_keys($allNodeIds);
            [$placeholders, $params] = $db->inClause($ids);
            $rows = $db->fetchAll(
                "SELECT id, name, node_type FROM org_nodes WHERE id IN ({$placeholders})",
                $params
            );
            foreach ($rows as $row) {
                $nodeMap[(int) $row['id']] = $row;
            }
        }

        foreach ($memberships as &$membership) {
            $pathNodes = [];
            foreach (self::pathNodeIds($membership) as $nodeId) {
                if (!isset($nodeMap[$nodeId])) {
                    continue;
                }
                $pathNodes[] = [
                    'id'        => $nodeId,
                    'name'      => $nodeMap[$nodeId]['name'],
                    'node_type' => $nodeMap[$nodeId]['node_type'],
                ];
            }

            $membership['path_nodes'] = $pathNodes;
            $membership['breadcrumb'] = self::membershipBreadcrumb($membership, $pathNodes);
        }
        unset($membership);

        return $memberships;
    }

    /** @param array<string, mixed> $membership */
    private static function pathNodeIds(array $membership): array
    {
        $orgId    = (int) $membership['org_id'];
        $segments = array_values(array_filter(explode('/', (string) ($membership['path'] ?? ''))));
        $nodeIds  = [];

        foreach ($segments as $segment) {
            $id = (int) $segment;
            if ($id <= 0 || $id === $orgId) {
                continue;
            }
            $nodeIds[] = $id;
        }

        if ($nodeIds === []) {
            $nodeIds[] = (int) $membership['org_node_id'];
        }

        return $nodeIds;
    }

    /** @param array<int, array<string, mixed>> $pathNodes */
    private static function membershipBreadcrumb(array $membership, array $pathNodes): string
    {
        $parts = [(string) ($membership['org_display_name'] ?? $membership['org_name'] ?? '')];
        foreach ($pathNodes as $node) {
            $parts[] = (string) $node['name'];
        }

        return implode(' → ', array_filter($parts));
    }
}
