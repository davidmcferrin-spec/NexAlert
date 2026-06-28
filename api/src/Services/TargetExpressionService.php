<?php
/**
 * NexAlert - Target Expression Service
 * Parses canonical targeting strings, resolves identifiers to alert_targets rows,
 * and previews recipient sets via TagService::resolveTargetRow().
 *
 * Expression grammar (nested AND/OR):
 *   org:nexstar AND (tag:eng OR tag:noc) OR group:noc@nexstar
 *   tag:a,b,c  →  shorthand for tag:a OR tag:b OR tag:c
 *
 * Dimensions: org, node, tag, group, user
 * - Parsed to AST, normalized to DNF, stored as alert_targets rows (OR of AND clauses)
 * - Compound AND (e.g. tag:a AND tag:b) uses conj_terms JSON column
 * - group:slug@org-slug disambiguates groups with the same slug in different orgs
 * - node:123 uses numeric id; node:slug when unique among active nodes
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;

class TargetExpressionService
{
    /** @var string[] */
    private const DIM_ORDER = ['org', 'node', 'tag', 'group', 'user'];

    /**
     * @return array{rows: list<array<string, string>>, errors: string[], ast?: array, expression?: string}
     */
    public static function parse(string $expression): array
    {
        $compiled = self::compileExpressionAst($expression);
        if ($compiled['errors'] !== []) {
            return ['rows' => [], 'errors' => $compiled['errors']];
        }

        return [
            'rows'       => $compiled['legacy_rows'],
            'errors'     => [],
            'ast'        => $compiled['ast'],
            'expression' => $compiled['expression'],
        ];
    }

    /**
     * Compile expression or builder tree to alert target rows.
     *
     * @param array<string, mixed>|null $tree
     * @return array{
     *   legacy_rows: list<array<string, string>>,
     *   ast: ?array,
     *   expression: string,
     *   errors: string[]
     * }
     */
    public static function compileExpressionAst(string $expression, ?array $tree = null): array
    {
        if ($tree !== null) {
            $parsed = TargetAstService::treeToAst($tree);
        } else {
            $parsed = TargetAstService::parseExpression($expression);
        }

        if ($parsed['errors'] !== []) {
            return [
                'legacy_rows' => [],
                'ast'         => null,
                'expression'  => trim($expression),
                'errors'      => $parsed['errors'],
            ];
        }

        $ast = $parsed['ast'];
        if ($ast === null) {
            return [
                'legacy_rows' => [],
                'ast'         => null,
                'expression'  => '',
                'errors'      => ['Expression is empty'],
            ];
        }

        $dnf = TargetAstService::astToDnf($ast);
        if ($dnf['errors'] !== []) {
            return [
                'legacy_rows' => [],
                'ast'         => $ast,
                'expression'  => TargetAstService::astToExpression($ast),
                'errors'      => $dnf['errors'],
            ];
        }

        if ($dnf['conjunctions'] === []) {
            return [
                'legacy_rows' => [],
                'ast'         => $ast,
                'expression'  => '',
                'errors'      => ['No valid targeting terms found'],
            ];
        }

        return [
            'legacy_rows' => self::dnfToLegacyRows($dnf['conjunctions']),
            'ast'         => $ast,
            'expression'  => TargetAstService::dnfToExpression($dnf['conjunctions']),
            'errors'      => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $structured Flat rows and/or builder tree
     * @return list<array<string, string>>
     */
    public static function structuredToRows(array $structured): array
    {
        if ($structured !== [] && ($structured['type'] ?? null) === 'group') {
            $compiled = self::compileExpressionAst('', $structured);

            return $compiled['legacy_rows'];
        }

        $rows = [];
        foreach ($structured as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === 'group') {
                $compiled = self::compileExpressionAst('', $item);

                return $compiled['legacy_rows'];
            }

            $row = [];
            foreach (self::DIM_ORDER as $dim) {
                if (!empty($item[$dim])) {
                    $row[$dim] = strtolower(trim((string) $item[$dim]));
                }
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<list<array<string, mixed>>> $conjunctions
     * @return list<array<string, string>>
     */
    private static function dnfToLegacyRows(array $conjunctions): array
    {
        $rows = [];
        foreach ($conjunctions as $conj) {
            $row = [];
            foreach ($conj as $term) {
                $dim = (string) ($term['dim'] ?? '');
                if (in_array($dim, self::DIM_ORDER, true) && ($term['value'] ?? '') !== '') {
                    $row[$dim] = (string) $term['value'];
                }
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<list<array<string, mixed>>> $conjunctions
     */
    public static function dnfToAlertTargets(Database $db, array $conjunctions): array
    {
        $resolveRows = TargetAstService::dnfToResolveRows($conjunctions);
        $targets     = [];
        $errors      = [];
        $warnings    = [];

        foreach ($resolveRows as $idx => $resolveRow) {
            if (!empty($resolveRow['conj_terms']) && is_array($resolveRow['conj_terms'])) {
                $resolved = self::resolveConjunctionTerms($db, $resolveRow['conj_terms'], $idx + 1);
                $errors   = array_merge($errors, $resolved['errors']);
                if ($resolved['target'] !== null) {
                    $targets[] = $resolved['target'];
                }
                continue;
            }

            $legacy = $resolveRow['_legacy'] ?? [];
            if ($legacy === []) {
                continue;
            }

            $resolved = self::resolveRowDimensions($db, $legacy, $idx + 1);
            $errors   = array_merge($errors, $resolved['errors']);
            $warnings = array_merge($warnings, $resolved['warnings']);
            if ($resolved['target'] !== null) {
                $targets[] = $resolved['target'];
            }
        }

        return [
            'expression' => TargetAstService::dnfToExpression($conjunctions),
            'targets'    => $targets,
            'errors'     => $errors,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Execute compound conjunction and return matching user IDs.
     *
     * @param list<array{dim: string, value: string}> $terms
     * @return int[]
     */
    public static function resolveConjunctionUserIds(Database $db, array $terms): array
    {
        $built = self::buildConjunctionSql($db, $terms, 1);
        if ($built['errors'] !== [] || $built['sql'] === null) {
            return [];
        }

        $rows = $db->fetchAll($built['sql'], $built['params']);

        return array_map('intval', array_column($rows, 'id'));
    }

    /**
     * @param list<array{dim: string, value: string}> $terms
     * @return array{sql: ?string, params: array, errors: string[]}
     */
    private static function buildConjunctionSql(Database $db, array $terms, int $rowNum): array
    {
        $errors = [];
        $sql    = 'SELECT DISTINCT u.id FROM users u WHERE u.is_active = 1';
        $params = [];

        foreach ($terms as $term) {
            $dim   = strtolower((string) ($term['dim'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($value === '' || !in_array($dim, self::DIM_ORDER, true)) {
                return ['sql' => null, 'params' => [], 'errors' => ["Row {$rowNum}: invalid conjunction term"]];
            }

            if ($dim === 'org') {
                $org = $db->fetchOne(
                    'SELECT id, slug FROM organizations WHERE slug = ? AND is_active = 1',
                    [strtolower($value)]
                );
                if (!$org) {
                    return ['sql' => null, 'params' => [], 'errors' => ["Row {$rowNum}: unknown org \"{$value}\""]];
                }
                $sql     .= ' AND u.home_org_id = ?';
                $params[] = (int) $org['id'];
            } elseif ($dim === 'node') {
                $node = self::resolveNode($db, $value);
                if ($node === null || isset($node['ambiguous'])) {
                    return ['sql' => null, 'params' => [], 'errors' => ["Row {$rowNum}: unknown or ambiguous node \"{$value}\""]];
                }
                $nodeRow = $db->fetchOne('SELECT path FROM org_nodes WHERE id = ?', [(int) $node['id']]);
                $sql     .= ' AND EXISTS (
                    SELECT 1 FROM user_org_memberships m
                    JOIN org_nodes n ON n.id = m.org_node_id
                    WHERE m.user_id = u.id AND m.is_active = 1 AND n.path LIKE ?
                )';
                $params[] = $nodeRow['path'] . '%';
            } elseif ($dim === 'tag') {
                $tag = $db->fetchOne(
                    'SELECT id FROM tags WHERE slug = ? AND is_active = 1',
                    [strtolower($value)]
                );
                if (!$tag) {
                    return ['sql' => null, 'params' => [], 'errors' => ["Row {$rowNum}: unknown tag \"{$value}\""]];
                }
                $sql     .= ' AND EXISTS (
                    SELECT 1 FROM tag_assignments ta
                    WHERE ta.user_id = u.id AND ta.tag_id = ? AND ta.is_active = 1
                )';
                $params[] = (int) $tag['id'];
            } elseif ($dim === 'group') {
                $group = self::resolveGroup($db, $value);
                if ($group === null || isset($group['ambiguous'])) {
                    return ['sql' => null, 'params' => [], 'errors' => ["Row {$rowNum}: unknown or ambiguous group \"{$value}\""]];
                }
                $groupUserIds = TagService::resolveGroupMembers($db, (int) $group['id']);
                if ($groupUserIds === []) {
                    $sql .= ' AND 1=0';
                } else {
                    [$placeholders, $params] = $db->inClause($groupUserIds, $params);
                    $sql .= " AND u.id IN ({$placeholders})";
                }
            } elseif ($dim === 'user') {
                $user = self::resolveUser($db, $value);
                if (!$user) {
                    return ['sql' => null, 'params' => [], 'errors' => ["Row {$rowNum}: unknown user \"{$value}\""]];
                }
                $sql     .= ' AND u.id = ?';
                $params[] = (int) $user['id'];
            }
        }

        return ['sql' => $sql, 'params' => $params, 'errors' => $errors];
    }

    /**
     * Resolve compound AND terms (e.g. tag:a AND tag:b AND org:x).
     *
     * @param list<array{dim: string, value: string}> $terms
     * @return array{target: ?array<string, mixed>, errors: string[]}
     */
    public static function resolveConjunctionTerms(Database $db, array $terms, int $rowNum): array
    {
        $built = self::buildConjunctionSql($db, $terms, $rowNum);
        if ($built['errors'] !== []) {
            return ['target' => null, 'errors' => $built['errors']];
        }

        $labels = [];
        foreach ($terms as $term) {
            $labels[] = ($term['dim'] ?? '') . ':' . ($term['value'] ?? '');
        }

        $target = [
            'target_org_id'   => null,
            'target_node_id'  => null,
            'target_tag_id'   => null,
            'target_group_id' => null,
            'target_user_id'  => null,
            'target_label'    => implode(' + ', $labels),
            'conj_terms'      => $terms,
        ];

        return ['target' => $target, 'errors' => []];
    }

    /**
     * @return array{rows: list<array<string, string>>, errors: string[]}
     */
    public static function parseLegacy(string $expression): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return ['rows' => [], 'errors' => ['Expression is empty']];
        }

        $orTerms = self::splitTopLevel($expression, 'OR');
        $rows    = [];
        $errors  = [];

        foreach ($orTerms as $i => $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }

            $term     = self::stripOuterParens($term);
            $andParts = self::splitTopLevel($term, 'AND');
            $row      = [];

            foreach ($andParts as $part) {
                $part = trim($part);
                if (!preg_match('/^(org|node|tag|group|user):(.+)$/i', $part, $m)) {
                    $errors[] = 'Invalid dimension in OR term ' . ($i + 1) . ': ' . $part;
                    continue 2;
                }

                $type = strtolower($m[1]);
                $ident = trim($m[2]);
                if ($ident === '') {
                    $errors[] = "Missing identifier for {$type}:";
                    continue 2;
                }

                if (isset($row[$type])) {
                    $errors[] = "Duplicate {$type} in the same AND group (OR term " . ($i + 1) . ')';
                    continue 2;
                }

                $row[$type] = $ident;
            }

            if ($row === []) {
                $errors[] = 'Empty OR term ' . ($i + 1);
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'No valid targeting terms found';
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param list<array<string, string>> $rows
     */
    public static function rowsToExpression(array $rows): string
    {
        $terms = [];

        foreach ($rows as $row) {
            $parts = [];
            foreach (self::DIM_ORDER as $dim) {
                if (!empty($row[$dim])) {
                    $parts[] = $dim . ':' . $row[$dim];
                }
            }

            if ($parts === []) {
                continue;
            }

            $terms[] = count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
        }

        return implode(' OR ', $terms);
    }

    /**
     * @param list<array<string, mixed>> $structured  Builder rows from UI (deprecated flat list)
     * @return list<array<string, string>>
     * @deprecated Use structuredToRows() which also accepts target trees
     */
    public static function structuredToFlatRows(array $structured): array
    {
        $rows = [];

        foreach ($structured as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [];
            foreach (self::DIM_ORDER as $dim) {
                if (!empty($item[$dim])) {
                    $row[$dim] = strtolower(trim((string) $item[$dim]));
                }
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     * @return array{
     *   expression: string,
     *   targets: list<array<string, mixed>>,
     *   errors: string[],
     *   warnings: string[]
     * }
     */
    public static function rowsToAlertTargets(Database $db, array $rows): array
    {
        $targets  = [];
        $errors   = [];
        $warnings = [];

        foreach ($rows as $idx => $row) {
            $resolved = self::resolveRowDimensions($db, $row, $idx + 1);
            $errors   = array_merge($errors, $resolved['errors']);
            $warnings = array_merge($warnings, $resolved['warnings']);

            if ($resolved['target'] !== null) {
                $targets[] = $resolved['target'];
            }
        }

        return [
            'expression' => self::rowsToExpression($rows),
            'targets'    => $targets,
            'errors'     => $errors,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Full preview: parse expression, builder tree, or legacy flat rows.
     *
     * @param list<array<string, string>>|null $rows Legacy flat OR rows
     * @param array<string, mixed>|null $tree Nested builder tree
     * @return array<string, mixed>
     */
    public static function preview(
        Database $db,
        ?string $expression = null,
        ?array $rows = null,
        ?array $tree = null
    ): array {
        $parseErrors = [];
        $compiled    = null;
        $dnf         = null;

        if ($tree !== null) {
            $compiled = self::compileExpressionAst('', $tree);
            $parseErrors = $compiled['errors'];
            if ($parseErrors === [] && $compiled['ast'] !== null) {
                $dnf = TargetAstService::astToDnf($compiled['ast']);
                $parseErrors = $dnf['errors'];
            }
        } elseif ($rows === null) {
            $compiled = self::compileExpressionAst((string) $expression);
            $parseErrors = $compiled['errors'];
            if ($parseErrors === [] && $compiled['ast'] !== null) {
                $dnf = TargetAstService::astToDnf($compiled['ast']);
                $parseErrors = $dnf['errors'];
            }
        } else {
            $ast = TargetAstService::flatRowsToAst($rows);
            $dnf = TargetAstService::astToDnf($ast);
            $parseErrors = $dnf['errors'];
            $compiled = [
                'expression' => TargetAstService::dnfToExpression($dnf['conjunctions']),
                'ast'        => $ast,
            ];
        }

        if ($parseErrors !== []) {
            return [
                'valid'      => false,
                'expression' => trim((string) ($compiled['expression'] ?? $expression)),
                'errors'     => $parseErrors,
                'warnings'   => [],
                'targets'    => [],
                'users'      => [],
                'counts'     => ['total_unique' => 0, 'sms_eligible' => 0, 'row_totals' => []],
                'ast'        => $compiled['ast'] ?? null,
            ];
        }

        $conjunctions = $dnf['conjunctions'] ?? [];
        if ($conjunctions === []) {
            return [
                'valid'      => false,
                'expression' => trim((string) ($compiled['expression'] ?? $expression)),
                'errors'     => ['No valid targeting terms found'],
                'warnings'   => [],
                'targets'    => [],
                'users'      => [],
                'counts'     => ['total_unique' => 0, 'sms_eligible' => 0, 'row_totals' => []],
            ];
        }

        $converted = self::dnfToAlertTargets($db, $conjunctions);
        if ($converted['errors'] !== []) {
            return [
                'valid'      => false,
                'expression' => $converted['expression'],
                'errors'     => $converted['errors'],
                'warnings'   => $converted['warnings'],
                'targets'    => [],
                'users'      => [],
                'counts'     => ['total_unique' => 0, 'sms_eligible' => 0, 'row_totals' => []],
            ];
        }

        $targets    = $converted['targets'];
        $matchedMap = [];
        $rowTotals  = [];

        foreach ($targets as $i => $target) {
            $rowUsers = TagService::resolveTargetRow($db, $target);
            $rowTotals[] = [
                'row'   => $i + 1,
                'label' => $target['target_label'] ?? ('Row ' . ($i + 1)),
                'count' => count($rowUsers),
            ];

            foreach ($rowUsers as $uid) {
                if (!isset($matchedMap[$uid])) {
                    $matchedMap[$uid] = [];
                }
                $matchedMap[$uid][] = $target['target_label'] ?? ('Row ' . ($i + 1));
            }
        }

        $userIds = array_keys($matchedMap);
        $users   = [];

        if ($userIds !== []) {
            [$placeholders, $params] = $db->inClause($userIds);
            $users = $db->fetchAll(
                "SELECT u.id, u.username, u.display_name, u.first_name, u.last_name,
                        o.display_name AS home_org_name,
                        (SELECT COUNT(*) FROM user_sms_consent sc
                         WHERE sc.user_id = u.id AND sc.status = 'confirmed') AS sms_confirmed
                 FROM users u
                 LEFT JOIN organizations o ON o.id = u.home_org_id
                 WHERE u.id IN ({$placeholders})
                 ORDER BY u.last_name, u.first_name, u.username",
                $params
            );
        }

        $smsEligible = 0;
        foreach ($users as &$user) {
            $uid = (int) $user['id'];
            $user['matched_by'] = $matchedMap[$uid] ?? [];
            $user['sms_eligible'] = ((int) ($user['sms_confirmed'] ?? 0)) > 0 ? 1 : 0;
            if ($user['sms_eligible'] === 1) {
                $smsEligible++;
            }
            unset($user['sms_confirmed']);
        }
        unset($user);

        $canonical = $converted['expression'];

        return [
            'valid'      => true,
            'expression' => $canonical,
            'errors'     => [],
            'warnings'   => $converted['warnings'],
            'targets'    => $targets,
            'users'      => $users,
            'counts'     => [
                'total_unique' => count($users),
                'sms_eligible' => $smsEligible,
                'row_totals'   => $rowTotals,
            ],
            'ast'        => $compiled['ast'] ?? null,
            'target_tree' => $tree,
            'rest_api'   => [
                'method' => 'POST',
                'path'   => '/api/v1/alert',
                'body'   => [
                    'severity' => 'test',
                    'subject'  => 'Test alert subject',
                    'body'     => 'Test alert body',
                    'channels' => ['email'],
                    'targets'  => $canonical,
                ],
            ],
        ];
    }

    /**
     * @return array{orgs: array, tags: array, groups: array, nodes: array, users: array}
     */
    public static function searchEntities(Database $db, string $query, ?int $orgId = null, int $limit = 20): array
    {
        $query = trim($query);
        $like  = $query !== '' ? '%' . $query . '%' : '%';

        $orgWhere  = 'o.is_active = 1';
        $orgParams = [];
        if ($orgId !== null) {
            $orgWhere   .= ' AND o.id = ?';
            $orgParams[] = $orgId;
        }
        if ($query !== '') {
            $orgWhere .= ' AND (o.name LIKE ? OR o.slug LIKE ? OR o.display_name LIKE ?)';
            $orgParams = array_merge($orgParams, [$like, $like, $like]);
        }

        $orgs = $db->fetchAll(
            "SELECT o.id, o.slug, o.display_name AS name
             FROM organizations o
             WHERE {$orgWhere}
             ORDER BY o.display_name ASC
             LIMIT ?",
            array_merge($orgParams, [$limit])
        );

        $tagWhere  = 't.is_active = 1';
        $tagParams = [];
        if ($query !== '') {
            $tagWhere .= ' AND (t.name LIKE ? OR t.slug LIKE ?)';
            $tagParams = [$like, $like];
        }

        $tags = $db->fetchAll(
            "SELECT t.id, t.slug, t.name, t.is_system
             FROM tags t
             WHERE {$tagWhere}
             ORDER BY t.name ASC
             LIMIT ?",
            array_merge($tagParams, [$limit])
        );

        $groupWhere  = 'g.is_active = 1';
        $groupParams = [];
        if ($orgId !== null) {
            $groupWhere   .= ' AND g.owner_org_id = ?';
            $groupParams[] = $orgId;
        }
        if ($query !== '') {
            $groupWhere .= ' AND (g.name LIKE ? OR g.slug LIKE ?)';
            $groupParams = array_merge($groupParams, [$like, $like]);
        }

        $groups = $db->fetchAll(
            "SELECT g.id, g.slug, g.name, o.slug AS org_slug, o.display_name AS org_name
             FROM `groups` g
             JOIN organizations o ON o.id = g.owner_org_id
             WHERE {$groupWhere}
             ORDER BY g.name ASC
             LIMIT ?",
            array_merge($groupParams, [$limit])
        );

        $nodeWhere  = 'n.is_active = 1';
        $nodeParams = [];
        if ($orgId !== null) {
            $nodeWhere   .= ' AND n.org_id = ?';
            $nodeParams[] = $orgId;
        }
        if ($query !== '') {
            $nodeWhere .= ' AND (n.name LIKE ? OR n.slug LIKE ?)';
            $nodeParams = array_merge($nodeParams, [$like, $like]);
        }

        $nodes = $db->fetchAll(
            "SELECT n.id, n.slug, n.name, n.org_id, n.path, n.node_type,
                    o.slug AS org_slug, o.display_name AS org_name
             FROM org_nodes n
             JOIN organizations o ON o.id = n.org_id
             WHERE {$nodeWhere}
             ORDER BY n.path ASC
             LIMIT ?",
            array_merge($nodeParams, [$limit])
        );

        foreach ($nodes as &$node) {
            $node['breadcrumb'] = self::nodeBreadcrumb($db, $node);
            $node['expression'] = 'node:' . $node['id'];
        }
        unset($node);

        foreach ($groups as &$group) {
            $group['expression'] = self::groupExpression($group['slug'], $group['org_slug']);
        }
        unset($group);

        $userWhere  = 'u.is_active = 1';
        $userParams = [];
        if ($orgId !== null) {
            $userWhere   .= ' AND u.home_org_id = ?';
            $userParams[] = $orgId;
        }
        if ($query !== '') {
            $userWhere .= ' AND (u.username LIKE ? OR u.display_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $userParams = array_merge($userParams, [$like, $like, $like, $like]);
        }

        $users = $db->fetchAll(
            "SELECT u.id, u.username, u.display_name
             FROM users u
             WHERE {$userWhere}
             ORDER BY u.last_name, u.first_name
             LIMIT ?",
            array_merge($userParams, [$limit])
        );

        return [
            'orgs'   => $orgs,
            'tags'   => $tags,
            'groups' => $groups,
            'nodes'  => $nodes,
            'users'  => $users,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     * @return array{target: ?array<string, mixed>, errors: string[], warnings: string[]}
     */
    private static function resolveRowDimensions(Database $db, array $row, int $rowNum): array
    {
        $errors   = [];
        $warnings = [];
        $target   = [
            'target_org_id'   => null,
            'target_node_id'  => null,
            'target_tag_id'   => null,
            'target_group_id' => null,
            'target_user_id'  => null,
            'target_label'    => '',
        ];
        $labels = [];

        if (!empty($row['org'])) {
            $org = $db->fetchOne(
                'SELECT id, slug, display_name FROM organizations WHERE slug = ? AND is_active = 1',
                [strtolower($row['org'])]
            );
            if (!$org) {
                $errors[] = "Row {$rowNum}: unknown org \"{$row['org']}\"";
            } else {
                $target['target_org_id'] = (int) $org['id'];
                $labels[] = 'org:' . $org['slug'];
            }
        }

        if (!empty($row['node'])) {
            $node = self::resolveNode($db, $row['node']);
            if ($node === null) {
                $errors[] = "Row {$rowNum}: unknown or ambiguous node \"{$row['node']}\"";
            } elseif (isset($node['ambiguous'])) {
                $errors[] = "Row {$rowNum}: ambiguous node slug \"{$row['node']}\" — use node:ID (e.g. node:{$node['ids'][0]})";
            } else {
                $target['target_node_id'] = (int) $node['id'];
                $labels[] = 'node:' . $node['id'];
            }
        }

        if (!empty($row['tag'])) {
            $tag = $db->fetchOne(
                'SELECT id, slug, name FROM tags WHERE slug = ? AND is_active = 1',
                [strtolower($row['tag'])]
            );
            if (!$tag) {
                $errors[] = "Row {$rowNum}: unknown tag \"{$row['tag']}\"";
            } else {
                $target['target_tag_id'] = (int) $tag['id'];
                $labels[] = 'tag:' . $tag['slug'];
            }
        }

        if (!empty($row['group'])) {
            $group = self::resolveGroup($db, $row['group']);
            if ($group === null) {
                $errors[] = "Row {$rowNum}: unknown group \"{$row['group']}\"";
            } elseif (isset($group['ambiguous'])) {
                $options = implode(', ', array_map(
                    fn (array $g): string => self::groupExpression($g['slug'], $g['org_slug']),
                    $group['matches']
                ));
                $errors[] = "Row {$rowNum}: ambiguous group \"{$row['group']}\" — use {$options}";
            } else {
                $target['target_group_id'] = (int) $group['id'];
                $labels[] = self::groupExpression($group['slug'], $group['org_slug']);
            }
        }

        if (!empty($row['user'])) {
            $user = self::resolveUser($db, $row['user']);
            if (!$user) {
                $errors[] = "Row {$rowNum}: unknown user \"{$row['user']}\"";
            } else {
                $target['target_user_id'] = (int) $user['id'];
                $labels[] = 'user:' . $user['username'];
            }
        }

        if ($errors !== []) {
            return ['target' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        $target['target_label'] = implode(' + ', $labels);

        return ['target' => $target, 'errors' => [], 'warnings' => $warnings];
    }

    /** @return array<string, mixed>|null */
    private static function resolveNode(Database $db, string $ident): ?array
    {
        if (ctype_digit($ident)) {
            $node = $db->fetchOne(
                'SELECT id, slug, name FROM org_nodes WHERE id = ? AND is_active = 1',
                [(int) $ident]
            );

            return $node ?: null;
        }

        $slug   = strtolower($ident);
        $matches = $db->fetchAll(
            'SELECT id, slug, name, org_id FROM org_nodes WHERE slug = ? AND is_active = 1',
            [$slug]
        );

        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return ['ambiguous' => true, 'ids' => array_column($matches, 'id')];
    }

    /** @return array<string, mixed>|null */
    private static function resolveGroup(Database $db, string $ident): ?array
    {
        $orgSlug = null;
        $slug    = strtolower($ident);

        if (str_contains($ident, '@')) {
            [$slug, $orgSlug] = explode('@', $ident, 2);
            $slug    = strtolower(trim($slug));
            $orgSlug = strtolower(trim($orgSlug));
        }

        if ($orgSlug !== null && $orgSlug !== '') {
            $group = $db->fetchOne(
                'SELECT g.id, g.slug, g.name, o.slug AS org_slug
                 FROM `groups` g
                 JOIN organizations o ON o.id = g.owner_org_id
                 WHERE g.slug = ? AND o.slug = ? AND g.is_active = 1',
                [$slug, $orgSlug]
            );

            return $group ?: null;
        }

        $matches = $db->fetchAll(
            'SELECT g.id, g.slug, g.name, o.slug AS org_slug
             FROM `groups` g
             JOIN organizations o ON o.id = g.owner_org_id
             WHERE g.slug = ? AND g.is_active = 1',
            [$slug]
        );

        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return ['ambiguous' => true, 'matches' => $matches];
    }

    /** @return ?array{id: int, username: string} */
    private static function resolveUser(Database $db, string $ident): ?array
    {
        if (ctype_digit($ident)) {
            return $db->fetchOne(
                'SELECT id, username FROM users WHERE id = ? AND is_active = 1',
                [(int) $ident]
            ) ?: null;
        }

        return $db->fetchOne(
            'SELECT id, username FROM users WHERE username = ? AND is_active = 1',
            [strtolower($ident)]
        ) ?: null;
    }

    /** @param list<array<string, mixed>> $targets */
    private static function canonicalizeFromTargets(array $targets): string
    {
        $rows = [];

        foreach ($targets as $target) {
            $row = [];
            $label = $target['target_label'] ?? '';
            foreach (preg_split('/\s+\+\s+/', $label) ?: [] as $part) {
                if (preg_match('/^(org|node|tag|group|user):(.+)$/', trim($part), $m)) {
                    $row[$m[1]] = $m[2];
                }
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return self::rowsToExpression($rows);
    }

    private static function groupExpression(string $slug, string $orgSlug): string
    {
        return 'group:' . $slug . '@' . $orgSlug;
    }

    /** @param array<string, mixed> $node */
    private static function nodeBreadcrumb(Database $db, array $node): string
    {
        $pathIds = array_filter(explode('/', (string) ($node['path'] ?? '')));
        if ($pathIds === []) {
            return (string) ($node['name'] ?? '');
        }

        [$placeholders, $params] = $db->inClause(array_map('intval', $pathIds));
        $ancestors = $db->fetchAll(
            "SELECT id, name FROM org_nodes WHERE id IN ({$placeholders})",
            $params
        );
        $byId = [];
        foreach ($ancestors as $a) {
            $byId[(int) $a['id']] = $a['name'];
        }

        $parts = [(string) ($node['org_name'] ?? '')];
        foreach ($pathIds as $id) {
            $parts[] = $byId[(int) $id] ?? ('#' . $id);
        }

        return implode(' → ', array_filter($parts));
    }

    /** @return string[] */
    private static function splitTopLevel(string $expr, string $delimiter): array
    {
        $parts   = [];
        $depth   = 0;
        $current = '';
        $len     = strlen($expr);
        $delLen  = strlen($delimiter);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                $current .= $ch;
                continue;
            }

            if ($depth === 0 && strncasecmp(substr($expr, $i), $delimiter, $delLen) === 0) {
                $before = $i === 0 ? ' ' : $expr[$i - 1];
                $after  = ($i + $delLen >= $len) ? ' ' : $expr[$i + $delLen];
                if ((ctype_space($before) || $before === '(') && (ctype_space($after) || $after === ')')) {
                    if (trim($current) !== '') {
                        $parts[] = trim($current);
                    }
                    $current = '';
                    $i      += $delLen - 1;
                    continue;
                }
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private static function stripOuterParens(string $term): string
    {
        $term = trim($term);
        while (str_starts_with($term, '(') && str_ends_with($term, ')')) {
            $depth = 0;
            $valid = true;
            $len   = strlen($term);
            for ($i = 0; $i < $len; $i++) {
                if ($term[$i] === '(') {
                    $depth++;
                } elseif ($term[$i] === ')') {
                    $depth--;
                }
                if ($depth === 0 && $i < $len - 1) {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                break;
            }
            $term = trim(substr($term, 1, -1));
        }

        return $term;
    }
}
