<?php
/**
 * NexAlert - Environment Loader
 * Parses .env file and populates $_ENV / getenv().
 * Must be the first thing loaded in any entry point.
 */

declare(strict_types=1);

namespace NexAlert\Config;

class Env
{
    private static bool $loaded = false;

    /**
     * Load .env file from the given path.
     * Safe to call multiple times - only loads once.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        $file = rtrim($path, '/') . '/.env';

        if (!file_exists($file)) {
            // In production this should be a hard failure
            if (self::get('APP_ENV') === 'production') {
                throw new \RuntimeException('.env file not found at: ' . $file);
            }
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip inline comments
            if (str_contains($value, ' #')) {
                $value = trim(explode(' #', $value, 2)[0]);
            }

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Don't overwrite existing environment variables (allows real env to win)
            if (!isset($_ENV[$key]) && getenv($key) === false) {
                $_ENV[$key]   = $value;
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * Get an environment variable with an optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Get a required environment variable. Throws if missing or empty.
     */
    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '' || $value === false) {
            throw new \RuntimeException("Required environment variable '{$key}' is not set.");
        }

        return (string) $value;
    }

    /**
     * Get an env var cast to bool.
     * Truthy values: "true", "1", "yes", "on"
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === false || $value === '') {
            return $default;
        }
        return in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get an env var cast to int.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return ($value !== null && $value !== false && $value !== '') ? (int) $value : $default;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }

    public static function isDevelopment(): bool
    {
        return self::get('APP_ENV', 'development') === 'development';
    }
}
