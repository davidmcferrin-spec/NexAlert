<?php
/**
 * NexAlert - Admin/user password helpers (reset link, set password).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Database;
use NexAlert\Config\Env;
use NexAlert\Config\Logger;

class UserPasswordService
{
    /**
     * Invalidate prior tokens, create a reset token, and email the link.
     *
     * @return string The email address the link was sent to
     */
    public static function sendResetLink(Database $db, int $userId, bool $allowUnverifiedEmail = false): string
    {
        $whereVerified = $allowUnverifiedEmail ? '' : ' AND uc.is_verified = 1';

        $row = $db->fetchOne(
            "SELECT uc.contact_value AS email
             FROM user_contacts uc
             WHERE uc.user_id = ? AND uc.channel = 'email' AND uc.is_primary = 1
               AND uc.is_active = 1{$whereVerified}
             LIMIT 1",
            [$userId]
        );

        if (!$row || empty($row['email'])) {
            throw new \RuntimeException('No primary email on file for this user');
        }

        $email = (string) $row['email'];

        $db->execute(
            "UPDATE user_tokens SET used_at = NOW()
             WHERE user_id = ? AND token_type = 'password_reset' AND used_at IS NULL",
            [$userId]
        );

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $db->execute(
            "INSERT INTO user_tokens (user_id, token_type, token_hash, expires_at)
             VALUES (?, 'password_reset', ?, DATE_ADD(NOW(), INTERVAL 2 HOUR))",
            [$userId, $tokenHash]
        );

        $resetUrl = rtrim(Env::get('APP_URL'), '/') . '/reset-password?token=' . urlencode($rawToken);
        MailService::sendPasswordReset($email, $resetUrl);

        Logger::info('Password reset email sent', ['user_id' => $userId, 'admin_initiated' => $allowUnverifiedEmail]);

        return $email;
    }

    public static function setPassword(Database $db, int $userId, string $password, bool $unlock = true): void
    {
        $cost    = Env::int('BCRYPT_COST', 12);
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);

        if ($unlock) {
            $db->execute(
                'UPDATE users SET local_password_hash = ?, is_locked = 0 WHERE id = ?',
                [$newHash, $userId]
            );
        } else {
            $db->execute(
                'UPDATE users SET local_password_hash = ? WHERE id = ?',
                [$newHash, $userId]
            );
        }
    }
}
