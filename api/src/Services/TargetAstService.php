<?php
/**
 * NexAlert - Target AST Service
 * Parses nested AND/OR targeting expressions, normalizes to DNF,
 * and converts builder trees to flat conjunctions for alert_targets resolution.
 */

declare(strict_types=1);

namespace NexAlert\Services;

class TargetAstService
{
    /** @var string[] */
    public const DIMS = ['org', 'node', 'tag', 'group', 'user'];

    /**
     * Parse expression string into an AST.
     *
     * @return array{ast: ?array, errors: string[]}
     */
    public static function parseExpression(string $expression): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return ['ast' => null, 'errors' => ['Expression is empty']];
        }

        try {
            $pos = 0;
            $ast = self::parseOrExpr($expression, $pos);
            self::skipWs($expression, $pos);
            if ($pos < strlen($expression)) {
                return ['ast' => null, 'errors' => ['Unexpected text after expression: ' . substr($expression, $pos, 40)]];
            }

            return ['ast' => self::normalizeAst($ast), 'errors' => []];
        } catch (\InvalidArgumentException $e) {
            return ['ast' => null, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $tree Builder JSON from UI
     * @return array{ast: ?array, errors: string[]}
     */
    public static function treeToAst(array $tree): array
    {
        try {
            return ['ast' => self::normalizeAst(self::treeNodeToAst($tree)), 'errors' => []];
        } catch (\InvalidArgumentException $e) {
            return ['ast' => null, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Flat OR-of-AND rows (legacy builder) → AST.
     *
     * @param list<array<string, string>> $rows
     */
    public static function flatRowsToAst(array $rows): array
    {
        $children = [];
        foreach ($rows as $row) {
            $terms = [];
            foreach (self::DIMS as $dim) {
                if (!empty($row[$dim])) {
                    $terms[] = self::termNode($dim, (string) $row[$dim]);
                }
            }
            if ($terms === []) {
                continue;
            }
            $children[] = count($terms) === 1
                ? $terms[0]
                : ['type' => 'group', 'op' => 'AND', 'children' => $terms];
        }

        if ($children === []) {
            return ['type' => 'group', 'op' => 'OR', 'children' => []];
        }

        return count($children) === 1
            ? $children[0]
            : ['type' => 'group', 'op' => 'OR', 'children' => $children];
    }

    /**
     * DNF: list of conjunctions; each conjunction is a list of term nodes.
     *
     * @return array{conjunctions: list<list<array<string, mixed>>>, errors: string[]}
     */
    public static function astToDnf(array $ast): array
    {
        if (($ast['type'] ?? '') === 'group' && ($ast['children'] ?? []) === []) {
            return ['conjunctions' => [], 'errors' => ['Target tree is empty']];
        }

        try {
            $conjunctions = self::dnfFromNode($ast);

            return ['conjunctions' => $conjunctions, 'errors' => []];
        } catch (\InvalidArgumentException $e) {
            return ['conjunctions' => [], 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * @param list<list<array<string, mixed>>> $conjunctions
     * @return list<array<string, mixed>> Rows for resolveRow / conj_terms
     */
    public static function dnfToResolveRows(array $conjunctions): array
    {
        $rows = [];
        foreach ($conjunctions as $conj) {
            if ($conj === []) {
                continue;
            }
            $rows[] = self::conjunctionToResolveRow($conj);
        }

        return $rows;
    }

    /**
     * Pretty canonical expression from AST.
     */
    public static function astToExpression(array $ast): string
    {
        return self::stringifyNode($ast, false);
    }

    /**
     * Expression from DNF rows (flat OR of ANDs).
     *
     * @param list<array<string, string>> $rows
     */
    public static function rowsToExpression(array $rows): string
    {
        $terms = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach (self::DIMS as $dim) {
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
     * Expression from DNF conjunctions (supports duplicate dims via meta in display).
     *
     * @param list<list<array<string, mixed>>> $conjunctions
     */
    public static function dnfToExpression(array $conjunctions): string
    {
        $terms = [];
        foreach ($conjunctions as $conj) {
            $parts = [];
            foreach ($conj as $term) {
                $parts[] = ($term['dim'] ?? '') . ':' . ($term['value'] ?? '');
            }
            if ($parts === []) {
                continue;
            }
            $terms[] = count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
        }

        return implode(' OR ', $terms);
    }

    /** Default empty builder tree (root OR group). */
    public static function emptyTree(): array
    {
        return [
            'type'  => 'group',
            'op'    => 'OR',
            'children' => [
                ['type' => 'group', 'op' => 'AND', 'children' => []],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Parser (recursive descent; AND binds tighter than OR)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function parseOrExpr(string $s, int &$pos): array
    {
        $parts = [self::parseAndExpr($s, $pos)];
        self::skipWs($s, $pos);
        while (self::matchWord($s, $pos, 'OR')) {
            $parts[] = self::parseAndExpr($s, $pos);
            self::skipWs($s, $pos);
        }

        return count($parts) === 1
            ? $parts[0]
            : ['type' => 'group', 'op' => 'OR', 'children' => $parts];
    }

    /** @return array<string, mixed> */
    private static function parseAndExpr(string $s, int &$pos): array
    {
        $parts = [self::parsePrimary($s, $pos)];
        self::skipWs($s, $pos);
        while (self::matchWord($s, $pos, 'AND')) {
            $parts[] = self::parsePrimary($s, $pos);
            self::skipWs($s, $pos);
        }

        return count($parts) === 1
            ? $parts[0]
            : ['type' => 'group', 'op' => 'AND', 'children' => $parts];
    }

    /** @return array<string, mixed> */
    private static function parsePrimary(string $s, int &$pos): array
    {
        self::skipWs($s, $pos);
        if ($pos < strlen($s) && $s[$pos] === '(') {
            $pos++;
            $node = self::parseOrExpr($s, $pos);
            self::skipWs($s, $pos);
            if ($pos >= strlen($s) || $s[$pos] !== ')') {
                throw new \InvalidArgumentException('Missing closing parenthesis');
            }
            $pos++;

            return $node;
        }

        return self::parseTerm($s, $pos);
    }

    /** @return array<string, mixed> */
    private static function parseTerm(string $s, int &$pos): array
    {
        self::skipWs($s, $pos);
        $start = $pos;
        $len   = strlen($s);

        while ($pos < $len && !ctype_space($s[$pos]) && $s[$pos] !== ')' && !self::wordAt($s, $pos, 'AND') && !self::wordAt($s, $pos, 'OR')) {
            $pos++;
        }

        $token = trim(substr($s, $start, $pos - $start));
        if ($token === '' || !preg_match('/^(org|node|tag|group|user):(.+)$/i', $token, $m)) {
            throw new \InvalidArgumentException('Invalid or missing target term near: ' . substr($s, $start, 30));
        }

        $dim   = strtolower($m[1]);
        $value = trim($m[2]);
        if ($value === '') {
            throw new \InvalidArgumentException("Missing value for {$dim}:");
        }

        if (str_contains($value, ',') && $dim !== 'group') {
            $values = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            if (count($values) > 1) {
                $children = array_map(fn (string $v) => self::termNode($dim, $v), $values);

                return ['type' => 'group', 'op' => 'OR', 'children' => $children];
            }
            $value = $values[0] ?? $value;
        }

        return self::termNode($dim, $value);
    }

    // ------------------------------------------------------------------
    // Tree ↔ AST
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $node */
    private static function treeNodeToAst(array $node): array
    {
        $type = (string) ($node['type'] ?? '');
        if ($type === 'term') {
            $dim = strtolower((string) ($node['dim'] ?? ''));
            if (!in_array($dim, self::DIMS, true)) {
                throw new \InvalidArgumentException("Invalid dimension: {$dim}");
            }
            $value = trim((string) ($node['value'] ?? ''));
            if ($value === '') {
                throw new \InvalidArgumentException("Empty value for {$dim}");
            }

            $term = self::termNode($dim, $value);
            if (!empty($node['label'])) {
                $term['label'] = (string) $node['label'];
            }

            return $term;
        }

        if ($type === 'group') {
            $op = strtoupper((string) ($node['op'] ?? 'AND'));
            if (!in_array($op, ['AND', 'OR'], true)) {
                throw new \InvalidArgumentException("Invalid group operator: {$op}");
            }
            $children = [];
            foreach ($node['children'] ?? [] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $children[] = self::treeNodeToAst($child);
            }

            return ['type' => 'group', 'op' => $op, 'children' => $children];
        }

        throw new \InvalidArgumentException('Tree node must be type "term" or "group"');
    }

    /** @return array<string, mixed> */
    private static function termNode(string $dim, string $value): array
    {
        return [
            'type'  => 'term',
            'dim'   => $dim,
            'value' => $dim === 'user' ? strtolower($value) : $value,
        ];
    }

    /** @param array<string, mixed> $node */
    private static function normalizeAst(array $node): array
    {
        if (($node['type'] ?? '') === 'term') {
            return $node;
        }

        $op       = strtoupper((string) ($node['op'] ?? 'AND'));
        $children = [];
        foreach ($node['children'] ?? [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $normalized = self::normalizeAst($child);
            if (($normalized['type'] ?? '') === 'group' && ($normalized['op'] ?? '') === $op) {
                foreach ($normalized['children'] ?? [] as $grand) {
                    $children[] = $grand;
                }
            } else {
                $children[] = $normalized;
            }
        }

        if ($children === []) {
            return ['type' => 'group', 'op' => $op, 'children' => []];
        }

        if (count($children) === 1) {
            return $children[0];
        }

        return ['type' => 'group', 'op' => $op, 'children' => $children];
    }

    // ------------------------------------------------------------------
    // DNF
    // ------------------------------------------------------------------

    /**
     * @return list<list<array<string, mixed>>>
     */
    private static function dnfFromNode(array $node): array
    {
        if (($node['type'] ?? '') === 'term') {
            return [[$node]];
        }

        $op       = strtoupper((string) ($node['op'] ?? 'AND'));
        $childDnfs = array_map(fn (array $c) => self::dnfFromNode($c), $node['children'] ?? []);

        if ($childDnfs === []) {
            return [];
        }

        if ($op === 'OR') {
            $out = [];
            foreach ($childDnfs as $dnf) {
                foreach ($dnf as $conj) {
                    $out[] = $conj;
                }
            }

            return $out;
        }

        $result = [[]];
        foreach ($childDnfs as $dnf) {
            $next = [];
            foreach ($result as $left) {
                foreach ($dnf as $right) {
                    $next[] = array_merge($left, $right);
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $conj
     * @return array<string, mixed>
     */
    private static function conjunctionToResolveRow(array $conj): array
    {
        $byDim = [];
        foreach ($conj as $term) {
            $dim = (string) ($term['dim'] ?? '');
            if (!in_array($dim, self::DIMS, true)) {
                continue;
            }
            $byDim[$dim][] = [
                'dim'   => $dim,
                'value' => (string) ($term['value'] ?? ''),
                'label' => $term['label'] ?? null,
            ];
        }

        $row = [
            'target_org_id'   => null,
            'target_node_id'  => null,
            'target_tag_id'   => null,
            'target_group_id' => null,
            'target_user_id'  => null,
            'target_label'    => '',
            'conj_terms'      => null,
        ];

        $labels     = [];
        $needsCompound = false;
        foreach (self::DIMS as $dim) {
            if (empty($byDim[$dim])) {
                continue;
            }
            if (count($byDim[$dim]) > 1) {
                $needsCompound = true;
            }
            $labels[] = $dim . ':' . $byDim[$dim][0]['value'];
        }

        if ($needsCompound) {
            $flat = [];
            foreach ($conj as $term) {
                $flat[] = [
                    'dim'   => $term['dim'],
                    'value' => $term['value'],
                ];
            }
            $row['conj_terms']   = $flat;
            $row['target_label'] = implode(' + ', array_map(
                fn (array $t) => ($t['dim'] ?? '') . ':' . ($t['value'] ?? ''),
                $flat
            ));

            return $row;
        }

        $legacy = [];
        foreach (self::DIMS as $dim) {
            if (!empty($byDim[$dim][0]['value'])) {
                $legacy[$dim] = $byDim[$dim][0]['value'];
            }
        }
        $row['_legacy'] = $legacy;

        return $row;
    }

    // ------------------------------------------------------------------
    // Stringify
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $node */
    private static function stringifyNode(array $node, bool $parentIsOr): string
    {
        if (($node['type'] ?? '') === 'term') {
            return ($node['dim'] ?? '') . ':' . ($node['value'] ?? '');
        }

        $op       = strtoupper((string) ($node['op'] ?? 'AND'));
        $children = $node['children'] ?? [];
        if ($children === []) {
            return '';
        }

        $parts = array_map(function (array $child) use ($op): string {
            $s = self::stringifyNode($child, $op === 'OR');
            if (($child['type'] ?? '') === 'group' && ($child['op'] ?? '') === 'OR' && $op === 'AND') {
                return '(' . $s . ')';
            }

            return $s;
        }, $children);

        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        if ($parts === []) {
            return '';
        }

        $join = ' ' . $op . ' ';
        $inner = implode($join, $parts);

        if ($parentIsOr && $op === 'AND' && count($parts) > 1) {
            return '(' . $inner . ')';
        }

        return $inner;
    }

    // ------------------------------------------------------------------
    // Lexer helpers
    // ------------------------------------------------------------------

    private static function skipWs(string $s, int &$pos): void
    {
        while ($pos < strlen($s) && ctype_space($s[$pos])) {
            $pos++;
        }
    }

    private static function matchWord(string $s, int &$pos, string $word): bool
    {
        if (!self::wordAt($s, $pos, $word)) {
            return false;
        }
        $pos += strlen($word);

        return true;
    }

    private static function wordAt(string $s, int $pos, string $word): bool
    {
        $len = strlen($word);
        if (strncasecmp(substr($s, $pos), $word, $len) !== 0) {
            return false;
        }
        $before = $pos === 0 ? ' ' : $s[$pos - 1];
        $after  = ($pos + $len >= strlen($s)) ? ' ' : $s[$pos + $len];

        return (ctype_space($before) || $before === '(') && (ctype_space($after) || $after === ')');
    }
}
