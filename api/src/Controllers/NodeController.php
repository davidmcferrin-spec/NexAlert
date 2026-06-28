<?php
/**
 * NexAlert - Org Node Controller
 * Manages the org hierarchy tree (global BUs, regions, markets, business units, sites, etc.).
 *
 * GET    /api/v1/orgs/{org_id}/nodes           → list nodes (tree or flat)
 * POST   /api/v1/orgs/{org_id}/nodes           → create node
 * GET    /api/v1/orgs/{org_id}/nodes/{id}      → get one node + children
 * PUT    /api/v1/orgs/{org_id}/nodes/{id}      → update node
 * DELETE /api/v1/orgs/{org_id}/nodes/{id}      → deactivate node
 * PUT    /api/v1/orgs/{org_id}/nodes/{id}/move → move node to new parent
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\AuditService;
use NexAlert\Services\RowNormalizer;
use NexAlert\Services\TagService;
use PDOException;

class NodeController
{
    private const VALID_TYPES = [
        'org',
        'global_business_unit',
        'region',
        'market',
        'business_unit',
        'site',
        'department',
        'team',
    ];

    /** node_type => allowed parent node_type values */
    private const PARENT_TYPE_RULES = [
        'global_business_unit' => ['org'],
        'business_unit'        => ['market'],
        'site'                 => ['global_business_unit', 'region', 'market', 'business_unit'],
        'department'           => ['site', 'global_business_unit', 'business_unit'],
        'team'                 => ['department', 'site', 'global_business_unit', 'business_unit'],
    ];

    /**
     * GET /api/v1/orgs/{org_id}/nodes
     * Returns flat list by default. Pass ?tree=1 for nested structure.
     */
    public static function list(Request $request): never
    {
        $orgId = (int) $request->param('org_id');
        $db    = Database::getInstance();

        self::assertOrgAccess($request, $orgId);
        self::assertOrgExists($db, $orgId);
        self::ensureOrgRootNode($db, $orgId);

        $asTree    = $request->query('tree') === '1';
        $activeOnly = $request->query('active', '1') !== '0';
        $type       = $request->query('type');

        $where  = ['n.org_id = ?'];
        $params = [$orgId];

        if ($activeOnly) {
            $where[]  = 'n.is_active = 1';
        }
        if ($type && in_array($type, self::VALID_TYPES, true)) {
            $where[]  = 'n.node_type = ?';
            $params[] = $type;
        }

        $whereStr = implode(' AND ', $where);

        $nodes = $db->fetchAll(
            "SELECT n.id, n.parent_id, n.node_type, n.name, n.slug,
                    n.path, n.depth, n.is_active, n.created_at,
                    (SELECT COUNT(*) FROM user_org_memberships m
                     WHERE m.org_node_id = n.id AND m.is_active = 1) AS member_count
             FROM org_nodes n
             WHERE {$whereStr}
             ORDER BY n.path ASC",
            $params
        );

        $nodes = RowNormalizer::mapFlags($nodes, ['is_active', 'member_count']);

        if ($asTree) {
            $nodes = self::buildTree($nodes);
        }

        Response::success(['nodes' => $nodes, 'total' => count($nodes)]);
    }

    /**
     * POST /api/v1/orgs/{org_id}/nodes
     */
    public static function create(Request $request): never
    {
        $orgId = (int) $request->param('org_id');
        $db    = Database::getInstance();

        self::assertOrgAccess($request, $orgId);
        self::assertOrgExists($db, $orgId);

        $missing = $request->validate(['name', 'node_type']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $name     = trim((string) $request->input('name'));
        $nodeType = (string) $request->input('node_type');
        $parentId = $request->input('parent_id') !== null ? (int) $request->input('parent_id') : null;
        $slugInput = trim((string) $request->input('slug', ''));
        $slugProvided = $slugInput !== '';

        if (!in_array($nodeType, self::VALID_TYPES, true)) {
            Response::validationError(['node_type' => 'Must be one of: ' . implode(', ', self::VALID_TYPES)]);
        }

        if ($nodeType === 'org') {
            Response::validationError(['node_type' => 'Cannot create org root nodes manually']);
        }

        // Validate and resolve parent
        $depth      = 0;
        $parentPath = "/{$orgId}/";
        $parent     = null;

        if ($parentId !== null) {
            $parent = $db->fetchOne(
                'SELECT id, path, depth, org_id, node_type FROM org_nodes WHERE id = ? AND is_active = 1',
                [$parentId]
            );
            if (!$parent || (int) $parent['org_id'] !== $orgId) {
                Response::validationError(['parent_id' => 'Parent node not found in this organization']);
            }
            $depth      = (int) $parent['depth'] + 1;
            $parentPath = $parent['path'];
        }

        self::assertValidParentForType($nodeType, $parent);

        if ($slugProvided) {
            $slug = strtolower($slugInput);
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                Response::validationError(['slug' => 'Only lowercase letters, numbers, hyphens, and underscores']);
            }
            if (self::slugExistsUnderParent($db, $orgId, $parentId, $slug)) {
                Response::validationError(['slug' => 'Slug already in use under this parent node']);
            }
        } else {
            $slug = self::allocateUniqueSlug($db, $orgId, $parentId, self::slugify($name));
        }

        $id = $db->transaction(function (Database $db) use (
            $orgId, $parentId, $nodeType, $name, $slug, $depth, $parentPath, $request
        ): int {
            $db->execute(
                'INSERT INTO org_nodes (org_id, parent_id, node_type, name, slug, path, depth)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$orgId, $parentId, $nodeType, $name, $slug, 'PLACEHOLDER', $depth]
            );
            $id = $db->lastInsertId();

            // Now that we have the ID, set the materialized path
            $path = $parentPath . $id . '/';
            $db->execute('UPDATE org_nodes SET path = ? WHERE id = ?', [$path, $id]);

            // Auto-create system tag for this node (e.g. tag "Engineering" for an Engineering node)
            TagService::ensureNodeTag($db, $orgId, $id, $name);

            AuditService::log('org_node.created', 'org_node', (string) $id, [
                'org_id'    => $orgId,
                'name'      => $name,
                'node_type' => $nodeType,
                'parent_id' => $parentId,
            ], $request->user['uid']);

            return $id;
        });

        $node = $db->fetchOne('SELECT * FROM org_nodes WHERE id = ?', [$id]);
        Response::success($node, 'Node created', 201);
    }

    /**
     * GET /api/v1/orgs/{org_id}/nodes/{id}
     */
    public static function get(Request $request): never
    {
        $orgId  = (int) $request->param('org_id');
        $nodeId = (int) $request->param('id');
        $db     = Database::getInstance();

        self::assertOrgAccess($request, $orgId);

        $node = $db->fetchOne(
            'SELECT n.*,
                    (SELECT COUNT(*) FROM user_org_memberships m
                     WHERE m.org_node_id = n.id AND m.is_active = 1) AS member_count
             FROM org_nodes n
             WHERE n.id = ? AND n.org_id = ?',
            [$nodeId, $orgId]
        );

        if (!$node) {
            Response::notFound('Node not found');
        }

        $node = RowNormalizer::flags($node, ['is_active', 'member_count']);

        // Direct children
        $node['children'] = RowNormalizer::mapFlags(
            $db->fetchAll(
                'SELECT id, parent_id, node_type, name, slug, depth, is_active
                 FROM org_nodes WHERE parent_id = ? ORDER BY name ASC',
                [$nodeId]
            ),
            ['is_active']
        );

        // Members of this node
        $node['members'] = $db->fetchAll(
            'SELECT u.id, u.username, u.display_name, u.first_name, u.last_name,
                    m.position_title, m.joined_at
             FROM user_org_memberships m
             JOIN users u ON u.id = m.user_id
             WHERE m.org_node_id = ? AND m.is_active = 1 AND u.is_active = 1
             ORDER BY u.last_name, u.first_name',
            [$nodeId]
        );

        Response::success($node);
    }

    /**
     * PUT /api/v1/orgs/{org_id}/nodes/{id}
     */
    public static function update(Request $request): never
    {
        $orgId  = (int) $request->param('org_id');
        $nodeId = (int) $request->param('id');
        $db     = Database::getInstance();

        self::assertOrgAccess($request, $orgId);

        $node = $db->fetchOne('SELECT * FROM org_nodes WHERE id = ? AND org_id = ?', [$nodeId, $orgId]);
        if (!$node) {
            Response::notFound('Node not found');
        }

        $name     = trim((string) $request->input('name', $node['name']));
        $nodeType = (string) $request->input('node_type', $node['node_type']);

        if (!in_array($nodeType, self::VALID_TYPES, true)) {
            Response::validationError(['node_type' => 'Must be one of: ' . implode(', ', self::VALID_TYPES)]);
        }

        if ($nodeType === 'org' && $node['node_type'] !== 'org') {
            Response::validationError(['node_type' => 'Cannot change node type to org']);
        }

        $parent = null;
        if ($node['parent_id']) {
            $parent = $db->fetchOne(
                'SELECT id, node_type FROM org_nodes WHERE id = ?',
                [(int) $node['parent_id']]
            );
        }

        self::assertValidParentForType($nodeType, $parent);

        $parentId = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
        $slug     = self::resolveUpdatedSlug($db, $orgId, $parentId, $nodeId, $node, $name, $request);

        if ($nodeType === 'org' && $slug !== $node['slug']) {
            if ($db->fetchValue('SELECT id FROM organizations WHERE slug = ? AND id != ?', [$slug, $orgId])) {
                Response::validationError(['slug' => 'Organization slug already in use']);
            }
        }

        $db->execute(
            'UPDATE org_nodes SET name = ?, node_type = ?, slug = ? WHERE id = ?',
            [$name, $nodeType, $slug, $nodeId]
        );

        if ($nodeType === 'org' && $slug !== $node['slug']) {
            $db->execute('UPDATE organizations SET slug = ? WHERE id = ?', [$slug, $orgId]);
        }

        if ($name !== $node['name']) {
            TagService::syncSystemTagForNodeName($db, $node['name']);
            TagService::syncSystemTagForNodeName($db, $name);
            TagService::ensureNodeTag($db, $orgId, $nodeId, $name);
        }

        AuditService::log('org_node.updated', 'org_node', (string) $nodeId, [
            'before' => ['name' => $node['name'], 'node_type' => $node['node_type'], 'slug' => $node['slug']],
            'after'  => ['name' => $name, 'node_type' => $nodeType, 'slug' => $slug],
        ], $request->user['uid']);

        $updated = $db->fetchOne('SELECT * FROM org_nodes WHERE id = ?', [$nodeId]);
        Response::success($updated, 'Node updated');
    }

    /**
     * DELETE /api/v1/orgs/{org_id}/nodes/{id}
     * Soft delete. Refuses if node has active members or active children.
     */
    public static function delete(Request $request): never
    {
        $orgId  = (int) $request->param('org_id');
        $nodeId = (int) $request->param('id');
        $db     = Database::getInstance();

        self::assertOrgAccess($request, $orgId);

        $node = $db->fetchOne('SELECT * FROM org_nodes WHERE id = ? AND org_id = ?', [$nodeId, $orgId]);
        if (!$node) {
            Response::notFound('Node not found');
        }

        // Block if node_type = 'org' (root node, managed by org lifecycle)
        if ($node['node_type'] === 'org') {
            Response::error('Cannot delete the root org node. Deactivate the organization instead.', 409);
        }

        $activeMembers = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM user_org_memberships WHERE org_node_id = ? AND is_active = 1',
            [$nodeId]
        );
        if ($activeMembers > 0) {
            Response::error("Cannot deactivate: {$activeMembers} active member(s) in this node.", 409);
        }

        $activeChildren = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM org_nodes WHERE parent_id = ? AND is_active = 1',
            [$nodeId]
        );
        if ($activeChildren > 0) {
            Response::error("Cannot deactivate: node has {$activeChildren} active child node(s).", 409);
        }

        $db->execute('UPDATE org_nodes SET is_active = 0 WHERE id = ?', [$nodeId]);

        TagService::syncSystemTagForNodeName($db, $node['name']);

        AuditService::log('org_node.deactivated', 'org_node', (string) $nodeId, [], $request->user['uid']);

        Response::success(null, 'Node deactivated');
    }

    /**
     * PUT /api/v1/orgs/{org_id}/nodes/{id}/move
     * Move a node to a new parent. Recalculates paths for node and all descendants.
     * Body: { "parent_id": 5 }  (null = make root-level under org)
     */
    public static function move(Request $request): never
    {
        $orgId    = (int) $request->param('org_id');
        $nodeId   = (int) $request->param('id');
        $newParentId = $request->input('parent_id') !== null ? (int) $request->input('parent_id') : null;
        $db       = Database::getInstance();

        self::assertOrgAccess($request, $orgId);

        $node = $db->fetchOne('SELECT * FROM org_nodes WHERE id = ? AND org_id = ?', [$nodeId, $orgId]);
        if (!$node) {
            Response::notFound('Node not found');
        }

        if ($node['node_type'] === 'org') {
            Response::error('Cannot move the root org node.', 409);
        }

        // Prevent moving a node into its own subtree
        if ($newParentId !== null) {
            $newParent = $db->fetchOne(
                'SELECT id, path, depth, org_id, node_type FROM org_nodes WHERE id = ? AND is_active = 1',
                [$newParentId]
            );
            if (!$newParent || (int) $newParent['org_id'] !== $orgId) {
                Response::validationError(['parent_id' => 'New parent not found in this organization']);
            }
            // Check the new parent isn't inside the node's current subtree
            if (str_starts_with($newParent['path'], $node['path'])) {
                Response::error('Cannot move a node into its own subtree.', 409);
            }

            self::assertValidParentForType($node['node_type'], $newParent);
        } elseif (self::requiresParent($node['node_type'])) {
            Response::validationError([
                'parent_id' => self::placementErrorMessage($node['node_type']),
            ]);
        }

        $db->transaction(function (Database $db) use ($node, $nodeId, $newParentId, $orgId, $request): void {
            // Compute new path and depth
            if ($newParentId === null) {
                $newPath  = "/{$orgId}/{$nodeId}/";
                $newDepth = 1;
                $depthDelta = $newDepth - (int) $node['depth'];
            } else {
                $newParent = $db->fetchOne('SELECT path, depth FROM org_nodes WHERE id = ?', [$newParentId]);
                $newPath   = $newParent['path'] . $nodeId . '/';
                $newDepth  = (int) $newParent['depth'] + 1;
                $depthDelta = $newDepth - (int) $node['depth'];
            }

            $oldPath = $node['path'];

            // Update the node itself
            $db->execute(
                'UPDATE org_nodes SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
                [$newParentId, $newPath, $newDepth, $nodeId]
            );

            // Update all descendants: replace old path prefix with new path
            $descendants = $db->fetchAll(
                'SELECT id, path, depth FROM org_nodes WHERE path LIKE ? AND id != ?',
                [$oldPath . '%', $nodeId]
            );

            foreach ($descendants as $desc) {
                $updatedPath  = $newPath . substr($desc['path'], strlen($oldPath));
                $updatedDepth = (int) $desc['depth'] + $depthDelta;
                $db->execute(
                    'UPDATE org_nodes SET path = ?, depth = ? WHERE id = ?',
                    [$updatedPath, $updatedDepth, $desc['id']]
                );
            }

            AuditService::log('org_node.moved', 'org_node', (string) $nodeId, [
                'old_parent_id' => $node['parent_id'],
                'new_parent_id' => $newParentId,
                'old_path'      => $oldPath,
                'new_path'      => $newPath,
            ], $request->user['uid']);
        });

        $updated = $db->fetchOne('SELECT * FROM org_nodes WHERE id = ?', [$nodeId]);
        Response::success($updated, 'Node moved');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function assertOrgAccess(Request $request, int $orgId): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && (int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied to this organization');
        }
    }

    private static function assertOrgExists(Database $db, int $orgId): void
    {
        if (!$db->fetchValue('SELECT id FROM organizations WHERE id = ? AND is_active = 1', [$orgId])) {
            Response::notFound('Organization not found');
        }
    }

    /**
     * Ensure every org has a root org_node (repairs legacy orgs created without one).
     * Safe under concurrent requests: INSERT IGNORE + duplicate-key catch.
     */
    private static function ensureOrgRootNode(Database $db, int $orgId): void
    {
        if ($db->fetchValue(
            "SELECT id FROM org_nodes WHERE org_id = ? AND node_type = 'org' AND is_active = 1",
            [$orgId]
        )) {
            return;
        }

        $org = $db->fetchOne('SELECT id, name, slug FROM organizations WHERE id = ?', [$orgId]);
        if (!$org) {
            return;
        }

        try {
            $db->transaction(function (Database $db) use ($orgId, $org): void {
                if ($db->fetchValue(
                    "SELECT id FROM org_nodes WHERE org_id = ? AND node_type = 'org' AND is_active = 1",
                    [$orgId]
                )) {
                    return;
                }

                $db->execute(
                    "INSERT IGNORE INTO org_nodes (org_id, parent_id, node_type, name, slug, path, depth, is_active)
                     VALUES (?, NULL, 'org', ?, ?, ?, 0, 1)",
                    [$orgId, $org['name'], $org['slug'], "/{$orgId}/"]
                );

                $nodeId = $db->lastInsertId();
                if ($nodeId === 0) {
                    return;
                }

                $db->execute(
                    'UPDATE org_nodes SET path = ? WHERE id = ?',
                    ["/{$orgId}/{$nodeId}/", $nodeId]
                );
            });
        } catch (PDOException $e) {
            // Another request won the race on (org_id, slug); list can proceed.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Build a nested tree from a flat node list (already ordered by path).
     */
    private static function buildTree(array $nodes): array
    {
        $map  = [];
        $tree = [];

        foreach ($nodes as &$node) {
            $node['children'] = [];
            $map[$node['id']] = &$node;
        }
        unset($node);

        foreach ($nodes as &$node) {
            if ($node['parent_id'] && isset($map[$node['parent_id']])) {
                $map[$node['parent_id']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }

        return $tree;
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s_-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', trim($slug));
        return substr($slug, 0, 80);
    }

    /**
     * True when slug was auto-derived from the node name (base or base-N suffix).
     */
    private static function slugMatchesNameDerived(string $slug, string $name): bool
    {
        $base = self::slugify($name);
        if ($base === '') {
            return false;
        }
        if ($slug === $base) {
            return true;
        }

        return (bool) preg_match('/^' . preg_quote($base, '/') . '(-\d+)?$/', $slug);
    }

    /**
     * Resolve slug on update: explicit input, auto-sync when linked to old name, or unchanged.
     */
    private static function resolveUpdatedSlug(
        Database $db,
        int $orgId,
        ?int $parentId,
        int $nodeId,
        array $node,
        string $name,
        Request $request
    ): string {
        $slugInput = $request->input('slug');
        if ($slugInput !== null && trim((string) $slugInput) !== '') {
            $slug = strtolower(trim((string) $slugInput));
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                Response::validationError(['slug' => 'Only lowercase letters, numbers, hyphens, and underscores']);
            }
            $baseFromName = self::slugify($name);
            if (
                $slug === $baseFromName
                && $name !== $node['name']
                && self::slugMatchesNameDerived($node['slug'], $node['name'])
            ) {
                $slug = self::allocateUniqueSlug($db, $orgId, $parentId, $baseFromName, $nodeId);
            }
        } elseif ($name !== $node['name'] && self::slugMatchesNameDerived($node['slug'], $node['name'])) {
            $slug = self::allocateUniqueSlug($db, $orgId, $parentId, self::slugify($name), $nodeId);
        } else {
            $slug = $node['slug'];
        }

        if ($slug !== $node['slug'] && self::slugExistsUnderParent($db, $orgId, $parentId, $slug, $nodeId)) {
            Response::validationError(['slug' => 'Slug already in use under this parent node']);
        }

        return $slug;
    }

    private static function slugExistsUnderParent(
        Database $db,
        int $orgId,
        ?int $parentId,
        string $slug,
        ?int $excludeNodeId = null
    ): bool {
        $sql    = 'SELECT id FROM org_nodes WHERE org_id = ? AND parent_id <=> ? AND slug = ?';
        $params = [$orgId, $parentId, $slug];

        if ($excludeNodeId !== null) {
            $sql     .= ' AND id != ?';
            $params[] = $excludeNodeId;
        }

        return (bool) $db->fetchValue($sql, $params);
    }

    /**
     * Pick a slug unique among siblings (same org + parent).
     * Appends -2, -3, … when the base slug is taken.
     */
    private static function allocateUniqueSlug(
        Database $db,
        int $orgId,
        ?int $parentId,
        string $baseSlug,
        ?int $excludeNodeId = null
    ): string {
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'node';

        if (!self::slugExistsUnderParent($db, $orgId, $parentId, $baseSlug, $excludeNodeId)) {
            return $baseSlug;
        }

        for ($n = 2; $n <= 999; $n++) {
            $suffix = '-' . $n;
            $slug   = substr($baseSlug, 0, max(1, 80 - strlen($suffix))) . $suffix;
            if (!self::slugExistsUnderParent($db, $orgId, $parentId, $slug, $excludeNodeId)) {
                return $slug;
            }
        }

        Response::validationError(['slug' => 'Could not generate a unique slug for this node']);
    }

    /**
     * Enforce parent-type placement rules for node types with structural constraints.
     *
     * @param array<string, mixed>|null $parent Row with at least node_type
     */
    private static function assertValidParentForType(string $nodeType, ?array $parent): void
    {
        if (!self::requiresParent($nodeType)) {
            return;
        }

        if ($parent === null) {
            Response::validationError([
                'parent_id'  => self::placementErrorMessage($nodeType),
                'node_type'  => self::placementErrorMessage($nodeType),
            ]);
        }

        $allowedParents = self::PARENT_TYPE_RULES[$nodeType];
        $parentType     = (string) $parent['node_type'];

        if (!in_array($parentType, $allowedParents, true)) {
            Response::validationError([
                'parent_id' => self::placementErrorMessage($nodeType),
                'node_type' => self::placementErrorMessage($nodeType),
            ]);
        }
    }

    private static function requiresParent(string $nodeType): bool
    {
        return isset(self::PARENT_TYPE_RULES[$nodeType]);
    }

    private static function placementErrorMessage(string $nodeType): string
    {
        return match ($nodeType) {
            'global_business_unit' => 'Global Business Unit nodes must be placed directly under the organization root',
            'business_unit'        => 'Business Unit nodes must be placed directly under a Market node',
            'site'                 => 'Site nodes must be placed under a Global BU, Region, Market, or Business Unit',
            'department'           => 'Department nodes must be placed under a Site, Global BU, or Business Unit',
            'team'                 => 'Team nodes must be placed under a Department, Site, Global BU, or Business Unit',
            default                => 'Invalid node placement for this type',
        };
    }
}
