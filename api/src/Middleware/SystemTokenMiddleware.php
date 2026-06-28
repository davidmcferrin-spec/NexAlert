<?php
/**
 * NexAlert - System Token Middleware
 * Validates Bearer tokens issued to external systems (CheckMK, XPression, etc.)
 * Sets $request->token with the token record from system_tokens table.
 */

declare(strict_types=1);

namespace NexAlert\Middleware;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Logger;

class SystemTokenMiddleware
{
    /**
     * Middleware callable that validates a system API token.
     */
    public static function required(): callable
    {
        return function (Request $request, callable $next): void {
            $raw = $request->bearerToken();
            if ($raw === null) {
                Response::unauthorized('System API token required');
            }

            $hash = hash('sha256', $raw);
            $db   = Database::getInstance();

            $token = $db->fetchOne(
                'SELECT id, name, owner_org_id, allowed_severity, allowed_alert_types,
                        ip_allowlist, expires_at
                 FROM system_tokens
                 WHERE token_hash = ? AND is_active = 1',
                [$hash]
            );

            if (!$token) {
                Logger::warning('Invalid system token attempt', ['ip' => $request->ip()]);
                Response::unauthorized('Invalid or revoked system token');
            }

            // Check expiry
            if ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
                Response::unauthorized('System token has expired');
            }

            // Check IP allowlist if configured
            if (!empty($token['ip_allowlist'])) {
                $allowed = array_map('trim', explode(',', $token['ip_allowlist']));
                if (!self::ipInList($request->ip(), $allowed)) {
                    Logger::warning('System token used from disallowed IP', [
                        'token_id' => $token['id'],
                        'ip'       => $request->ip(),
                    ]);
                    Response::forbidden('IP address not permitted for this token');
                }
            }

            // Update last used timestamp (non-blocking; errors here are non-fatal)
            try {
                $db->execute(
                    'UPDATE system_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?',
                    [$request->ip(), $token['id']]
                );
            } catch (\Throwable $e) {
                Logger::warning('Failed to update token last_used', ['error' => $e->getMessage()]);
            }

            $request->token = $token;
            $next($request);
        };
    }

    /**
     * Check if an IP matches any entry in a CIDR allowlist.
     * Supports exact IPs and basic CIDR notation.
     */
    private static function ipInList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if ($ip === $entry) {
                return true;
            }
            // Basic CIDR check
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = explode('/', $entry, 2);
                $ipLong     = ip2long($ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong !== false && $subnetLong !== false) {
                    $mask = -1 << (32 - (int) $bits);
                    if (($ipLong & $mask) === ($subnetLong & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
