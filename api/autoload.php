<?php
/**
 * NexAlert - PSR-4 Autoloader
 * No Composer required. Maps NexAlert\* namespace to api/src/
 * Must be the first require in any entry point.
 */

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    // Base namespace and directory map
    $namespaceMap = [
        'NexAlert\\' => __DIR__ . '/src/',
    ];

    foreach ($namespaceMap as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file          = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});