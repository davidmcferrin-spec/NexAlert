<?php
/**
 * Web session auth helpers — keeps PHP session in sync with JWT expiry.
 */

declare(strict_types=1);

use NexAlert\Config\Database;
use NexAlert\Config\Env;
use NexAlert\Services\JwtService;

/** Seconds before expiry to proactively refresh the access token. */
const WEB_TOKEN_REFRESH_BUFFER = 60;

function web_clear_auth_session(): void
{
    unset(
        $_SESSION['access_token'],
        $_SESSION['refresh_token'],
        $_SESSION['token_expires'],
        $_SESSION['user'],
    );
}

function web_try_refresh_session(): bool
{
    $refresh = trim((string) ($_SESSION['refresh_token'] ?? ''));
    if ($refresh === '') {
        return false;
    }

    try {
        $jwt     = new JwtService();
        $payload = $jwt->decode($refresh, 'refresh');

        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, display_name, home_org_id, is_active, is_locked
             FROM users WHERE id = ?',
            [$payload['uid']]
        );

        if (!$user || !(int) $user['is_active'] || (int) $user['is_locked']) {
            web_clear_auth_session();

            return false;
        }

        $roles = $db->fetchAll(
            'SELECT r.name FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [$user['id']]
        );
        $roleNames = array_column($roles, 'name');

        $_SESSION['access_token']  = $jwt->issueAccessToken($user, $roleNames);
        $_SESSION['token_expires'] = time() + Env::int('JWT_TTL_MINUTES', 480) * 60;
        $_SESSION['user']          = [
            'id'           => (int) $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'home_org_id'  => $user['home_org_id'],
            'roles'        => $roleNames,
        ];

        return true;
    } catch (\Throwable) {
        web_clear_auth_session();

        return false;
    }
}

function web_access_token_valid(): bool
{
    if (empty($_SESSION['access_token'])) {
        return false;
    }

    $expires = (int) ($_SESSION['token_expires'] ?? 0);
    if ($expires > time() + WEB_TOKEN_REFRESH_BUFFER) {
        return true;
    }

    return web_try_refresh_session();
}

function web_auth(): bool
{
    return !empty($_SESSION['user']) && web_access_token_valid();
}

function require_auth(): void
{
    if (web_auth()) {
        return;
    }

    web_clear_auth_session();
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/admin';
    header('Location: /admin/login?expired=1');
    exit;
}
