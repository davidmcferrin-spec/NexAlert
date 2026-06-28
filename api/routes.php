<?php
/**
 * NexAlert - API Route Definitions
 */

declare(strict_types=1);

use NexAlert\Api\Router;
use NexAlert\Controllers\AuthController;
use NexAlert\Controllers\HealthController;
use NexAlert\Controllers\OrgController;
use NexAlert\Controllers\NodeController;
use NexAlert\Controllers\UserController;
use NexAlert\Middleware\AuthMiddleware;
use NexAlert\Middleware\RateLimitMiddleware;

return function (Router $router): void {

    // -----------------------------------------------------------------------
    // Health
    // -----------------------------------------------------------------------
    $router->get('/api/v1/health',      [HealthController::class, 'ping']);
    $router->get('/api/v1/health/deep', [HealthController::class, 'deep'], [
        AuthMiddleware::required(),
    ]);

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------
    $router->group('/api/v1/auth', function (Router $r): void {
        $r->post('/login',          [AuthController::class, 'login'],         [RateLimitMiddleware::auth()]);
        $r->post('/refresh',        [AuthController::class, 'refresh'],       [RateLimitMiddleware::perIp(30, 60)]);
        $r->post('/logout',         [AuthController::class, 'logout'],        [AuthMiddleware::required()]);
        $r->post('/forgot-password',[AuthController::class, 'forgotPassword'],[RateLimitMiddleware::auth()]);
        $r->post('/reset-password', [AuthController::class, 'resetPassword'], [RateLimitMiddleware::auth()]);
    });

    // -----------------------------------------------------------------------
    // Organizations
    // -----------------------------------------------------------------------
    $router->group('/api/v1/orgs', function (Router $r): void {
        $r->get('/',            [OrgController::class, 'list'],   [AuthMiddleware::required()]);
        $r->post('/',           [OrgController::class, 'create'], [AuthMiddleware::withPermission('org.manage')]);
        $r->get('/{id:\d+}',   [OrgController::class, 'get'],    [AuthMiddleware::required()]);
        $r->put('/{id:\d+}',   [OrgController::class, 'update'], [AuthMiddleware::withPermission('org.manage')]);
        $r->delete('/{id:\d+}',[OrgController::class, 'delete'], [AuthMiddleware::withPermission('org.manage')]);

        // Org nodes (nested under org)
        $r->get('/{org_id:\d+}/nodes',                    [NodeController::class, 'list'],   [AuthMiddleware::required()]);
        $r->post('/{org_id:\d+}/nodes',                   [NodeController::class, 'create'], [AuthMiddleware::withPermission('org.node.manage')]);
        $r->get('/{org_id:\d+}/nodes/{id:\d+}',           [NodeController::class, 'get'],    [AuthMiddleware::required()]);
        $r->put('/{org_id:\d+}/nodes/{id:\d+}',           [NodeController::class, 'update'], [AuthMiddleware::withPermission('org.node.manage')]);
        $r->delete('/{org_id:\d+}/nodes/{id:\d+}',        [NodeController::class, 'delete'], [AuthMiddleware::withPermission('org.node.manage')]);
        $r->put('/{org_id:\d+}/nodes/{id:\d+}/move',      [NodeController::class, 'move'],   [AuthMiddleware::withPermission('org.node.manage')]);
    });

    // -----------------------------------------------------------------------
    // Users
    // -----------------------------------------------------------------------
    $router->group('/api/v1/users', function (Router $r): void {
        $r->get('/',              [UserController::class, 'list'],   [AuthMiddleware::withPermission('user.view')]);
        $r->post('/',             [UserController::class, 'create'], [AuthMiddleware::withPermission('user.manage')]);
        $r->post('/import',       [UserController::class, 'import'], [AuthMiddleware::withPermission('user.import')]);
        $r->get('/{id:\d+}',     [UserController::class, 'get'],    [AuthMiddleware::required()]);
        $r->put('/{id:\d+}',     [UserController::class, 'update'], [AuthMiddleware::required()]);
        $r->delete('/{id:\d+}',  [UserController::class, 'delete'], [AuthMiddleware::withPermission('user.manage')]);

        // Memberships
        $r->get('/{id:\d+}/memberships',           [UserController::class, 'listMemberships'],  [AuthMiddleware::required()]);
        $r->post('/{id:\d+}/memberships',          [UserController::class, 'addMembershipRoute'],[AuthMiddleware::withPermission('user.manage')]);
        $r->delete('/{id:\d+}/memberships/{mid:\d+}', [UserController::class, 'removeMembership'],[AuthMiddleware::withPermission('user.manage')]);

        // Tags
        $r->get('/{id:\d+}/tags',           [UserController::class, 'listTags'],  [AuthMiddleware::required()]);
        $r->post('/{id:\d+}/tags',          [UserController::class, 'assignTag'], [AuthMiddleware::withPermission('tag.manage')]);
        $r->delete('/{id:\d+}/tags/{tag_id:\d+}', [UserController::class, 'removeTag'], [AuthMiddleware::withPermission('tag.manage')]);
    });

};
