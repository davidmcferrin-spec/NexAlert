<?php
/**
 * NexAlert - Poll Controller
 * Public signed-link vote endpoint (email one-click).
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Services\PollService;

class PollController
{
    public static function voteViaLink(Request $request): never
    {
        $alertId = (int) $request->query('alert_id', 0);
        $userId  = (int) $request->query('user_id', 0);
        $option  = trim((string) $request->query('option', ''));
        $sig     = trim((string) $request->query('sig', ''));

        if ($alertId <= 0 || $userId <= 0 || $option === '') {
            Response::validationError(['link' => 'Invalid vote link']);
        }

        $db      = Database::getInstance();
        $results = PollService::submitViaSignedLink($db, $alertId, $userId, $option, $sig);

        Response::success($results, 'Vote recorded');
    }
}
