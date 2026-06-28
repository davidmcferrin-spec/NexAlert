#!/usr/bin/env php
<?php
/**
 * NexAlert - Database Migration & Dev Seed Tool
 *
 * CLI only. Applies pending SQL migrations from db/NNN_*.sql and seeds
 * a development admin account.
 *
 * Usage:
 *   php migrate.php                 Run pending migrations + seed admin
 *   php migrate.php --status        Show applied vs pending migrations
 *   php migrate.php --migrate-only  Apply SQL migrations only
 *   php migrate.php --seed-only     Seed dev org + admin only
 *   php migrate.php --reset-admin   Re-seed or reset admin password
 *   php migrate.php --force         Allow running seed in production
 *
 * Default admin (development):
 *   username: admin
 *   password: YourStrongPassword  (override with DEV_ADMIN_PASSWORD in .env)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "migrate.php is CLI-only.\n";
    exit(1);
}

define('NEXALERT_ROOT', __DIR__);

require_once NEXALERT_ROOT . '/api/autoload.php';

use NexAlert\Config\Database;
use NexAlert\Config\Env;
use NexAlert\Config\Logger;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

Env::load(NEXALERT_ROOT);
Logger::init();

$opts = getopt('', ['status', 'migrate-only', 'seed-only', 'reset-admin', 'repair-admin-email', 'force', 'help']);

if (isset($opts['help'])) {
    printHelp();
    exit(0);
}

$isProduction = Env::get('APP_ENV') === 'production';
$force        = isset($opts['force']);

if ($isProduction && !isset($opts['status']) && !$force) {
    fwrite(STDERR, "ERROR: APP_ENV=production. Pass --force to run migrations/seed.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

try {
    $db = Database::getInstance();

    if (isset($opts['status'])) {
        showStatus($db);
        exit(0);
    }

    if (isset($opts['repair-admin-email'])) {
        upgradeAdminEmailContacts($db);
        out('Done.');
        exit(0);
    }

    if (!isset($opts['seed-only'])) {
        runMigrations($db);
    }

    if (!isset($opts['migrate-only'])) {
        seedDevAdmin($db, isset($opts['reset-admin']));
    }

    out('');
    out('Done.');
    if (!isset($opts['migrate-only'])) {
        $password = Env::get('DEV_ADMIN_PASSWORD', 'YourStrongPassword');
        out('Admin login: admin / ' . $password);
        out('Admin URL:   ' . rtrim(Env::get('APP_URL', 'http://localhost'), '/') . '/admin/login');
        if (!$isProduction) {
            out('');
            out('WARNING: Change the admin password before any shared or production use.');
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    if (Env::isDevelopment()) {
        fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    }
    exit(1);
}

// ---------------------------------------------------------------------------
// Migration runner
// ---------------------------------------------------------------------------

function runMigrations(Database $db): void
{
    $files   = discoverMigrationFiles();
    $applied = getAppliedVersionMap($db);

    if ($files === []) {
        out('No migration files found in db/.');
        return;
    }

    $pending = array_filter(
        $files,
        static function (array $m) use ($applied): bool {
            return !isset($applied[$m['version']]);
        }
    );

    if ($pending === []) {
        out('No pending migrations.');
        return;
    }

    out('Applying ' . count($pending) . ' migration(s)...');

    foreach ($pending as $migration) {
        out("  → {$migration['version']}_{$migration['name']}.sql");
        applyMigrationFile($migration['path']);
        recordMigration($db, $migration);
        out("    ✓ applied");
    }
}

/**
 * @return list<array{version: string, name: string, path: string, description: string}>
 */
function discoverMigrationFiles(): array
{
    $pattern = NEXALERT_ROOT . '/db/[0-9][0-9][0-9]_*.sql';
    $paths   = glob($pattern) ?: [];
    sort($paths, SORT_STRING);

    $files = [];
    foreach ($paths as $path) {
        $basename = basename($path);
        if (!preg_match('/^(\d{3})_(.+)\.sql$/', $basename, $m)) {
            continue;
        }
        $files[] = [
            'version'     => $m[1],
            'name'        => $m[2],
            'path'        => $path,
            'description' => humanizeMigrationName($m[2]),
        ];
    }

    return $files;
}

function humanizeMigrationName(string $name): string
{
    return ucfirst(str_replace('_', ' ', $name));
}

/**
 * @return array<string, true>  version => true
 */
function getAppliedVersionMap(Database $db): array
{
    if (!tableExists($db, 'schema_migrations')) {
        return [];
    }

    $rows = $db->fetchAll('SELECT version FROM schema_migrations');
    $map  = [];
    foreach ($rows as $row) {
        $map[$row['version']] = true;
    }

    return $map;
}

function tableExists(Database $db, string $table): bool
{
    try {
        return (bool) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
    } catch (\Throwable) {
        return false;
    }
}

function applyMigrationFile(string $path): void
{
    if (!is_readable($path)) {
        throw new \RuntimeException("Migration file not readable: {$path}");
    }

    if (applyViaMysqlCli($path)) {
        return;
    }

    applyViaPdo($path);
}

function applyViaMysqlCli(string $path): bool
{
    $mysql = findMysqlBinary();
    if ($mysql === null) {
        return false;
    }

    $host   = Env::require('DB_HOST');
    $port   = (string) Env::int('DB_PORT', 3306);
    $user   = Env::require('DB_USER');
    $pass   = Env::require('DB_PASS');
    $dbname = Env::require('DB_NAME');

    $cmd = [
        $mysql,
        '-h', $host,
        '-P', $port,
        '-u', $user,
        $dbname,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = $_ENV;
    putenv('MYSQL_PWD=' . $pass);

    $proc = proc_open($cmd, $descriptors, $pipes, NEXALERT_ROOT, null);

    if (!is_resource($proc)) {
        putenv('MYSQL_PWD');
        return false;
    }

    $sql = file_get_contents($path);
    fwrite($pipes[0], $sql !== false ? $sql : '');
    fclose($pipes[0]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    putenv('MYSQL_PWD');

    $exitCode = proc_close($proc);

    if ($exitCode !== 0) {
        throw new \RuntimeException(
            'mysql CLI failed (exit ' . $exitCode . '): ' . trim($stderr ?: 'unknown error')
        );
    }

    return true;
}

function findMysqlBinary(): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates = ['mysql.exe', 'mysql'];
        foreach ($candidates as $bin) {
            $found = trim((string) shell_exec('where ' . escapeshellarg($bin) . ' 2>nul'));
            if ($found !== '' && is_executable(explode("\n", $found)[0])) {
                return explode("\n", $found)[0];
            }
        }
        return null;
    }

    $found = trim((string) shell_exec('command -v mysql 2>/dev/null'));
    return ($found !== '' && is_executable($found)) ? $found : null;
}

function applyViaPdo(string $path): void
{
    $host    = Env::require('DB_HOST');
    $port    = Env::int('DB_PORT', 3306);
    $dbname  = Env::require('DB_NAME');
    $charset = Env::get('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

    $pdo = new PDO(
        $dsn,
        Env::require('DB_USER'),
        Env::require('DB_PASS'),
        [
            PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS   => true,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new \RuntimeException("Migration file is empty: {$path}");
    }

    $pdo->exec($sql);

    // Drain remaining result sets from multi-statement execution
    while ($pdo->nextRowset()) {
        // no-op
    }
}

function recordMigration(Database $db, array $migration): void
{
    if (!tableExists($db, 'schema_migrations')) {
        throw new \RuntimeException('schema_migrations table missing after migration');
    }

    $exists = $db->fetchValue(
        'SELECT id FROM schema_migrations WHERE version = ?',
        [$migration['version']]
    );

    if ($exists) {
        return;
    }

    $checksum = hash_file('sha256', $migration['path']);

    $db->execute(
        'INSERT INTO schema_migrations (version, description, checksum) VALUES (?, ?, ?)',
        [$migration['version'], $migration['description'], $checksum]
    );
}

function showStatus(Database $db): void
{
    $files   = discoverMigrationFiles();
    $applied = getAppliedVersionMap($db);

    out('NexAlert migrations');
    out(str_repeat('-', 50));

    if ($files === []) {
        out('No migration files in db/.');
        return;
    }

    foreach ($files as $migration) {
        $state = isset($applied[$migration['version']]) ? 'applied' : 'pending';
        out(sprintf('  [%s] %s — %s', $state, $migration['version'], $migration['description']));
    }

    $pending = count(array_filter(
        $files,
        static function (array $m) use ($applied): bool {
            return !isset($applied[$m['version']]);
        }
    ));
    out('');
    out($pending . ' pending, ' . (count($files) - $pending) . ' applied.');
}

// ---------------------------------------------------------------------------
// Dev seed: default org + super_admin user
// ---------------------------------------------------------------------------

function seedDevAdmin(Database $db, bool $resetAdmin): void
{
    if (!tableExists($db, 'users')) {
        throw new \RuntimeException('users table not found — run migrations first');
    }

    $username = 'admin';
    $password = Env::get('DEV_ADMIN_PASSWORD', 'YourStrongPassword');
    $cost     = Env::int('BCRYPT_COST', 12);
    $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);

    if ($hash === false) {
        throw new \RuntimeException('password_hash() failed');
    }

    $existingId = $db->fetchValue('SELECT id FROM users WHERE username = ?', [$username]);

    if ($existingId && !$resetAdmin) {
        upgradeAdminEmailContacts($db);
        out('Admin user already exists (use --reset-admin to update password).');
        return;
    }

    out($existingId ? 'Resetting admin user...' : 'Seeding development admin...');

    $db->transaction(function (Database $db) use ($username, $hash, $existingId, $resetAdmin): void {
        $orgId  = ensureSeedOrg($db);
        $nodeId = ensureSeedOrgRootNode($db, $orgId);

        if ($existingId && $resetAdmin) {
            $db->execute(
                'UPDATE users SET local_password_hash = ?, is_active = 1, is_locked = 0 WHERE id = ?',
                [$hash, $existingId]
            );
            ensureSuperAdminRole($db, (int) $existingId, $orgId);
            ensureAdminEmail($db, (int) $existingId);
            upgradeAdminEmailContacts($db);
            out('  ✓ admin password reset');
            return;
        }

        $db->execute(
            'INSERT INTO users
                (username, display_name, first_name, last_name, home_org_id, home_node_id,
                 local_password_hash, timezone, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $username,
                'Administrator',
                'Admin',
                'User',
                $orgId,
                $nodeId,
                $hash,
                Env::get('APP_TIMEZONE', 'America/Chicago'),
                'en',
            ]
        );
        $userId = $db->lastInsertId();

        ensureSuperAdminRole($db, $userId, $orgId);
        ensureAdminEmail($db, $userId);

        out('  ✓ user admin (id ' . $userId . ')');
        out('  ✓ super_admin role assigned');
    });
}

function ensureSeedOrg(Database $db): int
{
    $slug = 'nexalert-dev';
    $id   = $db->fetchValue('SELECT id FROM organizations WHERE slug = ?', [$slug]);

    if ($id) {
        return (int) $id;
    }

    $db->execute(
        'INSERT INTO organizations (name, slug, display_name, primary_color)
         VALUES (?, ?, ?, ?)',
        ['NexAlert Dev', $slug, 'NexAlert Development', '#e51c1c']
    );

    $orgId = $db->lastInsertId();
    out('  ✓ organization NexAlert Dev (id ' . $orgId . ')');

    return $orgId;
}

function ensureSeedOrgRootNode(Database $db, int $orgId): int
{
    $id = $db->fetchValue(
        'SELECT id FROM org_nodes WHERE org_id = ? AND parent_id IS NULL',
        [$orgId]
    );

    if ($id) {
        return (int) $id;
    }

    $db->execute(
        "INSERT INTO org_nodes (org_id, parent_id, node_type, name, slug, path, depth)
         VALUES (?, NULL, 'org', ?, ?, ?, 0)",
        [$orgId, 'NexAlert Dev', 'nexalert-dev', "/{$orgId}/"]
    );

    $nodeId = $db->lastInsertId();
    out('  ✓ root org node (id ' . $nodeId . ')');

    return $nodeId;
}

function ensureSuperAdminRole(Database $db, int $userId, int $orgId): void
{
    $roleId = $db->fetchValue("SELECT id FROM roles WHERE name = 'super_admin'");
    if (!$roleId) {
        throw new \RuntimeException('super_admin role not found — run migrations first');
    }

    $hasRole = $db->fetchValue(
        'SELECT ur.id FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         WHERE ur.user_id = ? AND r.name = ?',
        [$userId, 'super_admin']
    );

    if (!$hasRole) {
        $db->execute(
            'INSERT INTO user_roles (user_id, role_id, org_id) VALUES (?, ?, NULL)',
            [$userId, $roleId]
        );
    }

    $recipientRole = $db->fetchValue("SELECT id FROM roles WHERE name = 'recipient'");
    if ($recipientRole) {
        $db->execute(
            'INSERT IGNORE INTO user_roles (user_id, role_id, org_id) VALUES (?, ?, ?)',
            [$userId, $recipientRole, $orgId]
        );
    }
}

function isFqdnEmail(string $email): bool
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = strtolower((string) substr(strrchr($email, '@'), 1));
    if ($domain === '' || !str_contains($domain, '.')) {
        return false;
    }

    return !in_array($domain, ['localhost', 'local', 'test', 'invalid'], true);
}

function resolveDevAdminEmail(): string
{
    $configured = trim(Env::get('DEV_ADMIN_EMAIL', ''));
    if ($configured !== '' && isFqdnEmail($configured)) {
        return $configured;
    }

    $from = trim(Env::get('MAIL_FROM_ADDRESS', ''));
    if ($from !== '' && isFqdnEmail($from)) {
        return $from;
    }

    $appUrl = trim(Env::get('APP_URL', ''));
    if ($appUrl !== '') {
        $host = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && str_contains($host, '.')) {
            return 'admin@' . $host;
        }
    }

    return $configured !== '' ? $configured : 'admin@localhost';
}

function upgradeAdminEmailContacts(Database $db): void
{
    $target = resolveDevAdminEmail();
    if (!isFqdnEmail($target)) {
        return;
    }

    $adminId = $db->fetchValue('SELECT id FROM users WHERE username = ?', ['admin']);
    if (!$adminId) {
        return;
    }

    $bad = $db->fetchAll(
        "SELECT id, contact_value FROM user_contacts
         WHERE user_id = ? AND channel = 'email' AND is_active = 1
           AND (contact_value = 'admin@localhost'
                OR contact_value NOT LIKE '%@%.%')",
        [(int) $adminId]
    );

    foreach ($bad as $row) {
        $db->execute(
            'UPDATE user_contacts
             SET contact_value = ?, is_verified = 1, verified_at = COALESCE(verified_at, NOW())
             WHERE id = ?',
            [$target, (int) $row['id']]
        );
        out('  ✓ admin email updated: ' . $row['contact_value'] . ' → ' . $target);
    }
}

function ensureAdminEmail(Database $db, int $userId): void
{
    $email = resolveDevAdminEmail();

    $exists = $db->fetchValue(
        'SELECT id FROM user_contacts WHERE user_id = ? AND channel = ? AND contact_value = ?',
        [$userId, 'email', $email]
    );

    if ($exists) {
        upgradeAdminEmailContacts($db);
        return;
    }

    if (!isFqdnEmail($email)) {
        out('  ⚠ admin email is not FQDN (' . $email . ') — set DEV_ADMIN_EMAIL or MAIL_FROM_ADDRESS');
    }

    $db->execute(
        'INSERT INTO user_contacts (user_id, channel, contact_value, label, is_primary, is_verified, verified_at)
         VALUES (?, ?, ?, ?, 1, 1, NOW())',
        [$userId, 'email', $email, 'Work']
    );

    upgradeAdminEmailContacts($db);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function out(string $message): void
{
    echo $message . "\n";
}

function printHelp(): void
{
    echo <<<HELP
NexAlert migrate.php — database migrations and dev seed

Usage:
  php migrate.php                 Run pending migrations + seed admin
  php migrate.php --status        Show applied vs pending migrations
  php migrate.php --migrate-only  Apply SQL migrations only
  php migrate.php --seed-only     Seed dev org + admin only
  php migrate.php --reset-admin   Create or reset admin password
  php migrate.php --repair-admin-email Fix admin@localhost → DEV_ADMIN_EMAIL / MAIL_FROM
  php migrate.php --force         Allow seed/migrate when APP_ENV=production
  php migrate.php --help          Show this help

Environment (.env):
  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS  Required
  DEV_ADMIN_PASSWORD                           Default: YourStrongPassword
  DEV_ADMIN_EMAIL                              Default: MAIL_FROM_ADDRESS or admin@APP_URL host
  BCRYPT_COST                                  Default: 12

Default admin credentials (development):
  username: admin
  password: YourStrongPassword

HELP;
}
