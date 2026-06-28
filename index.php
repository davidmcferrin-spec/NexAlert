<?php
/**
 * NexAlert - API Entry Point
 * All /api/* requests land here via .htaccess rewrite.
 * Bootstraps environment, autoloader, and dispatches to router.
 */

declare(strict_types=1);

// Repo root IS the webroot on Dreamhost managed hosting
define('NEXALERT_ROOT', __DIR__);

// Autoloader (PSR-4, no Composer required)
require_once NEXALERT_ROOT . '/api/autoload.php';

use NexAlert\Config\Env;
use NexAlert\Config\Logger;
use NexAlert\Api\Router;
use NexAlert\Api\Response;

// Load environment
Env::load(NEXALERT_ROOT);

// Initialize logger
Logger::init();

// Global exception handler - catches anything the router doesn't
set_exception_handler(function (\Throwable $e): void {
    Logger::critical('Unhandled top-level exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    Response::json([
        'success' => false,
        'error'   => 'Internal Server Error',
        'message' => Env::isDevelopment() ? $e->getMessage() : 'An unexpected error occurred.',
    ], 500);
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// CORS headers - always sent, including on errors
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');

// Build and dispatch router
$router   = new Router();
$register = require_once NEXALERT_ROOT . '/api/routes.php';
$register($router);
$router->dispatch();