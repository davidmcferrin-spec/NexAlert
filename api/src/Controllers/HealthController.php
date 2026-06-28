<?php
/**
 * NexAlert - Health Check Controller
 * GET /api/v1/health - Public endpoint for uptime monitoring (CheckMK, etc.)
 * GET /api/v1/health/deep - Auth-required deep check (DB + Redis)
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Env;

class HealthController
{
    /**
     * GET /api/v1/health
     * Lightweight check - confirms PHP and app are responding.
     */
    public static function ping(Request $request): never
    {
        Response::success([
            'status'  => 'ok',
            'app'     => Env::get('APP_NAME', 'NexAlert'),
            'env'     => Env::get('APP_ENV', 'unknown'),
            'time'    => date('c'),
        ], 'OK');
    }

    /**
     * GET /api/v1/health/deep
     * Full dependency check. Requires auth to prevent information disclosure.
     */
    public static function deep(Request $request): never
    {
        $checks = [];
        $allOk  = true;

        // Database check
        try {
            $db      = Database::getInstance();
            $version = $db->fetchValue('SELECT VERSION()');
            $checks['database'] = ['status' => 'ok', 'version' => $version];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            $allOk = false;
        }

        // Redis check
        try {
            $redis = new \Redis();
            $redis->connect(
                Env::get('REDIS_HOST', '127.0.0.1'),
                Env::int('REDIS_PORT', 6379),
                1.0
            );
            $pass = Env::get('REDIS_PASS');
            if ($pass) {
                $redis->auth($pass);
            }
            $pong = $redis->ping('nexalert');
            $checks['redis'] = ['status' => $pong === 'nexalert' ? 'ok' : 'error'];
        } catch (\Throwable $e) {
            $checks['redis'] = ['status' => 'error', 'message' => $e->getMessage()];
            $allOk = false;
        }

        // Schema version check
        try {
            $version = Database::getInstance()->fetchValue(
                "SELECT version FROM schema_migrations ORDER BY id DESC LIMIT 1"
            );
            $checks['schema'] = ['status' => 'ok', 'version' => $version];
        } catch (\Throwable $e) {
            $checks['schema'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        Response::json([
            'success' => $allOk,
            'status'  => $allOk ? 'ok' : 'degraded',
            'time'    => date('c'),
            'checks'  => $checks,
        ], $allOk ? 200 : 503);
    }
}
