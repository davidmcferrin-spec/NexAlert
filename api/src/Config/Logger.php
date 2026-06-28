<?php
/**
 * NexAlert - Logger
 * Structured JSON logger writing to the configured log path.
 * Levels: debug, info, warning, error, critical
 */

declare(strict_types=1);

namespace NexAlert\Config;

class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    private static ?string $logPath = null;
    private static int $minLevel    = 0;

    public static function init(): void
    {
        self::$logPath = Env::get('LOG_PATH', '/tmp/nexalert.log');
        $levelName     = Env::get('LOG_LEVEL', 'debug');
        self::$minLevel = self::LEVELS[$levelName] ?? 0;

        // Ensure log directory exists
        $dir = dirname(self::$logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('critical', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if ((self::LEVELS[$level] ?? 0) < self::$minLevel) {
            return;
        }

        if (self::$logPath === null) {
            self::init();
        }

        $entry = [
            'ts'      => date('c'),
            'level'   => $level,
            'message' => $message,
            'pid'     => getmypid(),
        ];

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        // Request context if available
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $entry['request'] = [
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'ip'     => self::clientIp(),
            ];
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        // Use file locking to prevent corruption under concurrent writes
        file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX);
    }

    private static function clientIp(): string
    {
        // Dreamhost / proxied environments
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }
}
