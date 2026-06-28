<?php
/**
 * NexAlert - Target AST Service
 * Parses nested AND/OR targeting expressions, optional EXCEPT/NOT exclusions,
 * normalizes include to DNF, and converts builder trees for alert_targets resolution.
 *
 * Exclusion syntax (applied to the full include set):
 *   (tag:eng AND org:nexstar) EXCEPT user:david
 *   tag:eng AND org:nexstar AND NOT user:david
 *   tag:eng EXCEPT (user:a OR user:b OR group:exec@nexstar)
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
            $expression = self::normalizeAndNotToExcept($expression);
            [$includeStr, $exceptStr] = self::splitTopLevelKeyword($expression, 'EXCEPT');

            $pos = 0;
            $includeAst = self::parseOrExpr($includeStr, $pos);
            self::skipWs($includeStr, $pos);
            if ($pos < strlen($includeStr)) {
                return [
                    'ast'    => null,
                    'errors' => ['Unexpected text in include expression: ' . substr($includeStr, $pos, 40)],
                ];
            }

            $exceptTerms = [];
            if ($exceptStr !== null && trim($exceptStr) !== '') {
                $exceptParsed = self::parseExceptClause(trim($exceptStr));
                if ($exceptParsed['errors'] !== []) {
                    return ['ast' => null, 'errors' => $exceptParsed['errors']];
                }
                $exceptTerms = $exceptParsed['terms'];
            }

            return ['ast' => self::wrapTargetAst(self::normalizeAst($includeAst), $exceptTerms), 'errors' => []];
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
            if (($tree['type'] ?? '') === 'target') {
                $includeNode = $tree['include'] ?? null;
                if (!is_array($includeNode)) {
                    throw new \InvalidArgumentException('Target tree missing include group');
                }

                $includeAst = self::normalizeAst(self::treeNodeToAst($includeNode));
                $exceptTerms = [];
                $exceptNode  = $tree['except'] ?? null;
                if (is_array($exceptNode) && ($exceptNode['type'] ?? '') === 'group') {
                    foreach ($exceptNode['children'] ?? [] as $child) {
                        if (!is_array($child)) {
                            continue;
                        }
                        $term = self::treeNodeToAst($child);
                        if (($term['type'] ?? '') !== 'term') {
                            throw new \InvalidArgumentException('EXCEPT terms must be simple dimension terms');
                        }
                        $exceptTerms[] = $term;
                    }
                }

                return ['ast' => self::wrapTargetAst($includeAst, $exceptTerms), 'errors' => []];
            }

            return ['ast' => self::wrapTargetAst(self::normalizeAst(self::treeNodeToAst($tree)), []), 'errors' => []];
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
        $includeAst = self::unwrapInclude($ast);

        if (($includeAst['type'] ?? '') === 'group' && ($includeAst['children'] ?? []) === []) {
            return ['conjunctions' => [], 'errors' => ['Target tree is empty']];
        }

        try {
            $conjunctions = self::dnfFromNode($includeAst);

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
        if (($ast['type'] ?? '') === 'target') {
            $include = self::stringifyNode($ast['include'] ?? [], false);
            $except  = $ast['except'] ?? [];
            if ($except === []) {
                return $include;
            }

            return $include . ' EXCEPT ' . self::exceptTermsToExpression($except);
        }

        return self::stringifyNode($ast, false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function unwrapInclude(array $ast): array
    {
        if (($ast['type'] ?? '') === 'target') {
            return $ast['include'] ?? ['type' => 'group', 'op' => 'OR', 'children' => []];
        }

        return $ast;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getExceptTerms(array $ast): array
    {
        if (($ast['type'] ?? '') !== 'target') {
            return [];
        }

        $terms = $ast['except'] ?? [];

        return is_array($terms) ? $terms : [];
    }

    /**
     * @param list<array<string, mixed>> $exceptTerms
     * @return array<string, mixed>
     */
    public static function wrapTargetAst(array $includeAst, array $exceptTerms): array
    {
        if ($exceptTerms === []) {
            return $includeAst;
        }

        return [
            'type'    => 'target',
            'include' => $includeAst,
            'except'  => array_values($exceptTerms),
        ];
    }

    /** Default builder tree with optional EXCEPT section. */
    public static function emptyTargetTree(): array
    {
        return [
            'type'    => 'target',
            'include' => self::emptyTree(),
            'except'  => ['type' => 'group', 'op' => 'OR', 'children' => []],
        ];
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

        while (
            $pos < $len
            && !ctype_space($s[$pos])
            && $s[$pos] !== ')'
            && !self::wordAt($s, $pos, 'AND')
            && !self::wordAt($s, $pos, 'OR')
            && !self::wordAt($s, $pos, 'NOT')
            && !self::wordAt($s, $pos, 'EXCEPT')
        ) {
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
    // EXCEPT / NOT
    // ------------------------------------------------------------------

    /**
     * Convert trailing " AND NOT term" suffixes to " EXCEPT term".
     */
    private static function normalizeAndNotToExcept(string $expr): string
    {
        $exceptParts = [];

        while (true) {
            $split = self::splitTrailingAndNot($expr);
            if ($split === null) {
                break;
            }

            [$expr, $term] = $split;
            $exceptParts[] = $term;
        }

        if ($exceptParts === []) {
            return trim($expr);
        }

        $exceptClause = count($exceptParts) === 1
            ? $exceptParts[0]
            : '(' . implode(' OR ', $exceptParts) . ')';

        return trim($expr) . ' EXCEPT ' . $exceptClause;
    }

    /** @return array{0: string, 1: string}|null */
    private static function splitTrailingAndNot(string $expr): ?array
    {
        $needle = ' AND NOT ';
        $pos    = self::lastTopLevelOccurrence($expr, $needle);
        if ($pos === false) {
            return null;
        }

        $include = trim(substr($expr, 0, $pos));
        $term    = trim(substr($expr, $pos + strlen($needle)));
        if ($include === '' || $term === '' || !preg_match('/^(org|node|tag|group|user):/i', $term)) {
            throw new \InvalidArgumentException('Invalid AND NOT exclusion term: ' . $term);
        }

        return [$include, $term];
    }

    /**
     * @return array{terms: list<array<string, mixed>>, errors: string[]}
     */
    private static function parseExceptClause(string $s): array
    {
        try {
            $pos = 0;
            $ast = self::parseOrExpr($s, $pos);
            self::skipWs($s, $pos);
            if ($pos < strlen($s)) {
                return ['terms' => [], 'errors' => ['Unexpected text in EXCEPT clause: ' . substr($s, $pos, 40)]];
            }

            $terms = self::flattenExceptTerms($ast);
            if ($terms === []) {
                return ['terms' => [], 'errors' => ['EXCEPT clause must contain at least one term']];
            }

            return ['terms' => $terms, 'errors' => []];
        } catch (\InvalidArgumentException $e) {
            return ['terms' => [], 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function flattenExceptTerms(array $node): array
    {
        if (($node['type'] ?? '') === 'term') {
            return [$node];
        }

        if (($node['type'] ?? '') === 'group' && strtoupper((string) ($node['op'] ?? '')) === 'OR') {
            $terms = [];
            foreach ($node['children'] ?? [] as $child) {
                if (!is_array($child) || ($child['type'] ?? '') !== 'term') {
                    throw new \InvalidArgumentException('EXCEPT supports simple terms joined by OR');
                }
                $terms[] = $child;
            }

            return $terms;
        }

        throw new \InvalidArgumentException('EXCEPT clause must be a term or OR of terms');
    }

    /**
     * @param list<array<string, mixed>> $terms
     */
    private static function exceptTermsToExpression(array $terms): string
    {
        $parts = array_map(
            fn (array $term): string => ($term['dim'] ?? '') . ':' . ($term['value'] ?? ''),
            $terms
        );
        $parts = array_values(array_filter($parts, fn (string $p): bool => $p !== ':'));

        if ($parts === []) {
            return '';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function splitTopLevelKeyword(string $expr, string $keyword): array
    {
        $parts = self::splitTopLevel($expr, $keyword);
        if ($parts === []) {
            return [trim($expr), null];
        }

        $include = trim(array_shift($parts));
        if ($parts === []) {
            return [$include, null];
        }

        $exceptParts = array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
        if ($exceptParts === []) {
            return [$include, null];
        }

        $except = count($exceptParts) === 1
            ? $exceptParts[0]
            : '(' . implode(' OR ', $exceptParts) . ')';

        return [$include, $except];
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

    /** @return int|false */
    private static function lastTopLevelOccurrence(string $expr, string $needle): int|false
    {
        $len       = strlen($expr);
        $needleLen = strlen($needle);
        $last      = false;

        for ($i = 0; $i <= $len - $needleLen; $i++) {
            if (strncasecmp(substr($expr, $i), $needle, $needleLen) !== 0) {
                continue;
            }

            $before = $i === 0 ? ' ' : $expr[$i - 1];
            $after  = ($i + $needleLen >= $len) ? ' ' : $expr[$i + $needleLen];
            if (!(ctype_space($before) || $before === '(') || !(ctype_space($after) || $after === ')')) {
                continue;
            }

            if (self::depthAt($expr, $i) === 0) {
                $last = $i;
            }
        }

        return $last;
    }

    private static function depthAt(string $expr, int $pos): int
    {
        $depth = 0;
        for ($i = 0; $i < $pos; $i++) {
            if ($expr[$i] === '(') {
                $depth++;
            } elseif ($expr[$i] === ')') {
                $depth--;
            }
        }

        return $depth;
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
