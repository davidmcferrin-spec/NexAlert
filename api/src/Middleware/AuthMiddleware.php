<?php
/**
 * NexAlert - Auth Middleware
 * Validates JWT access tokens on protected routes.
 * Sets $request->user with the decoded payload.
 *
 * Usage: $router->get('/route', $handler, [AuthMiddleware::required()]);
 *        $router->get('/route', $handler, [AuthMiddleware::withPermission('alert.send')]);
 */

declare(strict_types=1);

namespace NexAlert\Middleware;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Logger;
use NexAlert\Services\JwtService;
use NexAlert\Services\PermissionService;

class AuthMiddleware
{
    /**
     * Return a middleware callable that requires a valid JWT.
     */
    public static function required(): callable
    {
        return function (Request $request, callable $next): void {
            self::authenticate($request);
            $next($request);
        };
    }

    /**
     * Return a middleware callable that requires a JWT AND a specific permission.
     */
    public static function withPermission(string $permission): callable
    {
        return function (Request $request, callable $next) use ($permission): void {
            self::authenticate($request);
            self::authorize($request, $permission);
            $next($request);
        };
    }

    /**
     * Return a middleware callable that requires super_admin role.
     */
    public static function superAdmin(): callable
    {
        return function (Request $request, callable $next): void {
            self::authenticate($request);
            if (!in_array('super_admin', $request->user['roles'] ?? [], true)) {
                Response::forbidden('Super admin access required');
            }
            $next($request);
        };
    }

    /**
     * Validate JWT and attach user to request. Terminates on failure.
     */
    private static function authenticate(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            Response::unauthorized('Authorization token required');
        }

        try {
            $jwt     = new JwtService();
            $payload = $jwt->decode($token, 'access');
        } catch (\RuntimeException $e) {
            Logger::warning('JWT validation failed', ['error' => $e->getMessage(), 'ip' => $request->ip()]);
            Response::unauthorized('Invalid or expired token');
        }

        // Verify user is still active in DB (cache this in Redis later for performance)
        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, display_name, home_org_id, is_active, is_locked FROM users WHERE id = ?',
            [$payload['uid']]
        );

        if (!$user || !$user['is_active'] || $user['is_locked']) {
            Response::unauthorized('Account is inactive or locked');
        }

        $request->user = array_merge($payload, [
            'db'          => $user,
            'permissions' => PermissionService::loadForUser($db, (int) $payload['uid']),
        ]);
    }

    /**
     * Check if the authenticated user has the required permission.
     */
    private static function authorize(Request $request, string $permission): void
    {
        $db = Database::getInstance();

        if (!PermissionService::hasPermission($db, $request->user, $permission)) {
            Logger::warning('Permission denied', [
                'user'       => $request->user['uid'],
                'permission' => $permission,
            ]);
            Response::forbidden("Permission required: {$permission}");
        }
    }
}
