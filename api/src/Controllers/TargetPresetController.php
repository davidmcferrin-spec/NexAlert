<?php
/**
 * NexAlert - Target Preset Controller
 *
 * GET    /api/v1/targets/presets              → list presets
 * POST   /api/v1/targets/presets              → create preset
 * GET    /api/v1/targets/presets/{id}         → get preset
 * GET    /api/v1/targets/presets/by-slug/{slug} → get by slug
 * PUT    /api/v1/targets/presets/{id}         → update preset
 * DELETE /api/v1/targets/presets/{id}         → deactivate preset
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\TargetPresetService;

class TargetPresetController
{
    public static function list(Request $request): never
    {
        $db = Database::getInstance();

        $orgFilter = $request->query('org_id') !== null && $request->query('org_id') !== ''
            ? (int) $request->query('org_id')
            : null;
        $search = trim((string) $request->query('search', ''));

        $presets = TargetPresetService::list($db, $request->user, $orgFilter, $search);

        Response::success(['presets' => $presets]);
    }

    public static function show(Request $request): never
    {
        $db = Database::getInstance();
        $id = (int) $request->param('id');

        Response::success(TargetPresetService::get($db, $id, $request->user));
    }

    public static function showBySlug(Request $request): never
    {
        $db    = Database::getInstance();
        $slug  = (string) $request->param('slug');
        $orgId = $request->query('org_id') !== null && $request->query('org_id') !== ''
            ? (int) $request->query('org_id')
            : (int) ($request->user['org'] ?? 0);

        $preset = TargetPresetService::getBySlug($db, $slug, $orgId > 0 ? $orgId : null, $request->user);
        if ($preset === null) {
            Response::notFound('Target preset not found');
        }

        Response::success($preset);
    }

    public static function create(Request $request): never
    {
        $db = Database::getInstance();

        Response::success(
            TargetPresetService::create($db, $request->user, $request->all()),
            'Target preset created',
            201
        );
    }

    public static function update(Request $request): never
    {
        $db = Database::getInstance();
        $id = (int) $request->param('id');

        Response::success(
            TargetPresetService::update($db, $id, $request->user, $request->all()),
            'Target preset updated'
        );
    }

    public static function delete(Request $request): never
    {
        $db = Database::getInstance();
        $id = (int) $request->param('id');

        TargetPresetService::delete($db, $id, $request->user);

        Response::success(null, 'Target preset deleted');
    }
}
