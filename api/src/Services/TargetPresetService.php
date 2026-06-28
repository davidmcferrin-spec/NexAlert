<?php
/**
 * NexAlert - Target Preset Service
 * CRUD for saved target expressions (Target Builder + API target_preset).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;

class TargetPresetService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function list(Database $db, array $user, ?int $orgFilter = null, string $search = ''): array
    {
        $isSuperAdmin = in_array('super_admin', $user['roles'] ?? [], true);
        $where        = ['tp.is_active = 1'];
        $params       = [];

        if ($isSuperAdmin) {
            if ($orgFilter !== null) {
                $where[]  = '(tp.org_id IS NULL OR tp.org_id = ?)';
                $params[] = $orgFilter;
            }
        } else {
            $userOrg = (int) ($user['org'] ?? 0);
            $where[] = '(tp.org_id IS NULL OR tp.org_id = ?)';
            $params[] = $userOrg;
        }

        if ($search !== '') {
            $where[] = '(tp.name LIKE ? OR tp.slug LIKE ? OR tp.description LIKE ? OR tp.expression LIKE ?)';
            $like    = '%' . $search . '%';
            $params  = array_merge($params, [$like, $like, $like, $like]);
        }

        $whereStr = implode(' AND ', $where);

        $rows = $db->fetchAll(
            "SELECT tp.id, tp.org_id, tp.slug, tp.name, tp.description, tp.expression,
                    tp.is_active, tp.created_at, tp.updated_at,
                    o.display_name AS org_name,
                    u.display_name AS created_by_name
             FROM target_presets tp
             LEFT JOIN organizations o ON o.id = tp.org_id
             LEFT JOIN users u ON u.id = tp.created_by
             WHERE {$whereStr}
             ORDER BY tp.org_id IS NULL DESC, tp.name ASC",
            $params
        );

        return array_map([self::class, 'normalizeListRow'], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(Database $db, int $id, array $user): array
    {
        $row = self::fetchRow($db, $id);
        if (!$row) {
            Response::notFound('Target preset not found');
        }
        self::assertCanAccess($user, $row);

        return self::normalizeDetailRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getBySlug(Database $db, string $slug, ?int $orgId, array $user): ?array
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $row = self::lookupBySlug($db, $slug, $orgId);
        if (!$row) {
            return null;
        }

        self::assertCanAccess($user, $row);

        return self::normalizeDetailRow($row);
    }

    /**
     * Resolve preset for alert send (JWT user or system token).
     *
     * @return array{expression: string, target_tree: ?array<string, mixed>}
     */
    public static function resolveForAlert(Database $db, Request $request, string $slug): array
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            Response::validationError(['target_preset' => 'Invalid preset slug']);
        }

        $orgId = self::resolveOrgContext($request);
        $row   = self::lookupBySlug($db, $slug, $orgId);

        if (!$row || !(int) $row['is_active']) {
            Response::validationError(['target_preset' => 'Preset not found: ' . $slug]);
        }

        if (!empty($request->user)) {
            self::assertCanAccess($request->user, $row);
        } elseif (!empty($request->token)) {
            self::assertTokenCanAccess($request->token, $row);
        }

        $tree = self::decodeTree($row['target_tree']);

        return [
            'expression'  => (string) $row['expression'],
            'target_tree' => $tree,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function create(Database $db, array $user, array $payload): array
    {
        $isSuperAdmin = in_array('super_admin', $user['roles'] ?? [], true);
        $name         = trim((string) ($payload['name'] ?? ''));
        $slug         = self::normalizeSlug((string) ($payload['slug'] ?? ''));
        $description  = trim((string) ($payload['description'] ?? ''));
        $orgId        = self::resolveOrgIdForWrite($user, $payload, $isSuperAdmin);

        if ($name === '') {
            Response::validationError(['name' => 'Required']);
        }
        if ($slug === '') {
            $slug = self::slugFromName($name);
        }

        self::assertSlugAvailable($db, $slug, $orgId);
        $compiled = self::compilePayload($payload);

        $db->execute(
            'INSERT INTO target_presets
                (org_id, slug, name, description, expression, target_tree, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orgId,
                $slug,
                $name,
                $description !== '' ? $description : null,
                $compiled['expression'],
                $compiled['target_tree_json'],
                (int) $user['uid'],
                (int) $user['uid'],
            ]
        );

        $id = $db->lastInsertId();
        AuditService::log('target_preset.created', 'target_preset', (string) $id, [
            'slug' => $slug,
            'name' => $name,
        ], (int) $user['uid']);

        return self::get($db, $id, $user);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function update(Database $db, int $id, array $user, array $payload): array
    {
        $row = self::fetchRow($db, $id);
        if (!$row) {
            Response::notFound('Target preset not found');
        }
        self::assertCanManage($user, $row);

        $name        = trim((string) ($payload['name'] ?? $row['name']));
        $slug        = self::normalizeSlug((string) ($payload['slug'] ?? $row['slug']));
        $description = array_key_exists('description', $payload)
            ? trim((string) $payload['description'])
            : (string) ($row['description'] ?? '');

        if ($name === '') {
            Response::validationError(['name' => 'Required']);
        }
        if ($slug === '') {
            Response::validationError(['slug' => 'Required']);
        }

        $orgId = $row['org_id'] !== null ? (int) $row['org_id'] : null;
        if ($slug !== $row['slug']) {
            self::assertSlugAvailable($db, $slug, $orgId, $id);
        }

        $compiled = self::compilePayload($payload, $row);

        $db->execute(
            'UPDATE target_presets
             SET slug = ?, name = ?, description = ?, expression = ?, target_tree = ?, updated_by = ?
             WHERE id = ?',
            [
                $slug,
                $name,
                $description !== '' ? $description : null,
                $compiled['expression'],
                $compiled['target_tree_json'],
                (int) $user['uid'],
                $id,
            ]
        );

        AuditService::log('target_preset.updated', 'target_preset', (string) $id, [
            'slug' => $slug,
        ], (int) $user['uid']);

        return self::get($db, $id, $user);
    }

    public static function delete(Database $db, int $id, array $user): void
    {
        $row = self::fetchRow($db, $id);
        if (!$row) {
            Response::notFound('Target preset not found');
        }
        self::assertCanManage($user, $row);

        $db->execute(
            'UPDATE target_presets SET is_active = 0, updated_by = ? WHERE id = ?',
            [(int) $user['uid'], $id]
        );

        AuditService::log('target_preset.deleted', 'target_preset', (string) $id, [
            'slug' => $row['slug'],
        ], (int) $user['uid']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array{expression: string, target_tree_json: ?string}
     */
    private static function compilePayload(array $payload, ?array $existing = null): array
    {
        $tree = $payload['target_tree'] ?? null;
        if (is_string($tree) && $tree !== '') {
            $decoded = json_decode($tree, true);
            $tree    = is_array($decoded) ? $decoded : null;
        }

        $expression = trim((string) ($payload['expression'] ?? ''));

        if (is_array($tree) && ($tree['type'] ?? '') === 'group') {
            $compiled = TargetExpressionService::compileExpressionAst('', $tree);
            if ($compiled['errors'] !== []) {
                Response::validationError(['targets' => implode('; ', $compiled['errors'])]);
            }
            $preview = TargetExpressionService::preview(Database::getInstance(), null, null, $tree);
            if (!($preview['valid'] ?? false)) {
                Response::validationError(['targets' => implode('; ', $preview['errors'] ?? ['Invalid target'])]);
            }

            return [
                'expression'        => (string) ($preview['expression'] ?? $expression),
                'target_tree_json'  => json_encode($tree, JSON_THROW_ON_ERROR),
            ];
        }

        if ($expression === '' && $existing) {
            $expression = (string) $existing['expression'];
            $treeJson   = $existing['target_tree'];
            if ($expression !== '') {
                return [
                    'expression'       => $expression,
                    'target_tree_json' => is_string($treeJson) ? $treeJson : null,
                ];
            }
        }

        if ($expression === '') {
            Response::validationError(['expression' => 'Expression or target_tree is required']);
        }

        $compiled = TargetExpressionService::compileExpressionAst($expression);
        if ($compiled['errors'] !== []) {
            Response::validationError(['expression' => implode('; ', $compiled['errors'])]);
        }

        $preview = TargetExpressionService::preview(Database::getInstance(), $expression, null);
        if (!($preview['valid'] ?? false)) {
            Response::validationError(['expression' => implode('; ', $preview['errors'] ?? ['Invalid expression'])]);
        }

        $treeJson = null;

        return [
            'expression'       => (string) ($preview['expression'] ?? $expression),
            'target_tree_json' => $treeJson,
        ];
    }

    private static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, 64);
    }

    private static function slugFromName(string $name): string
    {
        return self::normalizeSlug($name) ?: 'preset';
    }

    private static function assertSlugAvailable(Database $db, string $slug, ?int $orgId, ?int $excludeId = null): void
    {
        if ($orgId === null) {
            $sql    = 'SELECT id FROM target_presets WHERE slug = ? AND org_id IS NULL AND is_active = 1';
            $params = [$slug];
        } else {
            $sql    = 'SELECT id FROM target_presets WHERE slug = ? AND org_id = ? AND is_active = 1';
            $params = [$slug, $orgId];
        }

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $existing = $db->fetchValue($sql, $params);
        if ($existing) {
            Response::validationError(['slug' => 'Slug already in use']);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lookupBySlug(Database $db, string $slug, ?int $orgId): ?array
    {
        if ($orgId !== null) {
            $row = $db->fetchOne(
                'SELECT * FROM target_presets WHERE slug = ? AND org_id = ? AND is_active = 1',
                [$slug, $orgId]
            );
            if ($row) {
                return $row;
            }
        }

        return $db->fetchOne(
            'SELECT * FROM target_presets WHERE slug = ? AND org_id IS NULL AND is_active = 1',
            [$slug]
        ) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchRow(Database $db, int $id): ?array
    {
        return $db->fetchOne(
            'SELECT * FROM target_presets WHERE id = ? AND is_active = 1',
            [$id]
        ) ?: null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private static function assertCanAccess(array $user, array $row): void
    {
        if (in_array('super_admin', $user['roles'] ?? [], true)) {
            return;
        }

        if ($row['org_id'] === null) {
            return;
        }

        if ((int) $row['org_id'] === (int) ($user['org'] ?? 0)) {
            return;
        }

        Response::forbidden('Target preset is outside your organization');
    }

    /**
     * @param array<string, mixed> $token
     * @param array<string, mixed> $row
     */
    private static function assertTokenCanAccess(array $token, array $row): void
    {
        if ($row['org_id'] === null) {
            return;
        }

        if ((int) $row['org_id'] === (int) ($token['owner_org_id'] ?? 0)) {
            return;
        }

        Response::forbidden('Target preset is outside token organization scope');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private static function assertCanManage(array $user, array $row): void
    {
        self::assertCanAccess($user, $row);

        if (in_array('super_admin', $user['roles'] ?? [], true)) {
            return;
        }

        if ($row['org_id'] === null) {
            Response::forbidden('Only super admins can modify global presets');
        }

        if (!PermissionService::hasPermission(Database::getInstance(), $user, 'alert.send')) {
            Response::forbidden('alert.send permission required');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function resolveOrgIdForWrite(array $user, array $payload, bool $isSuperAdmin): ?int
    {
        if ($isSuperAdmin && ($payload['global'] ?? false)) {
            return null;
        }

        if ($isSuperAdmin && isset($payload['org_id']) && $payload['org_id'] !== '' && $payload['org_id'] !== null) {
            return (int) $payload['org_id'];
        }

        $orgId = (int) ($user['org'] ?? 0);

        return $orgId > 0 ? $orgId : null;
    }

    private static function resolveOrgContext(Request $request): ?int
    {
        if (!empty($request->token)) {
            return (int) $request->token['owner_org_id'];
        }

        if ($request->input('org_id') !== null && $request->input('org_id') !== '') {
            return (int) $request->input('org_id');
        }

        if (!empty($request->user['org'])) {
            return (int) $request->user['org'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeTree(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        if (is_array($json)) {
            return ($json['type'] ?? '') === 'group' ? $json : null;
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) && ($decoded['type'] ?? '') === 'group' ? $decoded : null;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeListRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'org_id'          => $row['org_id'] !== null ? (int) $row['org_id'] : null,
            'org_name'        => $row['org_name'] ?? null,
            'slug'            => $row['slug'],
            'name'            => $row['name'],
            'description'     => $row['description'],
            'expression'      => $row['expression'],
            'is_global'       => $row['org_id'] === null ? 1 : 0,
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
            'created_by_name' => $row['created_by_name'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function normalizeDetailRow(array $row): array
    {
        $list = self::normalizeListRow($row);
        $list['target_tree'] = self::decodeTree($row['target_tree']);

        return $list;
    }
}
