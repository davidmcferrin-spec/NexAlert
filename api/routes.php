<?php
/**
 * NexAlert - API Route Definitions
 * All routes registered here. Imported by public/index.php.
 *
 * Convention:
 *   GET    /api/v1/{resource}         → list / search
 *   POST   /api/v1/{resource}         → create
 *   GET    /api/v1/{resource}/{id}    → get one
 *   PUT    /api/v1/{resource}/{id}    → full update
 *   PATCH  /api/v1/{resource}/{id}    → partial update
 *   DELETE /api/v1/{resource}/{id}    → delete / deactivate
 */

declare(strict_types=1);

use NexAlert\Api\Router;
use NexAlert\Controllers\AuthController;
use NexAlert\Controllers\HealthController;
use NexAlert\Middleware\AuthMiddleware;
use NexAlert\Middleware\RateLimitMiddleware;
use NexAlert\Middleware\SystemTokenMiddleware;

return function (Router $router): void {

    // -----------------------------------------------------------------------
    // Health
    // -----------------------------------------------------------------------
    $router->get('/api/v1/health', [HealthController::class, 'ping']);
    $router->get('/api/v1/health/deep', [HealthController::class, 'deep'], [
        AuthMiddleware::required(),
    ]);

    // -----------------------------------------------------------------------
    // Authentication (local + refresh)
    // -----------------------------------------------------------------------
    $router->group('/api/v1/auth', function (Router $r): void {

        $r->post('/login',          [AuthController::class, 'login'],         [RateLimitMiddleware::auth()]);
        $r->post('/refresh',        [AuthController::class, 'refresh'],       [RateLimitMiddleware::perIp(30, 60)]);
        $r->post('/logout',         [AuthController::class, 'logout'],        [AuthMiddleware::required()]);
        $r->post('/forgot-password',[AuthController::class, 'forgotPassword'],[RateLimitMiddleware::auth()]);
        $r->post('/reset-password', [AuthController::class, 'resetPassword'], [RateLimitMiddleware::auth()]);

    });

    // -----------------------------------------------------------------------
    // Organizations  (Phase 1 - stubbed, controllers added next session)
    // -----------------------------------------------------------------------
    // $router->group('/api/v1/orgs', function (Router $r): void {
    //     $r->get('/',           [OrgController::class, 'list'],   [AuthMiddleware::required()]);
    //     $r->post('/',          [OrgController::class, 'create'], [AuthMiddleware::withPermission('org.manage')]);
    //     $r->get('/{id:\d+}',   [OrgController::class, 'get'],    [AuthMiddleware::required()]);
    //     $r->put('/{id:\d+}',   [OrgController::class, 'update'], [AuthMiddleware::withPermission('org.manage')]);
    //     $r->delete('/{id:\d+}',[OrgController::class, 'delete'], [AuthMiddleware::withPermission('org.manage')]);
    // });

    // -----------------------------------------------------------------------
    // Users  (Phase 1 - stubbed)
    // -----------------------------------------------------------------------
    // $router->group('/api/v1/users', function (Router $r): void {
    //     $r->get('/',           [UserController::class, 'list'],   [AuthMiddleware::withPermission('user.view')]);
    //     $r->post('/',          [UserController::class, 'create'], [AuthMiddleware::withPermission('user.manage')]);
    //     $r->get('/{id:\d+}',   [UserController::class, 'get'],    [AuthMiddleware::withPermission('user.view')]);
    //     $r->put('/{id:\d+}',   [UserController::class, 'update'], [AuthMiddleware::withPermission('user.manage')]);
    //     $r->delete('/{id:\d+}',[UserController::class, 'delete'], [AuthMiddleware::withPermission('user.manage')]);
    // });

    // -----------------------------------------------------------------------
    // Inbound Alert API - system token auth (Phase 2)
    // -----------------------------------------------------------------------
    // $router->post('/api/v1/alert', [AlertController::class, 'inbound'], [
    //     SystemTokenMiddleware::required(),
    // ]);

};
