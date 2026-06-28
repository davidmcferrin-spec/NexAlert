<?php
/**
 * NexAlert - Rate Limiter Middleware
 * Sliding window rate limiting backed by Redis.
 * Protects auth endpoints from brute force.
 */

declare(strict_types=1);

namespace NexAlert\Middleware;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Env;
use NexAlert\Config\Logger;

class RateLimitMiddleware
{
    /**
     * Rate limit by IP address.
     *
     * @param int $maxRequests Maximum requests in the window
     * @param int $windowSeconds Window duration in seconds
     */
    public static function perIp(int $maxRequests = 60, int $windowSeconds = 60): callable
    {
        return function (Request $request, callable $next) use ($maxRequests, $windowSeconds): void {
            $key = 'ratelimit:ip:' . md5($request->ip());
            self::check($key, $maxRequests, $windowSeconds, $request);
            $next($request);
        };
    }

    /**
     * Stricter rate limiting for auth endpoints.
     * 10 attempts per 5 minutes per IP.
     */
    public static function auth(): callable
    {
        return function (Request $request, callable $next): void {
            $key = 'ratelimit:auth:' . md5($request->ip());
            self::check($key, 10, 300, $request);
            $next($request);
        };
    }

    private static function check(string $key, int $max, int $window, Request $request): void
    {
        $redis = self::redis();
        if ($redis === null) {
            // Redis unavailable: fail open with a warning (don't block requests)
            Logger::warning('Rate limiter: Redis unavailable, skipping check');
            return;
        }

        try {
            $current = (int) $redis->get($key);

            if ($current >= $max) {
                Logger::warning('Rate limit exceeded', ['key' => $key, 'ip' => $request->ip()]);
                Response::json([
                    'success' => false,
                    'error'   => 'Too many requests. Please try again later.',
                ], 429, ['Retry-After' => (string) $window]);
            }

            $pipe = $redis->pipeline();
            $pipe->incr($key);
            $pipe->expire($key, $window);
            $pipe->exec();
        } catch (\Throwable $e) {
            Logger::warning('Rate limiter error', ['error' => $e->getMessage()]);
            // Fail open
        }
    }

    private static function redis(): ?\Redis
    {
        static $redis = null;

        if ($redis !== null) {
            return $redis;
        }

        try {
            $r = new \Redis();
            $r->connect(
                Env::get('REDIS_HOST', '127.0.0.1'),
                Env::int('REDIS_PORT', 6379),
                2.0 // connection timeout
            );
            $pass = Env::get('REDIS_PASS');
            if ($pass) {
                $r->auth($pass);
            }
            $r->select(Env::int('REDIS_DB_QUEUE', 0));
            $redis = $r;
            return $redis;
        } catch (\Throwable $e) {
            Logger::warning('Redis connection failed in rate limiter', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
