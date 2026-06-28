<?php
/**
 * NexAlert - Auth Controller
 * Handles: login, logout, token refresh, password reset request, password reset confirm
 */

declare(strict_types=1);

namespace NexAlert\Controllers;

use NexAlert\Api\Request;
use NexAlert\Api\Response;
use NexAlert\Config\Database;
use NexAlert\Config\Env;
use NexAlert\Config\Logger;
use NexAlert\Services\AuditService;
use NexAlert\Services\JwtService;
use NexAlert\Services\MailService;

class AuthController
{
    /**
     * POST /api/v1/auth/login
     * Body: { "username": "...", "password": "..." }
     */
    public static function login(Request $request): never
    {
        $missing = $request->validate(['username', 'password']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password');

        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, display_name, home_org_id, local_password_hash,
                    auth_provider_id, is_active, is_locked, last_login_at
             FROM users
             WHERE username = ? AND is_active = 1',
            [$username]
        );

        if (!$user || !$user['local_password_hash']) {
            // Consistent timing to prevent user enumeration
            password_verify('dummy', '$2y$12$dummyhashtopreventtiming');
            Logger::warning('Login failed: user not found or no local auth', ['username' => $username, 'ip' => $request->ip()]);
            Response::unauthorized('Invalid username or password');
        }

        if ($user['is_locked']) {
            Response::unauthorized('Account is locked. Contact your administrator.');
        }

        if (!password_verify($password, $user['local_password_hash'])) {
            Logger::warning('Login failed: wrong password', ['user_id' => $user['id'], 'ip' => $request->ip()]);
            AuditService::log('auth.login_failed', 'user', (string) $user['id'], [
                'ip'     => $request->ip(),
                'reason' => 'invalid_password',
            ]);
            Response::unauthorized('Invalid username or password');
        }

        // Rehash if bcrypt cost has changed
        $cost = Env::int('BCRYPT_COST', 12);
        if (password_needs_rehash($user['local_password_hash'], PASSWORD_BCRYPT, ['cost' => $cost])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
            $db->execute('UPDATE users SET local_password_hash = ? WHERE id = ?', [$newHash, $user['id']]);
        }

        // Fetch roles
        $roles = $db->fetchAll(
            'SELECT r.name FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [$user['id']]
        );
        $roleNames = array_column($roles, 'name');

        $jwt          = new JwtService();
        $accessToken  = $jwt->issueAccessToken($user, $roleNames);
        $refreshToken = $jwt->issueRefreshToken($user['id']);

        // Store session record
        $sessionId = bin2hex(random_bytes(32));
        $db->execute(
            'INSERT INTO user_sessions (id, user_id, auth_method, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [
                $sessionId,
                $user['id'],
                'local',
                $request->ip(),
                $request->header('user-agent', ''),
                Env::int('JWT_REFRESH_TTL_DAYS', 30),
            ]
        );

        // Update last login
        $db->execute(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [$request->ip(), $user['id']]
        );

        AuditService::log('auth.login', 'user', (string) $user['id'], ['ip' => $request->ip()]);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => Env::int('JWT_TTL_MINUTES', 480) * 60,
            'user'          => [
                'id'           => $user['id'],
                'username'     => $user['username'],
                'display_name' => $user['display_name'],
                'home_org_id'  => $user['home_org_id'],
                'roles'        => $roleNames,
            ],
        ], 'Login successful');
    }

    /**
     * POST /api/v1/auth/refresh
     * Body: { "refresh_token": "..." }
     */
    public static function refresh(Request $request): never
    {
        $rawToken = $request->input('refresh_token');
        if (!$rawToken) {
            Response::validationError(['refresh_token' => 'Required']);
        }

        try {
            $jwt     = new JwtService();
            $payload = $jwt->decode((string) $rawToken, 'refresh');
        } catch (\RuntimeException $e) {
            Response::unauthorized('Invalid or expired refresh token');
        }

        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, display_name, home_org_id, is_active, is_locked FROM users WHERE id = ?',
            [$payload['uid']]
        );

        if (!$user || !$user['is_active'] || $user['is_locked']) {
            Response::unauthorized('Account is inactive or locked');
        }

        $roles = $db->fetchAll(
            'SELECT r.name FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > NOW())',
            [$user['id']]
        );

        $accessToken = $jwt->issueAccessToken($user, array_column($roles, 'name'));

        Response::success([
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => Env::int('JWT_TTL_MINUTES', 480) * 60,
        ], 'Token refreshed');
    }

    /**
     * POST /api/v1/auth/logout
     * Requires: valid access token
     */
    public static function logout(Request $request): never
    {
        // Revoke the current session by marking it revoked
        // (Session ID would come from a cookie in a stateful setup;
        //  for stateless JWT we log the logout event for audit purposes)
        AuditService::log('auth.logout', 'user', (string) $request->user['uid'], [
            'ip' => $request->ip(),
        ]);

        Response::success(null, 'Logged out successfully');
    }

    /**
     * POST /api/v1/auth/forgot-password
     * Body: { "username": "..." }
     * Always returns 200 to prevent user enumeration.
     */
    public static function forgotPassword(Request $request): never
    {
        $username = trim((string) $request->input('username', ''));

        if ($username === '') {
            Response::success(null, 'If that account exists, a reset email has been sent.');
        }

        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT u.id, uc.contact_value AS email
             FROM users u
             JOIN user_contacts uc ON uc.user_id = u.id AND uc.channel = ? AND uc.is_primary = 1 AND uc.is_verified = 1
             WHERE u.username = ? AND u.is_active = 1',
            ['email', $username]
        );

        if ($user) {
            // Invalidate any existing reset tokens
            $db->execute(
                "UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND token_type = 'password_reset' AND used_at IS NULL",
                [$user['id']]
            );

            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $db->execute(
                "INSERT INTO user_tokens (user_id, token_type, token_hash, expires_at)
                 VALUES (?, 'password_reset', ?, DATE_ADD(NOW(), INTERVAL 2 HOUR))",
                [$user['id'], $tokenHash]
            );

            $resetUrl = Env::get('APP_URL') . '/reset-password?token=' . $rawToken;

            MailService::sendPasswordReset($user['email'], $resetUrl);

            AuditService::log('auth.password_reset_requested', 'user', (string) $user['id'], [
                'ip' => $request->ip(),
            ]);

            Logger::info('Password reset email sent', ['user_id' => $user['id']]);
        }

        // Always return the same response
        Response::success(null, 'If that account exists, a reset email has been sent.');
    }

    /**
     * POST /api/v1/auth/reset-password
     * Body: { "token": "...", "password": "...", "password_confirm": "..." }
     */
    public static function resetPassword(Request $request): never
    {
        $missing = $request->validate(['token', 'password', 'password_confirm']);
        if ($missing) {
            Response::validationError(array_fill_keys($missing, 'Required'));
        }

        $rawToken = (string) $request->input('token');
        $password = (string) $request->input('password');
        $confirm  = (string) $request->input('password_confirm');

        if ($password !== $confirm) {
            Response::validationError(['password_confirm' => 'Passwords do not match']);
        }

        if (strlen($password) < 12) {
            Response::validationError(['password' => 'Password must be at least 12 characters']);
        }

        $tokenHash = hash('sha256', $rawToken);
        $db        = Database::getInstance();

        $tokenRow = $db->fetchOne(
            "SELECT id, user_id FROM user_tokens
             WHERE token_hash = ? AND token_type = 'password_reset'
               AND used_at IS NULL AND expires_at > NOW()",
            [$tokenHash]
        );

        if (!$tokenRow) {
            Response::error('Invalid or expired reset token', 400);
        }

        $cost    = Env::int('BCRYPT_COST', 12);
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);

        $db->transaction(function (Database $db) use ($tokenRow, $newHash): void {
            $db->execute(
                'UPDATE users SET local_password_hash = ? WHERE id = ?',
                [$newHash, $tokenRow['user_id']]
            );
            $db->execute(
                'UPDATE user_tokens SET used_at = NOW() WHERE id = ?',
                [$tokenRow['id']]
            );
        });

        AuditService::log('auth.password_reset', 'user', (string) $tokenRow['user_id'], [
            'ip' => $request->ip(),
        ]);

        Response::success(null, 'Password reset successfully. Please log in.');
    }
}
