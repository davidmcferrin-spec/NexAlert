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

require_once NEXALERT_ROOT . '/web/helpers/ui.php';

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

/** Same host the browser uses — avoids APP_URL mismatches in server-side API calls. */
function web_api_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
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

function render_profile(string $page, array $vars = []): void
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
    include NEXALERT_ROOT . '/web/templates/layouts/profile.php';
}

// -----------------------------------------------------------------------
// Route table
// -----------------------------------------------------------------------
$routes = [
    'GET'  => [
        '/admin/login'            => 'auth/login',
        '/admin/logout'           => 'auth/logout',
        '/forgot-password'        => 'auth/forgot_password',
        '/reset-password'         => 'auth/reset_password',
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
        '/admin/tags'             => 'tags/index',
        '/admin/tags/new'         => 'tags/form',
        '/admin/tags/edit'        => 'tags/form',
        '/admin/test-send'        => 'test-send/index',
        '/admin/alerts'           => 'alerts/form',
        '/admin/alerts/new'       => 'alerts/form',
        '/admin/alerts/history'   => 'alerts/index',
        '/admin/alerts/send'      => 'alerts/form',
        '/admin/tokens'           => 'tokens/index',
        '/admin/tokens/new'       => 'tokens/form',
        '/admin/tokens/edit'      => 'tokens/form',
        '/admin/audit'            => 'audit/index',
        '/profile'                => 'profile/index',
        '/profile/verify-email'   => 'profile/verify_email',
        '/poll/vote'              => 'poll/vote',
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
        '/admin/tags/save'        => 'tags/save',
        '/admin/tags/delete'      => 'tags/delete',
        '/admin/tokens/save'      => 'tokens/save',
        '/admin/tokens/regenerate' => 'tokens/regenerate',
        '/admin/tokens/delete'    => 'tokens/delete',
    ],
];

// -----------------------------------------------------------------------
// Dispatch
// -----------------------------------------------------------------------
$page = $routes[$method][$uri] ?? $routes[$method][rtrim($uri, '/')] ?? null;

$publicPages = ['auth/login', 'auth/login_post', 'auth/forgot_password', 'auth/reset_password', 'profile/verify_email', 'poll/vote'];
$profilePages = ['profile/index', 'profile/verify_email', 'poll/vote'];
$normalizedUri = rtrim($uri, '/') ?: '/';

if (str_starts_with($normalizedUri, '/admin')) {
    $isPublic = $page !== null && in_array($page, $publicPages, true);
    if (!$isPublic) {
        require_auth();
    }
}

if ($page !== null && in_array($page, ['profile/index'], true) && !web_auth()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/profile';
    header('Location: /admin/login');
    exit;
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
    'tags/save',
    'tags/delete',
    'tokens/save',
    'tokens/regenerate',
    'tokens/delete',
];

if (in_array($page, $actionPages, true)) {
    include $handlerPath;
    exit;
}

if ($page === 'auth/login' || $page === 'auth/forgot_password' || $page === 'auth/reset_password') {
    render_auth($page);
    exit;
}

if ($page !== null && in_array($page, $profilePages, true)) {
    render_profile($page);
    exit;
}

render($page);
