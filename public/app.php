<?php
/**
 * NexAlert - Web Frontend Entry Point
 * All non-API, non-static requests land here via .htaccess rewrite.
 * Phase 1: Minimal placeholder. Full frontend added in next session.
 */

declare(strict_types=1);

define('NEXALERT_ROOT', dirname(__DIR__));

require_once NEXALERT_ROOT . '/api/autoload.php';

use NexAlert\Config\Env;
use NexAlert\Config\Logger;

Env::load(NEXALERT_ROOT);
Logger::init();

$appName = Env::get('APP_NAME', 'NexAlert');
$appEnv  = Env::get('APP_ENV', 'development');

// Route to appropriate page (Phase 1: just renders a landing page)
$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 text-white min-h-screen flex items-center justify-center">
    <div class="text-center space-y-4">
        <div class="text-5xl font-bold tracking-tight">
            Nex<span class="text-red-500">Alert</span>
        </div>
        <p class="text-gray-400 text-lg">Mass Notification Platform</p>
        <p class="text-gray-600 text-sm">
            Environment: <span class="text-gray-400"><?= htmlspecialchars($appEnv) ?></span>
        </p>
        <div class="pt-4">
            <a href="/api/v1/health"
               class="text-xs text-gray-600 hover:text-gray-400 underline">
                API Health Check
            </a>
        </div>
        <p class="text-gray-700 text-xs pt-8">Web frontend coming in Phase 1 next session.</p>
    </div>
</body>
</html>
