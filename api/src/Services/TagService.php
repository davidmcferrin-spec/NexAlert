<?php
/**
 * NexAlert - Tag Service
 * Handles system tag creation (auto-generated from org tree nodes)
 * and tag assignment resolution for alert targeting.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;

class TagService
{
    /**
     * Ensure a system tag exists for a given org node.
     * Called when a node is created. Creates the tag if it doesn't exist,
     * then records the association so tag_assignments can be auto-populated
     * when users are added to this node.
     *
     * Tags are global by name (e.g. "Engineering" is one tag used across orgs).
     * The org_id on the tag marks ownership for scoped management.
     */
    public static function ensureNodeTag(Database $db, int $orgId, int $nodeId, string $nodeName): int
    {
        $slug = self::slugify($nodeName);

        // Check if a system tag with this slug already exists
        $tag = $db->fetchOne(
            "SELECT id FROM tags WHERE slug = ? AND is_system = 1",
            [$slug]
        );

        if (!$tag) {
            $db->execute(
                "INSERT INTO tags (name, slug, owner_org_id, is_exclusive, allow_self_request,
                                   requires_approval, is_active, is_system)
                 VALUES (?, ?, ?, 0, 0, 0, 1, 1)",
                [$nodeName, $slug, $orgId]
            );
            $tagId = $db->lastInsertId();
        } else {
            $tagId = (int) $tag['id'];
            // Re-activate if another org node still uses this slug
            if (self::systemTagHasActiveNode($db, $slug)) {
                $db->execute('UPDATE tags SET is_active = 1 WHERE id = ?', [$tagId]);
            }
        }

        return $tagId;
    }

    /**
     * True when at least one active org node slugifies to the given slug.
     */
    public static function systemTagHasActiveNode(Database $db, string $slug): bool
    {
        $nodes = $db->fetchAll('SELECT name FROM org_nodes WHERE is_active = 1');
        foreach ($nodes as $node) {
            if (self::slugify($node['name']) === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deactivate system tags whose org node was removed; reactivate when a node returns.
     */
    public static function syncSystemTagForNodeName(Database $db, string $nodeName): void
    {
        $slug = self::slugify($nodeName);
        $tag  = $db->fetchOne(
            'SELECT id, is_active FROM tags WHERE slug = ? AND is_system = 1',
            [$slug]
        );
        if (!$tag) {
            return;
        }

        $shouldBeActive = self::systemTagHasActiveNode($db, $slug) ? 1 : 0;
        if ((int) $tag['is_active'] !== $shouldBeActive) {
            $db->execute('UPDATE tags SET is_active = ? WHERE id = ?', [$shouldBeActive, $tag['id']]);
        }
    }

    /**
     * Auto-assign all system tags inherited from a user's org node membership.
     * Called when a user is added to an org node.
     *
     * Walks the materialized path and assigns a tag for each ancestor node.
     * e.g. path /1/4/12/ → assign tags for nodes 1, 4, and 12.
     */
    public static function assignNodeTagsToUser(Database $db, int $userId, int $nodeId): void
    {
        // Get the node and all its ancestors via materialized path
        $node = $db->fetchOne('SELECT path, org_id FROM org_nodes WHERE id = ?', [$nodeId]);
        if (!$node) {
            return;
        }

        // Extract ancestor node IDs from path e.g. /1/4/12/ → [1, 4, 12]
        $pathIds = array_filter(explode('/', $node['path']));

        foreach ($pathIds as $ancestorId) {
            $ancestorId = (int) $ancestorId;

            // Find the system tag for this node
            $ancestorNode = $db->fetchOne('SELECT name FROM org_nodes WHERE id = ?', [$ancestorId]);
            if (!$ancestorNode) {
                continue;
            }

            $slug = self::slugify($ancestorNode['name']);
            $tag  = $db->fetchOne('SELECT id FROM tags WHERE slug = ? AND is_system = 1', [$slug]);

            if (!$tag) {
                // Tag doesn't exist yet — create it
                $tagId = self::ensureNodeTag($db, (int) $node['org_id'], $ancestorId, $ancestorNode['name']);
            } else {
                $tagId = (int) $tag['id'];
            }

            // Assign tag to user (ignore duplicate)
            try {
                $db->execute(
                    "INSERT INTO tag_assignments
                        (tag_id, user_id, assignment_type, source_node_id, assigned_at)
                     VALUES (?, ?, 'auto_node', ?, NOW())
                     ON DUPLICATE KEY UPDATE is_active = 1",
                    [$tagId, $userId, $ancestorId]
                );
            } catch (\Throwable) {
                // ON DUPLICATE KEY handles conflicts; any other error is non-fatal here
            }
        }
    }

    /**
     * Remove auto-assigned node tags from a user when they leave a node.
     * Only removes tags that came from this specific node; shared tags from
     * other memberships are preserved.
     */
    public static function revokeNodeTagsFromUser(Database $db, int $userId, int $nodeId): void
    {
        $node = $db->fetchOne('SELECT path FROM org_nodes WHERE id = ?', [$nodeId]);
        if (!$node) {
            return;
        }

        $pathIds = array_filter(explode('/', $node['path']));

        foreach ($pathIds as $ancestorId) {
            $ancestorId = (int) $ancestorId;

            // Check if user has other active memberships that also grant this node's tag
            $otherMemberships = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM user_org_memberships m
                 JOIN org_nodes n ON n.id = m.org_node_id
                 WHERE m.user_id = ? AND m.is_active = 1
                   AND m.org_node_id != ?
                   AND n.path LIKE ?',
                [$userId, $nodeId, '%/' . $ancestorId . '/%']
            );

            if ($otherMemberships > 0) {
                // User still gets this tag via another membership
                continue;
            }

            // Safe to revoke
            $db->execute(
                "UPDATE tag_assignments
                 SET is_active = 0
                 WHERE user_id = ? AND source_node_id = ? AND assignment_type = 'auto_node'",
                [$userId, $ancestorId]
            );
        }
    }

    /**
     * Resolve a set of alert target rows into a deduplicated list of user IDs.
     * Each target row ANDs its non-null dimensions; rows are OR'd together.
     *
     * @param array $targets Rows from alert_targets table
     * @return int[] Deduplicated active user IDs
     */
    public static function resolveTargets(Database $db, array $targets): array
    {
        $userIds = [];

        foreach ($targets as $target) {
            $userIds = array_merge($userIds, self::resolveTargetRow($db, $target));
        }

        return array_values(array_unique(array_map('intval', $userIds)));
    }

    /**
     * Resolve a single alert_targets row to user IDs.
     *
     * @return int[]
     */
    public static function resolveTargetRow(Database $db, array $target): array
    {
        if (!empty($target['target_user_id'])) {
            $uid = (int) $target['target_user_id'];
            $active = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1',
                [$uid]
            );

            return $active > 0 ? [$uid] : [];
        }

        $sql    = 'SELECT DISTINCT u.id FROM users u WHERE u.is_active = 1';
        $params = [];

        if (!empty($target['target_org_id'])) {
            $sql     .= ' AND u.home_org_id = ?';
            $params[] = $target['target_org_id'];
        }

        if (!empty($target['target_node_id'])) {
            $node = $db->fetchOne('SELECT path FROM org_nodes WHERE id = ?', [$target['target_node_id']]);
            if ($node) {
                $sql     .= ' AND EXISTS (
                    SELECT 1 FROM user_org_memberships m
                    JOIN org_nodes n ON n.id = m.org_node_id
                    WHERE m.user_id = u.id AND m.is_active = 1
                      AND n.path LIKE ?
                )';
                $params[] = $node['path'] . '%';
            }
        }

        if (!empty($target['target_tag_id'])) {
            $sql     .= ' AND EXISTS (
                SELECT 1 FROM tag_assignments ta
                WHERE ta.user_id = u.id AND ta.tag_id = ? AND ta.is_active = 1
            )';
            $params[] = $target['target_tag_id'];
        }

        if (!empty($target['target_group_id'])) {
            $groupUserIds = self::resolveGroupMembers($db, (int) $target['target_group_id']);
            if (empty($groupUserIds)) {
                return [];
            }
            [$placeholders, $params] = $db->inClause($groupUserIds, $params);
            $sql .= " AND u.id IN ({$placeholders})";
        }

        $rows = $db->fetchAll($sql, $params);

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * Recursively resolve all user IDs in a group (including nested groups).
     * Uses iterative BFS to avoid stack overflow on deep nesting.
     * Cycle detection via visited set.
     *
     * @return int[]
     */
    public static function resolveGroupMembers(Database $db, int $groupId, int $maxDepth = 10): array
    {
        $visited  = [];
        $queue    = [$groupId];
        $userIds  = [];
        $depth    = 0;

        while (!empty($queue) && $depth <= $maxDepth) {
            $currentBatch = $queue;
            $queue        = [];
            $depth++;

            foreach ($currentBatch as $gid) {
                if (isset($visited[$gid])) {
                    continue; // Cycle detected — skip
                }
                $visited[$gid] = true;

                // Direct members
                $members = $db->fetchAll(
                    'SELECT user_id FROM group_memberships WHERE group_id = ? AND is_active = 1',
                    [$gid]
                );
                foreach ($members as $m) {
                    $userIds[] = (int) $m['user_id'];
                }

                // Child groups to process in next iteration
                $children = $db->fetchAll(
                    'SELECT child_group_id FROM group_children WHERE parent_group_id = ?',
                    [$gid]
                );
                foreach ($children as $c) {
                    $queue[] = (int) $c['child_group_id'];
                }
            }
        }

        return array_values(array_unique($userIds));
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s_-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', trim($slug));
        return substr($slug, 0, 100);
    }
}
