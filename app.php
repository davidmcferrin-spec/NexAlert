<?php
/**
 * NexAlert - Web Frontend Entry Point
 * Handles session, auth, and routes to the correct page template.
 */

declare(strict_types=1);

define('NEXALERT_ROOT', __DIR__);

require_once NEXALERT_ROOT . '/api/autoload.php';

use NexAlert\Config\Env;
use NexAlert\Config\Logger;

// Included page templates call Env::get() without their own use import
class_alias(Env::class, 'Env');

Env::load(NEXALERT_ROOT);
Logger::init();

session_start();

$uri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----------------------------------------------------------------------
// Helpers available to all page templates
// -----------------------------------------------------------------------
function web_auth(): bool
{
    return !empty($_SESSION['user']) && !empty($_SESSION['access_token']);
}

function require_auth(): void
{
    if (!web_auth()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/admin';
        header('Location: /admin/login');
        exit;
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function render(string $page, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $templatePath = NEXALERT_ROOT . '/web/templates/pages/' . $page . '.php';
    if (!file_exists($templatePath)) {
        http_response_code(404);
        die("Page not found: {$page}");
    }
    ob_start();
    include $templatePath;
    $content = ob_get_clean();
    include NEXALERT_ROOT . '/web/templates/layouts/admin.php';
}

function render_auth(string $page, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $templatePath = NEXALERT_ROOT . '/web/templates/pages/' . $page . '.php';
    if (!file_exists($templatePath)) {
        http_response_code(404);
        die("Page not found: {$page}");
    }
    ob_start();
    include $templatePath;
    $content = ob_get_clean();
    include NEXALERT_ROOT . '/web/templates/layouts/auth.php';
}

// -----------------------------------------------------------------------
// Route table
// -----------------------------------------------------------------------
$routes = [
    'GET'  => [
        '/admin/login'            => 'auth/login',
        '/admin/logout'           => 'auth/logout',
        '/admin'                  => 'dashboard',
        '/admin/orgs'             => 'orgs/index',
        '/admin/orgs/new'         => 'orgs/form',
        '/admin/orgs/edit'        => 'orgs/form',
        '/admin/users'            => 'users/index',
        '/admin/users/new'        => 'users/form',
        '/admin/users/edit'       => 'users/form',
        '/admin/users/import'     => 'users/import',
        '/admin/groups'           => 'groups/index',
        '/admin/groups/new'       => 'groups/form',
        '/admin/groups/edit'      => 'groups/form',
        '/admin/tokens'           => 'tokens/index',
        '/admin/tokens/new'       => 'tokens/form',
        '/admin/tokens/edit'      => 'tokens/form',
        '/admin/audit'            => 'audit/index',
    ],
    'POST' => [
        '/admin/login'            => 'auth/login_post',
        '/admin/orgs/save'        => 'orgs/save',
        '/admin/orgs/delete'      => 'orgs/delete',
        '/admin/orgs/node/save'   => 'orgs/node_save',
        '/admin/orgs/node/delete' => 'orgs/node_delete',
        '/admin/users/save'       => 'users/save',
        '/admin/users/delete'     => 'users/delete',
        '/admin/users/import'     => 'users/import_post',
        '/admin/groups/save'      => 'groups/save',
        '/admin/groups/delete'    => 'groups/delete',
        '/admin/tokens/save'      => 'tokens/save',
        '/admin/tokens/delete'    => 'tokens/delete',
    ],
];

// -----------------------------------------------------------------------
// Dispatch
// -----------------------------------------------------------------------
$page = $routes[$method][$uri] ?? $routes[$method][rtrim($uri, '/')] ?? null;

$publicPages = ['auth/login', 'auth/login_post'];
$normalizedUri = rtrim($uri, '/') ?: '/';

if (str_starts_with($normalizedUri, '/admin')) {
    $isPublic = $page !== null && in_array($page, $publicPages, true);
    if (!$isPublic) {
        require_auth();
    }
}

if ($page === null) {
    http_response_code(404);
    render('errors/404', ['pageTitle' => 'Not Found']);
    exit;
}

$handlerPath = NEXALERT_ROOT . '/web/templates/pages/' . $page . '.php';
if (!file_exists($handlerPath)) {
    http_response_code(500);
    die("Handler not found: {$page}");
}

// POST handlers and redirect-only GET handlers run directly
$actionPages = [
    'auth/login_post',
    'auth/logout',
    'orgs/save',
    'orgs/delete',
    'orgs/node_save',
    'orgs/node_delete',
    'users/save',
    'users/delete',
    'users/import_post',
    'groups/save',
    'groups/delete',
    'tokens/save',
    'tokens/delete',
];

if (in_array($page, $actionPages, true)) {
    include $handlerPath;
    exit;
}

if ($page === 'auth/login') {
    render_auth($page);
    exit;
}

render($page);
