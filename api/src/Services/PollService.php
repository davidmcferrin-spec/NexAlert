<?php
/**
 * NexAlert - Poll Service
 * Signed email vote links, response recording, and results aggregation.
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Env;

class PollService
{
    /**
     * @return array<string, mixed>
     */
    public static function submitResponse(
        Database $db,
        int $alertId,
        int $userId,
        string $responseValue,
        string $channel = 'web'
    ): array {
        $alert = self::assertCanRespond($db, $alertId, $userId);

        $option = self::matchOption($responseValue, self::parsePollOptions($alert));
        if ($option === null) {
            Response::validationError(['response_value' => 'Invalid poll option']);
        }

        try {
            $db->execute(
                'INSERT INTO poll_responses (alert_id, user_id, response_value, response_channel)
                 VALUES (?, ?, ?, ?)',
                [$alertId, $userId, $option, $channel]
            );
        } catch (\Throwable) {
            Response::error('You have already responded to this poll', 409);
        }

        AuditService::log('alert.poll_response', 'alert', (string) $alertId, [
            'user_id'        => $userId,
            'response_value' => $option,
            'channel'        => $channel,
        ], $userId);

        return self::getResults($db, $alertId);
    }

    /**
     * Public email link vote — validates HMAC signature.
     *
     * @return array<string, mixed>
     */
    public static function submitViaSignedLink(
        Database $db,
        int $alertId,
        int $userId,
        string $responseValue,
        string $signature
    ): array {
        if (!self::verifySignature($alertId, $userId, $responseValue, $signature)) {
            Response::forbidden('Invalid or tampered vote link');
        }

        return self::submitResponse($db, $alertId, $userId, $responseValue, 'email');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getResults(Database $db, int $alertId): array
    {
        $alert = $db->fetchOne(
            'SELECT id, alert_type, poll_question, poll_options, status, expires_at FROM alerts WHERE id = ?',
            [$alertId]
        );
        if (!$alert || $alert['alert_type'] !== 'poll') {
            return ['options' => [], 'total' => 0, 'responses' => []];
        }

        $options = self::parsePollOptions($alert);
        $counts  = array_fill_keys($options, 0);

        $rows = $db->fetchAll(
            'SELECT pr.user_id, pr.response_value, pr.response_channel, pr.responded_at,
                    u.display_name AS user_name, u.username
             FROM poll_responses pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.alert_id = ?
             ORDER BY pr.responded_at ASC',
            [$alertId]
        );

        foreach ($rows as $row) {
            $val = $row['response_value'];
            if (isset($counts[$val])) {
                $counts[$val]++;
            } else {
                $counts[$val] = 1;
            }
        }

        $optionStats = [];
        foreach ($options as $opt) {
            $count = $counts[$opt] ?? 0;
            $optionStats[] = [
                'option'     => $opt,
                'count'      => $count,
                'percentage' => count($rows) > 0 ? round(100 * $count / count($rows), 1) : 0.0,
            ];
        }

        return [
            'poll_question' => $alert['poll_question'],
            'options'       => $optionStats,
            'total'         => count($rows),
            'responses'     => $rows,
            'is_expired'    => self::isExpired($alert),
        ];
    }

    public static function signVote(int $alertId, int $userId, string $option): string
    {
        return substr(hash_hmac(
            'sha256',
            $alertId . ':' . $userId . ':' . $option,
            Env::require('APP_SECRET')
        ), 0, 32);
    }

    public static function verifySignature(int $alertId, int $userId, string $option, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(self::signVote($alertId, $userId, $option), $signature);
    }

    public static function buildVoteUrl(int $alertId, int $userId, string $option): string
    {
        $base = rtrim(Env::get('APP_URL', ''), '/');

        return $base . '/poll/vote?' . http_build_query([
            'alert_id' => $alertId,
            'user_id'  => $userId,
            'option'   => $option,
            'sig'      => self::signVote($alertId, $userId, $option),
        ]);
    }

    /**
     * @param array<string, mixed> $alert
     */
    public static function isExpired(array $alert): bool
    {
        if (($alert['status'] ?? '') === 'expired') {
            return true;
        }

        $expiresAt = $alert['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        return strtotime((string) $expiresAt) <= time();
    }

    /**
     * @return array<string, mixed>
     */
    private static function assertCanRespond(Database $db, int $alertId, int $userId): array
    {
        $alert = $db->fetchOne(
            'SELECT id, alert_type, poll_question, poll_options, status, expires_at
             FROM alerts WHERE id = ?',
            [$alertId]
        );

        if (!$alert) {
            Response::notFound('Alert not found');
        }

        if ($alert['alert_type'] !== 'poll') {
            Response::error('This alert is not a poll', 409);
        }

        if (self::isExpired($alert)) {
            Response::error('This poll has expired', 410);
        }

        if (!in_array($alert['status'], ['sending', 'sent'], true)) {
            Response::error('Poll is not open for responses', 409);
        }

        $isRecipient = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM alert_deliveries
             WHERE alert_id = ? AND user_id = ? AND status IN (\'sent\', \'delivered\', \'queued\')',
            [$alertId, $userId]
        ) > 0;

        if (!$isRecipient) {
            Response::forbidden('You are not a recipient of this poll');
        }

        return $alert;
    }

    /**
     * @return list<string>
     */
    public static function parsePollOptions(array $alert): array
    {
        $raw = $alert['poll_options'] ?? null;
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        return [];
    }

    /**
     * @param list<string> $options
     */
    private static function matchOption(string $value, array $options): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach ($options as $opt) {
            if (strcasecmp($opt, $value) === 0) {
                return $opt;
            }
        }

        return null;
    }
}
