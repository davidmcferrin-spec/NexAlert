<?php
/**
 * NexAlert - Send Scope Service
 * Validates that alert targets fall within a sender's scoped role grants.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Response;
use NexAlert\Config\Database;

class SendScopeService
{
    /**
     * @param array<string, mixed> $user Request user payload
     * @param list<array<string, mixed>> $targetRows
     */
    public static function assertUserCanTarget(Database $db, array $user, array $targetRows, int $alertOrgId): void
    {
        if (PermissionService::isSuperAdmin($user)) {
            return;
        }

        $userId = (int) $user['uid'];
        $scopes = self::getSendScopes($db, $userId);

        if ($scopes === []) {
            Response::forbidden('No send scope assigned');
        }

        if (self::hasGlobalScope($scopes)) {
            return;
        }

        if (!self::alertOrgAllowed($db, $scopes, $alertOrgId)) {
            Response::forbidden('Alert organization is outside your send scope');
        }

        foreach ($targetRows as $i => $row) {
            $recipients = TagService::resolveTargetRow($db, $row);
            if ($recipients === []) {
                continue;
            }

            if (!self::rowAllowedByAnyScope($db, $scopes, $recipients)) {
                Response::forbidden(
                    'Target row ' . ($i + 1) . ' includes recipients outside your send scope'
                );
            }
        }
    }

    /**
     * Active sender scopes: user_roles rows whose role grants alert.send.
     *
     * @return list<array<string, mixed>>
     */
    public static function getSendScopes(Database $db, int $userId): array
    {
        $rows = $db->fetchAll(
            'SELECT ur.id, ur.org_id, ur.org_node_id, ur.group_id, ur.tag_id,
                    n.path AS node_path, n.org_id AS node_org_id,
                    g.name AS group_name, g.owner_org_id AS group_org_id,
                    t.name AS tag_name, t.owner_org_id AS tag_org_id
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id AND p.name = \'alert.send\'
             LEFT JOIN org_nodes n ON n.id = ur.org_node_id
             LEFT JOIN `groups` g ON g.id = ur.group_id
             LEFT JOIN tags t ON t.id = ur.tag_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [$userId]
        );

        $scopes = [];
        foreach ($rows as $row) {
            if (!empty($row['group_id'])) {
                $scopes[] = [
                    'type'     => 'group',
                    'group_id' => (int) $row['group_id'],
                    'org_id'   => (int) ($row['group_org_id'] ?? 0),
                    'label'    => (string) ($row['group_name'] ?? 'Group'),
                ];
            } elseif (!empty($row['tag_id'])) {
                $scopes[] = [
                    'type'    => 'tag',
                    'tag_id'  => (int) $row['tag_id'],
                    'org_id'  => $row['tag_org_id'] !== null ? (int) $row['tag_org_id'] : null,
                    'label'   => (string) ($row['tag_name'] ?? 'Tag'),
                ];
            } elseif (!empty($row['org_node_id'])) {
                $scopes[] = [
                    'type'        => 'node',
                    'org_id'      => (int) ($row['node_org_id'] ?? $row['org_id'] ?? 0),
                    'org_node_id' => (int) $row['org_node_id'],
                    'path'        => (string) ($row['node_path'] ?? ''),
                ];
            } elseif (!empty($row['org_id'])) {
                $scopes[] = [
                    'type'   => 'org',
                    'org_id' => (int) $row['org_id'],
                ];
            } else {
                $scopes[] = ['type' => 'global'];
            }
        }

        return $scopes;
    }

    /**
     * @param list<array<string, mixed>> $scopes
     */
    private static function hasGlobalScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (($scope['type'] ?? '') === 'global') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $scopes
     */
    private static function alertOrgAllowed(Database $db, array $scopes, int $alertOrgId): bool
    {
        foreach ($scopes as $scope) {
            $type = $scope['type'] ?? '';

            if ($type === 'org' && (int) $scope['org_id'] === $alertOrgId) {
                return true;
            }

            if ($type === 'node' && (int) $scope['org_id'] === $alertOrgId) {
                return true;
            }

            if ($type === 'group' && (int) ($scope['org_id'] ?? 0) === $alertOrgId) {
                return true;
            }

            if ($type === 'tag') {
                $tagOrgId = $scope['org_id'] ?? null;
                if ($tagOrgId === null || (int) $tagOrgId === $alertOrgId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $scopes
     * @param list<int> $recipients
     */
    private static function rowAllowedByAnyScope(Database $db, array $scopes, array $recipients): bool
    {
        foreach ($scopes as $scope) {
            if (($scope['type'] ?? '') === 'global') {
                return true;
            }

            $population = self::scopePopulation($db, $scope);
            if ($population === null) {
                return true;
            }

            $allowed = true;
            foreach ($recipients as $userId) {
                if (!in_array($userId, $population, true)) {
                    $allowed = false;
                    break;
                }
            }

            if ($allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<int>|null Null means unrestricted (global)
     */
    private static function scopePopulation(Database $db, array $scope): ?array
    {
        return match ($scope['type'] ?? '') {
            'global' => null,
            'org'    => self::orgPopulation($db, (int) $scope['org_id']),
            'node'   => self::nodePopulation($db, (string) ($scope['path'] ?? '')),
            'group'  => TagService::resolveGroupMembers($db, (int) $scope['group_id']),
            'tag'    => self::tagPopulation($db, (int) $scope['tag_id']),
            default  => [],
        };
    }

    /** @return list<int> */
    private static function orgPopulation(Database $db, int $orgId): array
    {
        $rows = $db->fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             WHERE u.is_active = 1
               AND (
                   u.home_org_id = ?
                   OR EXISTS (
                       SELECT 1 FROM user_org_memberships m
                       WHERE m.user_id = u.id AND m.org_id = ? AND m.is_active = 1
                   )
               )',
            [$orgId, $orgId]
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** @return list<int> */
    private static function nodePopulation(Database $db, string $path): array
    {
        if ($path === '') {
            return [];
        }

        $rows = $db->fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             JOIN user_org_memberships m ON m.user_id = u.id AND m.is_active = 1
             JOIN org_nodes n ON n.id = m.org_node_id
             WHERE u.is_active = 1 AND n.path LIKE ?',
            [$path . '%']
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** @return list<int> */
    private static function tagPopulation(Database $db, int $tagId): array
    {
        $rows = $db->fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             JOIN tag_assignments ta ON ta.user_id = u.id AND ta.is_active = 1
             WHERE u.is_active = 1 AND ta.tag_id = ?',
            [$tagId]
        );

        return array_map('intval', array_column($rows, 'id'));
    }
}
