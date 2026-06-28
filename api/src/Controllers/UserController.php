<?php
/**
 * NexAlert - User Controller
 *
 * GET    /api/v1/users                        → list/search users
 * POST   /api/v1/users                        → create single user
 * GET    /api/v1/users/{id}                   → get user detail
 * PUT    /api/v1/users/{id}                   → update user
 * DELETE /api/v1/users/{id}                   → deactivate user
 * POST   /api/v1/users/import                 → CSV bulk import
 * GET    /api/v1/users/{id}/memberships       → list org memberships
 * POST   /api/v1/users/{id}/memberships       → add org node membership
 * DELETE /api/v1/users/{id}/memberships/{mid} → remove membership
 * GET    /api/v1/users/{id}/tags              → list user tags
 * POST   /api/v1/users/{id}/tags              → manually assign tag
 * DELETE /api/v1/users/{id}/tags/{tag_id}     → remove manual tag
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Env;
use NexAlert\Services\AuditService;
use NexAlert\Services\TagService;

class UserController
{
    /**
     * GET /api/v1/users
     */
    public static function list(Request $request): never
    {
        $db           = Database::getInstance();
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        $search  = trim((string) $request->query('search', ''));
        $orgId   = $request->query('org_id') ? (int) $request->query('org_id') : null;
        $nodeId  = $request->query('node_id') ? (int) $request->query('node_id') : null;
        $tagId   = $request->query('tag_id') ? (int) $request->query('tag_id') : null;
        $active  = $request->query('active', '1');
        $limit   = min((int) $request->query('limit', 50), 200);
        $offset  = (int) $request->query('offset', 0);

        $where  = ['1=1'];
        $params = [];

        // Non-super-admins can only see users in their own org
        if (!$isSuperAdmin) {
            $where[]  = 'u.home_org_id = ?';
            $params[] = $request->user['org'];
        } elseif ($orgId) {
            $where[]  = 'u.home_org_id = ?';
            $params[] = $orgId;
        }

        if ($active !== 'all') {
            $where[]  = 'u.is_active = ?';
            $params[] = $active === '0' ? 0 : 1;
        }

        if ($search !== '') {
            $where[]  = '(u.username LIKE ? OR u.display_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $like     = "%{$search}%";
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }

        if ($nodeId) {
            $node = $db->fetchOne('SELECT path FROM org_nodes WHERE id = ?', [$nodeId]);
            if ($node) {
                $where[]  = 'EXISTS (
                    SELECT 1 FROM user_org_memberships m
                    JOIN org_nodes n ON n.id = m.org_node_id
                    WHERE m.user_id = u.id AND m.is_active = 1 AND n.path LIKE ?
                )';
                $params[] = $node['path'] . '%';
            }
        }

        if ($tagId) {
            $where[]  = 'EXISTS (
                SELECT 1 FROM tag_assignments ta
                WHERE ta.user_id = u.id AND ta.tag_id = ? AND ta.is_active = 1
            )';
            $params[] = $tagId;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) $db->fetchValue("SELECT COUNT(*) FROM users u WHERE {$whereStr}", $params);

        $rows = $db->fetchAll(
            "SELECT u.id, u.username, u.display_name, u.first_name, u.last_name,
                    u.home_org_id, u.home_node_id, u.is_active, u.is_locked,
                    u.last_login_at, u.timezone, u.created_at,
                    o.name AS home_org_name,
                    n.name AS home_node_name
             FROM users u
             LEFT JOIN organizations o ON o.id = u.home_org_id
             LEFT JOIN org_nodes n ON n.id = u.home_node_id
             WHERE {$whereStr}
             ORDER BY u.last_name ASC, u.first_name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        Response::success([
            'users'  => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * POST /api/v1/users
     */
    public static function create(Request $request): never
    {
        $missing = $request->validate(['username', 'first_name', 'last_name', 'home_org_id']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $data   = self::extractUserFields($request);
        $errors = self::validateUserFields($data);
        if ($errors) {
            Response::validationError($errors);
        }

        $db = Database::getInstance();
        self::assertOrgAccess($request, (int) $data['home_org_id']);

        // Check username uniqueness
        if ($db->fetchValue('SELECT id FROM users WHERE username = ?', [$data['username']])) {
            Response::validationError(['username' => 'Username already in use']);
        }

        $passwordHash = null;
        $password     = trim((string) $request->input('password', ''));
        if ($password !== '') {
            if (strlen($password) < 12) {
                Response::validationError(['password' => 'Password must be at least 12 characters']);
            }
            $cost         = Env::int('BCRYPT_COST', 12);
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
        }

        $userId = $db->transaction(function (Database $db) use ($data, $passwordHash, $request): int {
            $db->execute(
                'INSERT INTO users
                    (username, display_name, first_name, last_name, home_org_id, home_node_id,
                     local_password_hash, timezone, preferred_language, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    $data['username'],
                    $data['display_name'],
                    $data['first_name'],
                    $data['last_name'],
                    $data['home_org_id'],
                    $data['home_node_id'],
                    $passwordHash,
                    $data['timezone'],
                    $data['preferred_language'],
                ]
            );
            $userId = $db->lastInsertId();

            // Add home node membership if specified
            if ($data['home_node_id']) {
                self::addMembership($db, $userId, (int) $data['home_org_id'], (int) $data['home_node_id'], null, $request->user['uid']);
            }

            // Add primary email contact if provided
            $email = trim((string) $request->input('email', ''));
            if ($email !== '') {
                self::addContact($db, $userId, 'email', $email, 'Work', true);
            }

            // Add primary phone if provided
            $phone = trim((string) $request->input('phone', ''));
            if ($phone !== '') {
                self::addContact($db, $userId, 'sms', self::normalizePhone($phone), 'Mobile', true);
            }

            // Assign recipient role by default
            $recipientRole = $db->fetchValue("SELECT id FROM roles WHERE name = 'recipient'");
            if ($recipientRole) {
                $db->execute(
                    'INSERT IGNORE INTO user_roles (user_id, role_id, org_id) VALUES (?, ?, ?)',
                    [$userId, $recipientRole, $data['home_org_id']]
                );
            }

            AuditService::log('user.created', 'user', (string) $userId, [
                'username'    => $data['username'],
                'home_org_id' => $data['home_org_id'],
            ], $request->user['uid']);

            return $userId;
        });

        $user = self::fetchUserDetail($db, $userId);
        Response::success($user, 'User created', 201);
    }

    /**
     * GET /api/v1/users/{id}
     */
    public static function get(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertUserAccess($request, $id, $db);

        $user = self::fetchUserDetail($db, $id);
        if (!$user) {
            Response::notFound('User not found');
        }

        Response::success($user);
    }

    /**
     * PUT /api/v1/users/{id}
     */
    public static function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertUserAccess($request, $id, $db);

        $existing = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$existing) {
            Response::notFound('User not found');
        }

        $data = [
            'first_name'         => trim((string) $request->input('first_name', $existing['first_name'])),
            'last_name'          => trim((string) $request->input('last_name', $existing['last_name'])),
            'display_name'       => trim((string) $request->input('display_name', $existing['display_name'])),
            'timezone'           => trim((string) $request->input('timezone', $existing['timezone'])),
            'preferred_language' => trim((string) $request->input('preferred_language', $existing['preferred_language'])),
        ];

        // Only super_admin or org_admin can change home_org / lock status
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        $isOrgAdmin   = in_array('org_admin',   $request->user['roles'] ?? [], true);

        if ($isSuperAdmin || $isOrgAdmin) {
            if ($request->input('is_locked') !== null) {
                $data['is_locked'] = $request->input('is_locked') ? 1 : 0;
            }
            if ($request->input('home_node_id') !== null) {
                $data['home_node_id']  = (int) $request->input('home_node_id') ?: null;
                $data['home_override'] = 1;
            }
        }

        // Auto-update display_name if not explicitly provided
        if ($request->input('display_name') === null) {
            $data['display_name'] = $data['first_name'] . ' ' . $data['last_name'];
        }

        $db->execute(
            'UPDATE users SET first_name = ?, last_name = ?, display_name = ?,
                              timezone = ?, preferred_language = ?
             WHERE id = ?',
            [
                $data['first_name'], $data['last_name'], $data['display_name'],
                $data['timezone'], $data['preferred_language'], $id,
            ]
        );

        if (isset($data['is_locked'])) {
            $db->execute('UPDATE users SET is_locked = ? WHERE id = ?', [$data['is_locked'], $id]);
        }
        if (array_key_exists('home_node_id', $data)) {
            $db->execute(
                'UPDATE users SET home_node_id = ?, home_override = 1 WHERE id = ?',
                [$data['home_node_id'], $id]
            );
        }

        AuditService::log('user.updated', 'user', (string) $id, [
            'before' => ['first_name' => $existing['first_name'], 'last_name' => $existing['last_name']],
            'after'  => ['first_name' => $data['first_name'],     'last_name' => $data['last_name']],
        ], $request->user['uid']);

        $user = self::fetchUserDetail($db, $id);
        Response::success($user, 'User updated');
    }

    /**
     * DELETE /api/v1/users/{id}
     * Soft deactivate. Cannot deactivate yourself.
     */
    public static function delete(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        if ($id === (int) $request->user['uid']) {
            Response::error('Cannot deactivate your own account', 409);
        }

        self::assertUserAccess($request, $id, $db);

        $user = $db->fetchOne('SELECT id, is_active FROM users WHERE id = ?', [$id]);
        if (!$user) {
            Response::notFound('User not found');
        }

        $db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);

        AuditService::log('user.deactivated', 'user', (string) $id, [], $request->user['uid']);

        Response::success(null, 'User deactivated');
    }

    /**
     * POST /api/v1/users/import
     * CSV bulk import. Expected columns:
     *   username, first_name, last_name, email, phone (opt), org_node_slug (opt), position_title (opt)
     */
    public static function import(Request $request): never
    {
        // Expect multipart/form-data with file field 'csv'
        if (empty($_FILES['csv'])) {
            Response::validationError(['csv' => 'CSV file required']);
        }

        $file = $_FILES['csv'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload error: ' . $file['error'], 400);
        }

        $orgId = (int) $request->input('org_id', $request->user['org']);
        self::assertOrgAccess($request, $orgId);

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            Response::error('Could not read uploaded file', 500);
        }

        // Read and validate header row
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            Response::error('CSV file is empty or unreadable', 400);
        }

        $header   = array_map('trim', array_map('strtolower', $header));
        $required = ['username', 'first_name', 'last_name', 'email'];
        $missing  = array_diff($required, $header);

        if ($missing) {
            fclose($handle);
            Response::validationError(['csv' => 'Missing required columns: ' . implode(', ', $missing)]);
        }

        $db          = Database::getInstance();
        $results     = ['created' => 0, 'skipped' => 0, 'errors' => []];
        $lineNum     = 1;
        $cost        = Env::int('BCRYPT_COST', 12);

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;

            if (count($row) < count($required)) {
                $results['errors'][] = "Line {$lineNum}: insufficient columns";
                $results['skipped']++;
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));

            if (empty($data['username']) || empty($data['email'])) {
                $results['errors'][] = "Line {$lineNum}: username and email are required";
                $results['skipped']++;
                continue;
            }

            // Skip if username already exists
            if ($db->fetchValue('SELECT id FROM users WHERE username = ?', [$data['username']])) {
                $results['errors'][] = "Line {$lineNum}: username '{$data['username']}' already exists";
                $results['skipped']++;
                continue;
            }

            // Resolve org node by slug if provided
            $nodeId = null;
            if (!empty($data['org_node_slug'] ?? '')) {
                $node = $db->fetchOne(
                    'SELECT id FROM org_nodes WHERE org_id = ? AND slug = ? AND is_active = 1',
                    [$orgId, $data['org_node_slug']]
                );
                $nodeId = $node ? (int) $node['id'] : null;
                if (!$nodeId) {
                    $results['errors'][] = "Line {$lineNum}: org_node_slug '{$data['org_node_slug']}' not found (user created without node)";
                }
            }

            try {
                // Generate temporary password — user will reset via email
                $tempPassword = bin2hex(random_bytes(8));
                $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => $cost]);
                $displayName  = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

                $userId = $db->transaction(function (Database $db) use (
                    $data, $displayName, $orgId, $nodeId, $passwordHash, $request
                ): int {
                    $db->execute(
                        'INSERT INTO users
                            (username, display_name, first_name, last_name, home_org_id,
                             home_node_id, local_password_hash, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                        [
                            $data['username'], $displayName,
                            $data['first_name'], $data['last_name'],
                            $orgId, $nodeId, $passwordHash,
                        ]
                    );
                    $userId = $db->lastInsertId();

                    self::addContact($db, $userId, 'email', $data['email'], 'Work', true);

                    if (!empty($data['phone'])) {
                        self::addContact($db, $userId, 'sms', self::normalizePhone($data['phone']), 'Mobile', true);
                    }

                    if ($nodeId) {
                        self::addMembership(
                            $db, $userId, $orgId, $nodeId,
                            $data['position_title'] ?? null,
                            $request->user['uid']
                        );
                    }

                    // Assign recipient role
                    $recipientRole = $db->fetchValue("SELECT id FROM roles WHERE name = 'recipient'");
                    if ($recipientRole) {
                        $db->execute(
                            'INSERT IGNORE INTO user_roles (user_id, role_id, org_id) VALUES (?, ?, ?)',
                            [$userId, $recipientRole, $orgId]
                        );
                    }

                    AuditService::log('user.imported', 'user', (string) $userId, [
                        'username' => $data['username'],
                        'source'   => 'csv_import',
                    ], $request->user['uid']);

                    return $userId;
                });

                $results['created']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Line {$lineNum}: " . $e->getMessage();
                $results['skipped']++;
            }
        }

        fclose($handle);

        AuditService::log('user.bulk_import', null, null, [
            'org_id'  => $orgId,
            'created' => $results['created'],
            'skipped' => $results['skipped'],
        ], $request->user['uid']);

        Response::success($results, "Import complete: {$results['created']} created, {$results['skipped']} skipped");
    }

    /**
     * GET /api/v1/users/{id}/memberships
     */
    public static function listMemberships(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertUserAccess($request, $id, $db);

        $memberships = $db->fetchAll(
            'SELECT m.id, m.org_id, m.org_node_id, m.position_title, m.is_active, m.joined_at,
                    o.name AS org_name, o.slug AS org_slug,
                    n.name AS node_name, n.node_type, n.path
             FROM user_org_memberships m
             JOIN organizations o ON o.id = m.org_id
             JOIN org_nodes n ON n.id = m.org_node_id
             WHERE m.user_id = ?
             ORDER BY o.name, n.path',
            [$id]
        );

        Response::success(['memberships' => $memberships]);
    }

    /**
     * POST /api/v1/users/{id}/memberships
     * Body: { "org_id": 1, "org_node_id": 5, "position_title": "Chief Engineer" }
     */
    public static function addMembershipRoute(Request $request): never
    {
        $id    = (int) $request->param('id');
        $db    = Database::getInstance();

        $missing = $request->validate(['org_id', 'org_node_id']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $orgId    = (int) $request->input('org_id');
        $nodeId   = (int) $request->input('org_node_id');
        $position = trim((string) $request->input('position_title', '')) ?: null;

        self::assertOrgAccess($request, $orgId);
        self::assertUserAccess($request, $id, $db);

        $user = $db->fetchOne('SELECT id FROM users WHERE id = ? AND is_active = 1', [$id]);
        if (!$user) {
            Response::notFound('User not found');
        }

        $node = $db->fetchOne('SELECT id, org_id FROM org_nodes WHERE id = ? AND is_active = 1', [$nodeId]);
        if (!$node || (int) $node['org_id'] !== $orgId) {
            Response::validationError(['org_node_id' => 'Node not found in this organization']);
        }

        // Check for existing active membership
        if ($db->fetchValue(
            'SELECT id FROM user_org_memberships WHERE user_id = ? AND org_node_id = ? AND is_active = 1',
            [$id, $nodeId]
        )) {
            Response::error('User already has an active membership in this node', 409);
        }

        $db->transaction(function (Database $db) use ($id, $orgId, $nodeId, $position, $request): void {
            self::addMembership($db, $id, $orgId, $nodeId, $position, $request->user['uid']);
        });

        Response::success(null, 'Membership added', 201);
    }

    /**
     * DELETE /api/v1/users/{id}/memberships/{mid}
     */
    public static function removeMembership(Request $request): never
    {
        $id  = (int) $request->param('id');
        $mid = (int) $request->param('mid');
        $db  = Database::getInstance();

        self::assertUserAccess($request, $id, $db);

        $membership = $db->fetchOne(
            'SELECT * FROM user_org_memberships WHERE id = ? AND user_id = ?',
            [$mid, $id]
        );
        if (!$membership) {
            Response::notFound('Membership not found');
        }

        $db->transaction(function (Database $db) use ($membership, $id, $mid, $request): void {
            $db->execute('UPDATE user_org_memberships SET is_active = 0, left_at = NOW() WHERE id = ?', [$mid]);

            TagService::revokeNodeTagsFromUser($db, $id, (int) $membership['org_node_id']);

            AuditService::log('user.membership_removed', 'user', (string) $id, [
                'membership_id' => $mid,
                'org_node_id'   => $membership['org_node_id'],
            ], $request->user['uid']);
        });

        Response::success(null, 'Membership removed');
    }

    /**
     * GET /api/v1/users/{id}/tags
     */
    public static function listTags(Request $request): never
    {
        $id = (int) $request->param('id');
        $db = Database::getInstance();

        self::assertUserAccess($request, $id, $db);

        $tags = $db->fetchAll(
            'SELECT ta.id, ta.tag_id, ta.assignment_type, ta.assigned_at, ta.is_active,
                    t.name, t.slug, t.is_system, t.is_exclusive,
                    n.name AS source_node_name
             FROM tag_assignments ta
             JOIN tags t ON t.id = ta.tag_id
             LEFT JOIN org_nodes n ON n.id = ta.source_node_id
             WHERE ta.user_id = ? AND ta.is_active = 1
             ORDER BY t.name ASC',
            [$id]
        );

        Response::success(['tags' => $tags]);
    }

    /**
     * POST /api/v1/users/{id}/tags
     * Body: { "tag_id": 5 }
     * Manual assignment — subject to tag approval rules.
     */
    public static function assignTag(Request $request): never
    {
        $id    = (int) $request->param('id');
        $tagId = (int) $request->input('tag_id', 0);
        $db    = Database::getInstance();

        if (!$tagId) {
            Response::validationError(['tag_id' => 'Required']);
        }

        self::assertUserAccess($request, $id, $db);

        $tag = $db->fetchOne('SELECT * FROM tags WHERE id = ? AND is_active = 1', [$tagId]);
        if (!$tag) {
            Response::notFound('Tag not found');
        }

        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);

        // Exclusive tags: only super_admin or tag admin can assign
        if ($tag['is_exclusive'] && !$isSuperAdmin) {
            if ((int) ($tag['tag_admin_id'] ?? 0) !== $request->user['uid']) {
                Response::forbidden('This tag requires exclusive admin approval to assign');
            }
        }

        // Check already assigned
        if ($db->fetchValue(
            'SELECT id FROM tag_assignments WHERE tag_id = ? AND user_id = ? AND is_active = 1',
            [$tagId, $id]
        )) {
            Response::error('Tag already assigned to this user', 409);
        }

        $db->execute(
            "INSERT INTO tag_assignments (tag_id, user_id, assignment_type, assigned_by, assigned_at)
             VALUES (?, ?, 'manual', ?, NOW())",
            [$tagId, $id, $request->user['uid']]
        );

        AuditService::log('tag.assigned', 'user', (string) $id, [
            'tag_id'   => $tagId,
            'tag_name' => $tag['name'],
        ], $request->user['uid']);

        Response::success(null, 'Tag assigned', 201);
    }

    /**
     * DELETE /api/v1/users/{id}/tags/{tag_id}
     */
    public static function removeTag(Request $request): never
    {
        $id    = (int) $request->param('id');
        $tagId = (int) $request->param('tag_id');
        $db    = Database::getInstance();

        self::assertUserAccess($request, $id, $db);

        $assignment = $db->fetchOne(
            "SELECT * FROM tag_assignments WHERE tag_id = ? AND user_id = ? AND is_active = 1 AND assignment_type = 'manual'",
            [$tagId, $id]
        );
        if (!$assignment) {
            Response::notFound('Manual tag assignment not found');
        }

        $db->execute(
            'UPDATE tag_assignments SET is_active = 0 WHERE tag_id = ? AND user_id = ?',
            [$tagId, $id]
        );

        AuditService::log('tag.removed', 'user', (string) $id, ['tag_id' => $tagId], $request->user['uid']);

        Response::success(null, 'Tag removed');
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private static function fetchUserDetail(Database $db, int $id): ?array
    {
        $user = $db->fetchOne(
            'SELECT u.id, u.username, u.display_name, u.first_name, u.last_name,
                    u.home_org_id, u.home_node_id, u.home_override, u.auth_provider_id,
                    u.external_id, u.is_active, u.is_locked, u.last_login_at, u.last_login_ip,
                    u.timezone, u.preferred_language, u.created_at, u.updated_at,
                    o.name AS home_org_name,
                    n.name AS home_node_name, n.node_type AS home_node_type
             FROM users u
             LEFT JOIN organizations o ON o.id = u.home_org_id
             LEFT JOIN org_nodes n ON n.id = u.home_node_id
             WHERE u.id = ?',
            [$id]
        );

        if (!$user) {
            return null;
        }

        $user['contacts'] = $db->fetchAll(
            'SELECT id, channel, contact_value, label, is_primary, is_verified, verified_at, is_active
             FROM user_contacts WHERE user_id = ? ORDER BY channel, is_primary DESC',
            [$id]
        );

        $user['roles'] = $db->fetchAll(
            'SELECT r.id, r.name, r.display_name, ur.org_id, ur.org_node_id, ur.expires_at
             FROM roles r JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [$id]
        );

        return $user;
    }

    private static function addMembership(
        Database $db,
        int $userId,
        int $orgId,
        int $nodeId,
        ?string $positionTitle,
        ?int $addedBy
    ): void {
        $db->execute(
            'INSERT INTO user_org_memberships (user_id, org_id, org_node_id, position_title, added_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_active = 1, position_title = VALUES(position_title)',
            [$userId, $orgId, $nodeId, $positionTitle, $addedBy]
        );

        TagService::assignNodeTagsToUser($db, $userId, $nodeId);
    }

    private static function addContact(
        Database $db,
        int $userId,
        string $channel,
        string $value,
        string $label,
        bool $isPrimary
    ): void {
        $db->execute(
            'INSERT INTO user_contacts (user_id, channel, contact_value, label, is_primary)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $channel, $value, $label, $isPrimary ? 1 : 0]
        );
    }

    private static function extractUserFields(Request $request): array
    {
        $firstName   = trim((string) $request->input('first_name', ''));
        $lastName    = trim((string) $request->input('last_name', ''));
        $displayName = trim((string) $request->input('display_name', ''));

        if ($displayName === '') {
            $displayName = $firstName . ' ' . $lastName;
        }

        return [
            'username'           => strtolower(trim((string) $request->input('username', ''))),
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'display_name'       => $displayName,
            'home_org_id'        => (int) $request->input('home_org_id', 0),
            'home_node_id'       => $request->input('home_node_id') ? (int) $request->input('home_node_id') : null,
            'timezone'           => trim((string) $request->input('timezone', 'America/Chicago')),
            'preferred_language' => trim((string) $request->input('preferred_language', 'en')),
        ];
    }

    private static function validateUserFields(array $data): array
    {
        $errors = [];

        if (empty($data['username'])) {
            $errors['username'] = 'Required';
        } elseif (!preg_match('/^[a-z0-9_.-]+$/', $data['username'])) {
            $errors['username'] = 'Only lowercase letters, numbers, dots, hyphens, underscores';
        }

        if (empty($data['first_name'])) {
            $errors['first_name'] = 'Required';
        }

        if (empty($data['last_name'])) {
            $errors['last_name'] = 'Required';
        }

        if (empty($data['home_org_id'])) {
            $errors['home_org_id'] = 'Required';
        }

        return $errors;
    }

    /**
     * Normalize a phone number to E.164 format (basic US normalization).
     * Full international normalization should use a library in production.
     */
    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        // Return as-is with + prefix if it already looks international
        return str_starts_with($phone, '+') ? $phone : '+' . $digits;
    }

    private static function assertOrgAccess(Request $request, int $orgId): void
    {
        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if (!$isSuperAdmin && (int) $request->user['org'] !== $orgId) {
            Response::forbidden('Access denied to this organization');
        }
    }

    private static function assertUserAccess(Request $request, int $userId, Database $db): void
    {
        // Users can always access their own record
        if ((int) $request->user['uid'] === $userId) {
            return;
        }

        $isSuperAdmin = in_array('super_admin', $request->user['roles'] ?? [], true);
        if ($isSuperAdmin) {
            return;
        }

        // org_admin and group_admin can access users in their org
        $targetUser = $db->fetchOne('SELECT home_org_id FROM users WHERE id = ?', [$userId]);
        if ($targetUser && (int) $targetUser['home_org_id'] === (int) $request->user['org']) {
            return;
        }

        Response::forbidden('Access denied to this user');
    }
}
