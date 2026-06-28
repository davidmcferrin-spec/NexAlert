<?php
/**
 * NexAlert - JWT Service
 * HS256 JWT implementation without external dependencies.
 * Issues access tokens (short TTL) and refresh tokens (long TTL).
 */

declare(strict_types=1);

namespace NexAlert\Services;

use NexAlert\Config\Env;

class JwtService
{
    private string $secret;
    private string $algo;
    private int $ttlMinutes;
    private int $refreshTtlDays;

    public function __construct()
    {
        $this->secret         = Env::require('APP_SECRET');
        $this->algo           = Env::get('JWT_ALGO', 'HS256');
        $this->ttlMinutes     = Env::int('JWT_TTL_MINUTES', 480);
        $this->refreshTtlDays = Env::int('JWT_REFRESH_TTL_DAYS', 30);
    }

    /**
     * Issue an access token for a user.
     *
     * @param array $user Row from users table (id, username, display_name, home_org_id)
     * @param array $roles Array of role names the user holds
     */
    public function issueAccessToken(array $user, array $roles = []): string
    {
        $now = time();

        $payload = [
            'iss'   => Env::get('APP_URL'),
            'sub'   => (string) $user['id'],
            'iat'   => $now,
            'exp'   => $now + ($this->ttlMinutes * 60),
            'type'  => 'access',
            // Claims
            'uid'   => $user['id'],
            'uname' => $user['username'],
            'name'  => $user['display_name'],
            'org'   => $user['home_org_id'],
            'roles' => $roles,
        ];

        return $this->encode($payload);
    }

    /**
     * Issue a refresh token. Stores minimal claims — no roles (resolved on refresh).
     */
    public function issueRefreshToken(int $userId): string
    {
        $now = time();

        $payload = [
            'iss'  => Env::get('APP_URL'),
            'sub'  => (string) $userId,
            'iat'  => $now,
            'exp'  => $now + ($this->refreshTtlDays * 86400),
            'type' => 'refresh',
            'uid'  => $userId,
            'jti'  => bin2hex(random_bytes(16)), // Unique token ID for revocation
        ];

        return $this->encode($payload);
    }

    /**
     * Validate and decode a token. Returns the payload array.
     *
     * @throws \RuntimeException on invalid or expired token
     */
    public function decode(string $token, string $expectedType = 'access'): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid token format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Verify signature
        $expected = $this->sign("{$headerB64}.{$payloadB64}");
        if (!hash_equals($expected, $signatureB64)) {
            throw new \RuntimeException('Invalid token signature');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid token payload');
        }

        // Check expiry
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \RuntimeException('Token expired');
        }

        // Check type
        if (isset($payload['type']) && $payload['type'] !== $expectedType) {
            throw new \RuntimeException("Expected token type '{$expectedType}', got '{$payload['type']}'");
        }

        return $payload;
    }

    /**
     * Encode a payload into a signed JWT string.
     */
    private function encode(array $payload): string
    {
        $header = self::base64UrlEncode(json_encode([
            'alg' => $this->algo,
            'typ' => 'JWT',
        ]));

        $payload = self::base64UrlEncode(json_encode($payload));
        $sig     = $this->sign("{$header}.{$payload}");

        return "{$header}.{$payload}.{$sig}";
    }

    private function sign(string $data): string
    {
        return self::base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, true)
        );
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
